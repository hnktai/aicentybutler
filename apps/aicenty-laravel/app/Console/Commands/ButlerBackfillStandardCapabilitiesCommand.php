<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ButlerAutomationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ButlerBackfillStandardCapabilitiesCommand extends Command
{
    protected $signature = 'butler:backfill-standard-capabilities
                            {--dry-run : Report eligible and non-compliant Butler counts without writing}';

    protected $description = 'Idempotently grant and pin the standard capability set for existing customer Butlers';

    public function handle(ButlerAutomationService $automation): int
    {
        $db = DB::connection('butler_pgsql');
        $masterTenantId = '0193a5b0-7000-7000-8000-000000000001';
        $required = [
            'document-to-pdf',
            'images-to-pdf',
            'quangia-image-generation',
            'quangia-mobile-messaging',
        ];

        $agents = $db->table('agents')
            ->select(['id', 'tenant_id'])
            ->where('agent_key', 'aicenty-master-butler')
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->where('tenant_id', '<>', $masterTenantId)
            ->orderBy('tenant_id')
            ->get();

        $nonCompliant = $agents->filter(function ($agent) use ($db, $required): bool {
            $raw = $db->table('agents')->where('id', $agent->id)->value('other_config');
            $config = is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw;
            $pinned = is_array($config['pinned_skills'] ?? null) ? $config['pinned_skills'] : [];

            return array_diff($required, array_map('strval', $pinned)) !== [];
        });

        $this->line(sprintf(
            'eligible=%d non_compliant=%d dry_run=%s',
            $agents->count(),
            $nonCompliant->count(),
            $this->option('dry-run') ? 'true' : 'false'
        ));

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $db->transaction(function () use ($automation, $nonCompliant): void {
            foreach ($nonCompliant as $agent) {
                $automation->ensureStandardButlerSkillsForAgent(
                    (string) $agent->tenant_id,
                    (string) $agent->id
                );
            }
        }, 3);

        $this->info(sprintf('updated=%d', $nonCompliant->count()));

        return self::SUCCESS;
    }
}
