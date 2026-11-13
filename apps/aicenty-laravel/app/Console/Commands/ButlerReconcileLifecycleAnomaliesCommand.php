<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ButlerReconcileLifecycleAnomaliesCommand extends Command
{
    protected $signature = 'butler:reconcile-lifecycle-anomalies
                            {--dry-run : Report repairable and unresolved lifecycle anomalies without writing}';

    protected $description = 'Safely repair uniquely bound handovers and durably report unresolved onboarding or subscription anomalies';

    public function handle(): int
    {
        $db = DB::connection('butler_pgsql');
        $masterTenantId = '0193a5b0-7000-7000-8000-000000000001';

        $repairableSql = <<<'SQL'
WITH pending AS (
    SELECT h.id,
           ARRAY_REMOVE(ARRAY[
               NULLIF(trim(h.claimed_zalo_personal_id), ''),
               NULLIF(trim(h.zalo_oa_user_id), ''),
               NULLIF(regexp_replace(COALESCE(h.phone, ''), '[^0-9]', '', 'g'), ''),
               CASE
                   WHEN regexp_replace(COALESCE(h.phone, ''), '[^0-9]', '', 'g') <> ''
                   THEN 'phone_' || regexp_replace(h.phone, '[^0-9]', '', 'g')
               END
           ], NULL) AS candidate_ids
    FROM quangia_pending_handover h
    WHERE h.claimed_at IS NOT NULL
      AND (h.dedicated_tenant_id IS NULL OR h.dedicated_agent_id IS NULL)
),
matches AS (
    SELECT DISTINCT p.id AS handover_id, b.tenant_id, b.agent_id
    FROM pending p
    CROSS JOIN LATERAL unnest(p.candidate_ids) AS candidate(external_user_id)
    JOIN butler_identity_bindings b
      ON b.external_user_id = candidate.external_user_id
     AND b.status = 'active'
    JOIN tenants t
      ON t.id = b.tenant_id
     AND t.status = 'active'
    JOIN agents a
      ON a.id = b.agent_id
     AND a.tenant_id = b.tenant_id
     AND a.agent_key = 'aicenty-master-butler'
     AND a.status = 'active'
     AND a.deleted_at IS NULL
),
unique_matches AS (
    SELECT handover_id, MIN(tenant_id::text)::uuid AS tenant_id, MIN(agent_id::text)::uuid AS agent_id
    FROM matches
    GROUP BY handover_id
    HAVING COUNT(*) = 1
)
SELECT handover_id, tenant_id, agent_id FROM unique_matches
SQL;

        $repairable = collect($db->select($repairableSql));
        $unresolvedBefore = $db->table('quangia_pending_handover')
            ->whereNotNull('claimed_at')
            ->where(function ($query): void {
                $query->whereNull('dedicated_tenant_id')->orWhereNull('dedicated_agent_id');
            })
            ->count();
        $expiredActive = $db->table('tenants')
            ->where('status', 'active')
            ->whereRaw("settings->>'is_vip' = 'true'")
            ->whereRaw("NULLIF(settings->>'expires_at', '') IS NOT NULL")
            ->whereRaw("(settings->>'expires_at')::timestamptz < now()")
            ->count();

        $this->line(sprintf(
            'repairable_handovers=%d unresolved_handovers=%d expired_active_vip=%d dry_run=%s',
            $repairable->count(),
            $unresolvedBefore,
            $expiredActive,
            $this->option('dry-run') ? 'true' : 'false'
        ));

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $db->transaction(function () use ($db, $repairable, $masterTenantId): void {
            foreach ($repairable as $match) {
                $db->table('quangia_pending_handover')
                    ->where('id', $match->handover_id)
                    ->where(function ($query): void {
                        $query->whereNull('dedicated_tenant_id')->orWhereNull('dedicated_agent_id');
                    })
                    ->update([
                        'dedicated_tenant_id' => $match->tenant_id,
                        'dedicated_agent_id' => $match->agent_id,
                    ]);
            }

            $unresolved = $db->table('quangia_pending_handover')
                ->select(['id', 'created_at', 'claimed_at', 'phone', 'email', 'name', 'family_name', 'butler_name'])
                ->whereNotNull('claimed_at')
                ->where(function ($query): void {
                    $query->whereNull('dedicated_tenant_id')->orWhereNull('dedicated_agent_id');
                })
                ->get();

            foreach ($unresolved as $handover) {
                $exists = $db->table('activity_logs')
                    ->where('action', 'butler.lifecycle.handover_unresolved')
                    ->where('entity_type', 'quangia_pending_handover')
                    ->where('entity_id', (string) $handover->id)
                    ->exists();
                if ($exists) {
                    continue;
                }
                $db->table('activity_logs')->insert([
                    'id' => (string) Str::uuid(),
                    'actor_type' => 'system',
                    'actor_id' => 'butler-lifecycle-reconciler',
                    'action' => 'butler.lifecycle.handover_unresolved',
                    'entity_type' => 'quangia_pending_handover',
                    'entity_id' => (string) $handover->id,
                    'details' => json_encode([
                        'created_at' => $handover->created_at,
                        'claimed_at' => $handover->claimed_at,
                        'has_phone' => trim((string) $handover->phone) !== '',
                        'has_email' => trim((string) $handover->email) !== '',
                        'has_name' => trim((string) $handover->name) !== '',
                        'has_family_name' => trim((string) $handover->family_name) !== '',
                        'has_butler_name' => trim((string) $handover->butler_name) !== '',
                    ], JSON_UNESCAPED_SLASHES),
                    'created_at' => now(),
                    'tenant_id' => $masterTenantId,
                ]);
            }

            $expiredTenants = $db->table('tenants')
                ->select(['id', 'settings'])
                ->where('status', 'active')
                ->whereRaw("settings->>'is_vip' = 'true'")
                ->whereRaw("NULLIF(settings->>'expires_at', '') IS NOT NULL")
                ->whereRaw("(settings->>'expires_at')::timestamptz < now()")
                ->get();

            foreach ($expiredTenants as $tenant) {
                $exists = $db->table('activity_logs')
                    ->where('action', 'butler.lifecycle.subscription_expired_active')
                    ->where('entity_type', 'tenant')
                    ->where('entity_id', (string) $tenant->id)
                    ->exists();
                if ($exists) {
                    continue;
                }
                $settings = is_string($tenant->settings)
                    ? (json_decode($tenant->settings, true) ?: [])
                    : (array) $tenant->settings;
                $db->table('activity_logs')->insert([
                    'id' => (string) Str::uuid(),
                    'actor_type' => 'system',
                    'actor_id' => 'butler-lifecycle-reconciler',
                    'action' => 'butler.lifecycle.subscription_expired_active',
                    'entity_type' => 'tenant',
                    'entity_id' => (string) $tenant->id,
                    'details' => json_encode([
                        'expires_at' => $settings['expires_at'] ?? null,
                        'plan_name' => $settings['plan_name'] ?? null,
                        'requires_business_review' => true,
                    ], JSON_UNESCAPED_SLASHES),
                    'created_at' => now(),
                    'tenant_id' => $tenant->id,
                ]);
            }
        }, 3);

        $unresolvedAfter = $db->table('quangia_pending_handover')
            ->whereNotNull('claimed_at')
            ->where(function ($query): void {
                $query->whereNull('dedicated_tenant_id')->orWhereNull('dedicated_agent_id');
            })
            ->count();
        $this->info(sprintf(
            'repaired=%d unresolved_reported=%d expired_reported=%d',
            $repairable->count(),
            $unresolvedAfter,
            $expiredActive
        ));

        return self::SUCCESS;
    }
}
