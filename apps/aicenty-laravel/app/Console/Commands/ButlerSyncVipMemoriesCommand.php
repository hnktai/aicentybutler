<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ButlerSyncVipMemoriesCommand extends Command
{
    protected $signature = 'butler:sync-vip-memories';
    protected $description = 'Legacy cross-tenant memory sync (disabled by tenant-isolation safety gate)';

    public function handle(): int
    {
        if ((string) env('BUTLER_LEGACY_VIP_SYNC_ENABLED', '0') !== '1') {
            Log::critical('[Butler Sync VIP] Legacy cross-tenant memory replication is disabled by the tenant-isolation safety gate.');
            $this->warn('Legacy cross-tenant memory replication is disabled.');
            return 0;
        }

        $this->info("Starting Nightly Incremental Memory Sync for VIP Butler Families...");
        Log::info("[Butler Sync VIP] Bat dau tien trinh dong bo gia tang hang dem...");

        $masterTenantId = '0193a5b0-7000-7000-8000-000000000001';

        try {
            $db = DB::connection('butler_pgsql');

            // 1. Quét danh sách tất cả các Tenant gia đình VIP đang hoạt động
            $vipTenants = $db->table('tenants')
                ->where('id', '!=', $masterTenantId)
                ->where('status', 'active')
                ->get();

            $totalSyncedFamilies = 0;
            $totalUpdatedSessions = 0;
            $totalUpdatedDocs = 0;
            $totalUpdatedContexts = 0;

            foreach ($vipTenants as $tenant) {
                $settings = is_string($tenant->settings) ? json_decode($tenant->settings, true) : (array) $tenant->settings;
                $familyPhone = trim((string) ($settings['family_phone'] ?? ''));
                $cleanPhone = preg_replace('/[^0-9]/', '', $familyPhone);

                if (empty($cleanPhone)) {
                    continue;
                }

                // 2. Tìm Agent Quản Gia Điều Phối trong Tenant riêng này
                $tenantButler = $db->table('agents')
                    ->where('tenant_id', $tenant->id)
                    ->where('agent_key', 'aicenty-master-butler')
                    ->first();

                if (!$tenantButler) {
                    continue;
                }

                $newMasterButlerId = $tenantButler->id;

                // 3. Tìm tất cả user_id liên quan của gia đình này
                $targetUserIds = [
                    'phone_' . $cleanPhone,
                ];

                $profiles = $db->table('user_agent_profiles')
                    ->where('tenant_id', $masterTenantId)
                    ->get();

                foreach ($profiles as $p) {
                    $pMeta = is_string($p->metadata) ? json_decode($p->metadata, true) : (array) $p->metadata;
                    $pPhone = preg_replace('/[^0-9]/', '', (string) ($pMeta['phone'] ?? ''));
                    if (!empty($pPhone) && ($pPhone === $cleanPhone || str_ends_with($pPhone, substr($cleanPhone, -9)))) {
                        $targetUserIds[] = (string) $p->user_id;
                        if (!empty($pMeta['zalo_user_id'])) {
                            $targetUserIds[] = (string) $pMeta['zalo_user_id'];
                        }
                        if (!empty($pMeta['zalo_quangia_id'])) {
                            $targetUserIds[] = (string) $pMeta['zalo_quangia_id'];
                        }
                    }
                }
                $targetUserIds = array_values(array_unique(array_filter($targetUserIds)));

                // 4. Quét toàn bộ Sessions trên Master của gia đình này
                $masterSessions = $db->table('sessions')
                    ->where('tenant_id', $masterTenantId)
                    ->whereIn('user_id', $targetUserIds)
                    ->get();

                $familySessionUpdated = 0;

                foreach ($masterSessions as $mSess) {
                    $tenantSess = $db->table('sessions')
                        ->where('tenant_id', $tenant->id)
                        ->where('session_key', $mSess->session_key)
                        ->first();

                    if (!$tenantSess) {
                        $newSessionId = (string) Str::uuid();
                        $sessArray = (array) $mSess;
                        $sessArray['id'] = $newSessionId;
                        $sessArray['tenant_id'] = $tenant->id;
                        $sessArray['agent_id'] = $newMasterButlerId;
                        $sessArray['created_at'] = $mSess->created_at;
                        $sessArray['updated_at'] = now();

                        $meta = is_string($mSess->metadata) ? json_decode($mSess->metadata, true) : (array) $mSess->metadata;
                        $meta['replicated_from_master'] = true;
                        $meta['nightly_sync_at'] = now()->toIso8601String();
                        $sessArray['metadata'] = json_encode($meta, JSON_UNESCAPED_UNICODE);

                        $db->table('sessions')->insert($sessArray);
                        $familySessionUpdated++;
                    } else {
                        $mMessages = is_string($mSess->messages) ? json_decode($mSess->messages, true) : (array) $mSess->messages;
                        $tMessages = is_string($tenantSess->messages) ? json_decode($tenantSess->messages, true) : (array) $tenantSess->messages;

                        $mCount = is_array($mMessages) ? count($mMessages) : 0;
                        $tCount = is_array($tMessages) ? count($tMessages) : 0;

                        if ($mCount > $tCount || $mSess->updated_at > $tenantSess->updated_at) {
                            $meta = is_string($tenantSess->metadata) ? json_decode($tenantSess->metadata, true) : (array) $tenantSess->metadata;
                            $meta['incremental_synced_at'] = now()->toIso8601String();
                            $meta['messages_count'] = $mCount;

                            $db->table('sessions')
                                ->where('id', $tenantSess->id)
                                ->update([
                                    'messages'         => is_string($mSess->messages) ? $mSess->messages : json_encode($mMessages, JSON_UNESCAPED_UNICODE),
                                    'summary'          => $mSess->summary,
                                    'input_tokens'     => $mSess->input_tokens,
                                    'output_tokens'    => $mSess->output_tokens,
                                    'metadata'         => json_encode($meta, JSON_UNESCAPED_UNICODE),
                                    'updated_at'       => now(),
                                ]);
                            $familySessionUpdated++;
                        }
                    }
                }

                // 5. Quét toàn bộ Memory Documents trên Master
                $masterDocs = $db->table('memory_documents')
                    ->where('tenant_id', $masterTenantId)
                    ->whereIn('user_id', $targetUserIds)
                    ->get();

                $familyDocsUpdated = 0;

                foreach ($masterDocs as $mDoc) {
                    $tenantDoc = $db->table('memory_documents')
                        ->where('tenant_id', $tenant->id)
                        ->where('path', $mDoc->path)
                        ->where('user_id', $mDoc->user_id)
                        ->first();

                    if (!$tenantDoc) {
                        $newDocId = (string) Str::uuid();
                        $docArray = (array) $mDoc;
                        $docArray['id'] = $newDocId;
                        $docArray['tenant_id'] = $tenant->id;
                        $docArray['agent_id'] = $newMasterButlerId;
                        $docArray['created_at'] = $mDoc->created_at;
                        $docArray['updated_at'] = now();

                        $db->table('memory_documents')->insert($docArray);
                        $familyDocsUpdated++;
                    } elseif ($mDoc->hash !== $tenantDoc->hash || $mDoc->updated_at > $tenantDoc->updated_at) {
                        $db->table('memory_documents')
                            ->where('id', $tenantDoc->id)
                            ->update([
                                'content'    => $mDoc->content,
                                'hash'       => $mDoc->hash,
                                'updated_at' => now(),
                            ]);
                        $familyDocsUpdated++;
                    }
                }

                // 6. Quét toàn bộ User Context Files (USER.md) trên Master sang Tenant riêng
                $masterContexts = $db->table('user_context_files')
                    ->where('tenant_id', $masterTenantId)
                    ->whereIn('user_id', $targetUserIds)
                    ->get();

                $familyContextsUpdated = 0;

                foreach ($masterContexts as $mCtx) {
                    $tenantCtx = $db->table('user_context_files')
                        ->where('tenant_id', $tenant->id)
                        ->where('file_name', $mCtx->file_name)
                        ->where('user_id', $mCtx->user_id)
                        ->first();

                    if (!$tenantCtx) {
                        $newCtxId = (string) Str::uuid();
                        $ctxArray = (array) $mCtx;
                        $ctxArray['id'] = $newCtxId;
                        $ctxArray['tenant_id'] = $tenant->id;
                        $ctxArray['agent_id'] = $newMasterButlerId;
                        $ctxArray['created_at'] = $mCtx->created_at;
                        $ctxArray['updated_at'] = now();

                        $db->table('user_context_files')->insert($ctxArray);
                        $familyContextsUpdated++;
                    } elseif ($mCtx->content !== $tenantCtx->content || $mCtx->updated_at > $tenantCtx->updated_at) {
                        $db->table('user_context_files')
                            ->where('id', $tenantCtx->id)
                            ->update([
                                'content'    => $mCtx->content,
                                'updated_at' => now(),
                            ]);
                        $familyContextsUpdated++;
                    }
                }

                // 7. Đồng bộ Profile sang Tenant riêng
                foreach ($targetUserIds as $tuid) {
                    $mProf = $db->table('user_agent_profiles')
                        ->where('tenant_id', $masterTenantId)
                        ->where('user_id', $tuid)
                        ->orderByDesc('last_seen_at')
                        ->first();
                    if ($mProf) {
                        $db->table('user_agent_profiles')->updateOrInsert(
                            [
                                'tenant_id' => $tenant->id,
                                'agent_id'  => $newMasterButlerId,
                                'user_id'   => $tuid,
                            ],
                            [
                                'metadata'     => $mProf->metadata,
                                'last_seen_at' => now(),
                            ]
                        );
                    }
                }

                if ($familySessionUpdated > 0 || $familyDocsUpdated > 0 || $familyContextsUpdated > 0) {
                    $totalSyncedFamilies++;
                    $totalUpdatedSessions += $familySessionUpdated;
                    $totalUpdatedDocs += $familyDocsUpdated;
                    $totalUpdatedContexts += $familyContextsUpdated;
                    $this->info("Synced {$tenant->name} ({$cleanPhone}): {$familySessionUpdated} sessions, {$familyDocsUpdated} docs, {$familyContextsUpdated} contexts.");
                }
            }

            $summary = "Nightly Memory Sync Completed. Total Families Synced: {$totalSyncedFamilies}, Sessions: {$totalUpdatedSessions}, Docs: {$totalUpdatedDocs}, Contexts: {$totalUpdatedContexts}.";
            $this->info($summary);
            Log::info("[Butler Sync VIP] " . $summary);

            return 0;
        } catch (\Throwable $e) {
            $this->error("Nightly Memory Sync Error: " . $e->getMessage());
            Log::error("[Butler Sync VIP] Loi dong bo gia tang: " . $e->getMessage());
            return 1;
        }
    }
}
