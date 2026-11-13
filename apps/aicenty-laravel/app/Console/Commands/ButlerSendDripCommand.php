<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ButlerMorningBriefingService;
use App\Services\ZaloOAService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ButlerSendDripCommand extends Command
{
    protected $signature = 'butler:send-drip-nurturing 
                            {--type=auto : Loai hanh dong: auto, morning, trial, study_alert, report_offer}
                            {--phone= : Chi dinh so dien thoai cu the de test} 
                            {--day= : Chi dinh ngay thu may (1, 3, 5, 7) de gui} 
                            {--dry-run : Chi hien thi noi dung, khong ban cron_jobs vao database} 
                            {--force : Bat buoc gui ke ca da gui trong ngay}';

    protected $description = 'He thong Tach Kenh: Zalo Quan Gia (Chao Sang 07h / Toi 21h30) & Zalo OA (Cham soc quy trinh tam 14h chieu)';

    public function handle(
        ZaloOAService $zaloOaService,
        ButlerMorningBriefingService $morningBriefingService
    ): int
    {
        $targetPhone = $this->option('phone');
        $forcedDay   = $this->option('day') ? (int) $this->option('day') : null;
        $isDryRun    = (bool) $this->option('dry-run');
        $isForce     = (bool) $this->option('force');
        $typeOption  = (string) ($this->option('type') ?: 'auto');
        $phoneAliasMap = json_decode(
            (string) env('BUTLER_PHONE_ALIAS_MAP_JSON', '{}'),
            true
        ) ?: [];
        $memberNameOverrides = json_decode(
            (string) env('BUTLER_MEMBER_NAME_OVERRIDES_JSON', '{}'),
            true
        ) ?: [];

        $tz = new \DateTimeZone('Asia/Ho_Chi_Minh');
        $nowVn = Carbon::now($tz);
        $today = $nowVn->format('Ymd');
        $hourVn = (int) $nowVn->format('G'); // 0 - 23

        // Xác định loại hành động nếu là 'auto' dựa trên khung giờ Việt Nam (né trùng kênh)
        $activeType = $typeOption;
        if ($typeOption === 'auto') {
            if ($hourVn >= 6 && $hourVn <= 8) {
                $activeType = 'morning';
            } elseif ($hourVn >= 13 && $hourVn <= 15) {
                $activeType = 'trial';
            } elseif ($hourVn >= 21 && $hourVn <= 22) {
                $activeType = 'evening';
            } else {
                $this->info("Khung giờ hiện tại ({$hourVn}h VN) không thuộc cửa sổ tự động (06-08h Quản Gia, 13-15h Zalo OA, 21-22h Quản Gia). Dừng tác vụ.");
                return 0;
            }
        }

        $this->info("=== TIEN TRINH QUAN GIA EXECUTIVE BUTLER AICENTY ===");
        $this->info("Thoi gian VN: " . $nowVn->toDateTimeString() . " | Che do: [{$activeType}]");
        if ($targetPhone) {
            $this->info("Target SĐT: {$targetPhone}" . ($forcedDay ? " (Ngay {$forcedDay})" : ""));
        }

        // =========================================================================
        // 1. KỊCH BẢN ZALO OA CHĂM SÓC QUY TRÌNH (14H CHIỀU - SIÊU NGẮN GỌN)
        // (Chỉ gửi qua Zalo OA vào Ngày 1, 3, 5, 7 - KHÔNG chào sáng/tối)
        // =========================================================================
        $trialTemplates = [
            1 => "Chào {DANH_XUNG}, ngày 1 trải nghiệm Quản Gia AI 🎩. Thử nhắn Quản Gia: \"Nhắc con học 19h tối nay\" hoặc gửi 1 bài tập khó nhé ạ!",
            3 => "Chào {DANH_XUNG}, ngày 3 đồng hành cùng {TO_AM} ⏰. Đừng quên dùng Quản Gia nhắc lịch Zalo 24/7 và kèm học Socratic nhé ạ!",
            5 => "Chào {DANH_XUNG}, ngày 5 đồng hành 📊. Để xem tóm tắt học tập của con, nhắn Quản Gia: \"Xuất báo cáo tuần\" nhé ạ!",
            7 => "Chào {DANH_XUNG}, ngày 7 khép lại tuần dùng thử 🎩. Nhắn Quản Gia \"Báo cáo 7 ngày\" hoặc kích hoạt SIM riêng tại: {LINK_THANH_TOAN}",
        ];

        try {
            $db = DB::connection('butler_pgsql');

            // 2. TỔNG HỢP TOÀN BỘ KHÁCH HÀNG TỪ CÁC NGUỒN CHÍNH THỨC
            $handovers = $db->table('quangia_pending_handover')->orderByDesc('created_at')->get();
            $profiles   = $db->table('user_agent_profiles')->get();
            $vipTenantRows = $db->table('tenants')->whereRaw("settings->>'is_vip' = 'true'")->get()->keyBy('id');
            $channelInstances = $db->table('channel_instances')->where('enabled', true)->get()->keyBy('tenant_id');
            $familyGroups = $db->table('quangia_family_groups')->get()->keyBy('group_id');
            $channelContacts = $db->table('channel_contacts')->whereNotNull('display_name')->get()->keyBy('sender_id');

            // Xây dựng bảng ánh xạ Zalo ID -> SĐT chuẩn
            $phoneByZaloId = [];
            foreach ($handovers as $h) {
                $p = preg_replace('/[^0-9]/', '', (string)$h->phone);
                if (strlen($p) >= 9 && strlen($p) <= 11) {
                    if ($h->claimed_zalo_personal_id) $phoneByZaloId[(string)$h->claimed_zalo_personal_id] = $p;
                    if ($h->zalo_oa_user_id) $phoneByZaloId[(string)$h->zalo_oa_user_id] = $p;
                }
            }

            foreach ($profiles as $prof) {
                $meta = is_string($prof->metadata) ? json_decode($prof->metadata, true) : (array)($prof->metadata ?? []);
                $p = preg_replace('/[^0-9]/', '', (string)($meta['phone'] ?? ''));
                if (str_starts_with($p, '84') && strlen($p) === 11) {
                    $p = '0' . substr($p, 2);
                }
                if (!empty($p) && strlen($p) >= 9 && strlen($p) <= 11) {
                    $uStr = (string)$prof->user_id;
                    $phoneByZaloId[$uStr] = $p;
                    if (!empty($meta['zalo_quangia_id'])) $phoneByZaloId[(string)$meta['zalo_quangia_id']] = $p;
                    if (!empty($meta['zalo_user_id'])) $phoneByZaloId[(string)$meta['zalo_user_id']] = $p;
                }
            }

            $handoversById = $handovers->keyBy('id');
            $handoversByPhone = [];

            $giaChuPhoneSet = [];
            foreach ($handovers as $h) {
                $cleanP = preg_replace('/[^0-9]/', '', (string)$h->phone);
                if (str_starts_with($cleanP, '84') && strlen($cleanP) === 11) $cleanP = '0' . substr($cleanP, 2);
                if (!empty($cleanP)) {
                    $giaChuPhoneSet[$cleanP] = true;
                    $handoversByPhone[$cleanP] = $h;
                }
                if ($h->claimed_zalo_personal_id) $giaChuPhoneSet[(string)$h->claimed_zalo_personal_id] = true;
                if ($h->zalo_oa_user_id) $giaChuPhoneSet[(string)$h->zalo_oa_user_id] = true;
            }

            $customers = [];

            // NẠP TẤT CẢ KHÁCH HÀNG TỪ quangia_pending_handover
            foreach ($handovers as $h) {
                $pClean = preg_replace('/[^0-9]/', '', (string) $h->phone);
                if (str_starts_with($pClean, '84') && strlen($pClean) === 11) {
                    $pClean = '0' . substr($pClean, 2);
                }
                if (empty($pClean) || strlen($pClean) < 9 || strlen($pClean) > 11) {
                    continue;
                }

                $name = trim($h->name ?? 'Gia Chủ');
                $customers[$pClean] = [
                    'phone'                    => $pClean,
                    'name'                     => $name,
                    'honorific'                => trim($h->honorific ?? ('Gia chủ ' . $name)),
                    'family_name'              => trim($h->family_name ?? ('Gia Đình ' . $name)),
                    'butler_name'              => trim($h->butler_name ?? 'Quản Gia Aicenty'),
                    'child_info'               => trim((string)($h->child_info ?? '')),
                    'claimed_zalo_personal_id' => $h->claimed_zalo_personal_id ? (string)$h->claimed_zalo_personal_id : null,
                    'zalo_oa_user_id'          => $h->zalo_oa_user_id ? (string)$h->zalo_oa_user_id : null,
                    'dedicated_tenant_id'      => $h->dedicated_tenant_id ?? null,
                    'dedicated_agent_id'       => $h->dedicated_agent_id ?? null,
                    'created_at'               => $h->created_at ?? now()->toIso8601String(),
                    'is_vip'                   => false,
                    'vip_plan'                 => null,
                    'vip_until'                => null,
                ];
            }

            $familyMembers = [];

            // =========================================================================
            // NGUỒN 1: QUANGIA_FAMILY_MEMBERS (Độ chuẩn xác cao nhất từ Nhóm Zalo & Webhook)
            // =========================================================================
            try {
                $qfmRows = $db->table('quangia_family_members')->get();
                foreach ($qfmRows as $qfm) {
                    $uStr = (string)$qfm->zalo_uid;
                    if (empty($uStr) || str_starts_with($uStr, 'group:') || str_starts_with($uStr, 'phone_') || !ctype_digit($uStr) || strlen($uStr) < 10) {
                        continue;
                    }
                    if (isset($giaChuPhoneSet[$uStr])) continue;

                    $h = $handoversById[$qfm->handover_id] ?? null;
                    if (!$h && !empty($qfm->group_id) && isset($familyGroups[$qfm->group_id])) {
                        $grp = $familyGroups[$qfm->group_id];
                        $h = $handoversById[$grp->handover_id] ?? null;
                    }

                    $pClean = '';
                    if ($h) {
                        $pClean = preg_replace('/[^0-9]/', '', (string)$h->phone);
                        if (str_starts_with($pClean, '84') && strlen($pClean) === 11) $pClean = '0' . substr($pClean, 2);
                    }

                    $mName = trim($qfm->display_name ?? '');
                    if ((empty($mName) || $mName === 'con') && isset($channelContacts[$uStr])) {
                        $mName = trim($channelContacts[$uStr]->display_name);
                    }
                    $academic = mb_strtolower((string)($qfm->academic_info ?? ''));
                    $rawRole = mb_strtolower(trim($qfm->role ?? 'member'));

                    if (str_contains($academic, 'vợ') || str_contains($rawRole, 'wife') || str_contains(mb_strtolower($mName), 'vợ') || str_contains(mb_strtolower($mName), 'mẹ')) {
                        $cleanRole = 'wife';
                    } elseif (str_contains($academic, 'chồng') || str_contains($rawRole, 'husband') || str_contains(mb_strtolower($mName), 'ba') || str_contains(mb_strtolower($mName), 'bố')) {
                        $cleanRole = 'husband';
                    } elseif ($rawRole === 'child' || str_contains($academic, 'học sinh') || str_contains($academic, 'con') || str_contains(mb_strtolower($mName), 'bé')) {
                        $cleanRole = 'child';
                    } elseif ($rawRole === 'parent') {
                        $cleanRole = 'wife';
                    } else {
                        $cleanRole = 'member';
                    }

                    $bName = trim($h->butler_name ?? 'Quản Gia Aicenty');
                    if (preg_match('/(?:thành le|chưa chọn|chưa có|null)/iu', $bName) || mb_strlen($bName) < 4) {
                        $bName = 'Quản Gia Aicenty';
                    }

                    $familyMembers[$uStr] = [
                        'user_id'             => $uStr,
                        'name'                => $mName ?: 'con',
                        'role'                => $cleanRole,
                        'parent_phone'        => $pClean,
                        'family_name'         => trim($h->family_name ?? ''),
                        'butler_name'         => $bName,
                        'dedicated_tenant_id' => $h->dedicated_tenant_id ?? null,
                        'source'              => 'quangia_family_members',
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning("[Butler Executive] quangia_family_members scan note: " . $e->getMessage());
            }

            // =========================================================================
            // NGUỒN 2: USER_CONTEXT_FILES (USER.md - Hồ sơ ngữ cảnh thành viên tổ ấm)
            // =========================================================================
            try {
                $ucFiles = $db->table('user_context_files')
                    ->where('file_name', 'USER.md')
                    ->where('user_id', 'not like', 'group:%')
                    ->where('user_id', '!=', 'system')
                    ->get();

                foreach ($ucFiles as $uc) {
                    $uStr = (string)$uc->user_id;
                    if (empty($uStr) || !ctype_digit($uStr) || strlen($uStr) < 10) continue;
                    if (isset($giaChuPhoneSet[$uStr])) continue;

                    $cStr = (string)$uc->content;
                    if (!str_contains($cStr, 'Hồ Sơ Thành Viên Tổ Ấm')) continue;

                    preg_match('/- \*\*Họ tên thành viên:\*\*\s*(.+)/u', $cStr, $mMatch);
                    $name = trim($mMatch[1] ?? '');

                    preg_match('/- \*\*Gia chủ tối cao:\*\*\s*.*SĐT:\s*([0-9]+)/u', $cStr, $pMatch);
                    $pRaw = trim($pMatch[1] ?? '');
                    $pClean = preg_replace('/[^0-9]/', '', $pRaw);
                    if (str_starts_with($pClean, '84') && strlen($pClean) === 11) $pClean = '0' . substr($pClean, 2);

                    $pClean = (string) ($phoneAliasMap[$pClean] ?? $pClean);

                    preg_match('/- \*\*Vai trò:\*\*\s*(.+)/u', $cStr, $rMatch);
                    $roleRaw = mb_strtolower(trim($rMatch[1] ?? ''));

                    if (str_contains($roleRaw, 'vợ') || str_contains($roleRaw, 'mẹ') || str_contains($roleRaw, 'phụ huynh') || str_contains(mb_strtolower($name), 'vợ') || str_contains(mb_strtolower($name), 'mẹ')) {
                        $cleanRole = 'wife';
                    } elseif (str_contains($roleRaw, 'chồng') || str_contains($roleRaw, 'ba') || str_contains(mb_strtolower($name), 'chồng') || str_contains(mb_strtolower($name), 'ba')) {
                        $cleanRole = 'husband';
                    } else {
                        $cleanRole = 'child';
                    }

                    preg_match('/- \*\*Tên Quản Gia riêng:\*\*\s*(.+)/u', $cStr, $bMatch);
                    $bName = trim($bMatch[1] ?? '');

                    $h = $handoversByPhone[$pClean] ?? null;
                    if (empty($bName)) {
                        $bName = trim($h->butler_name ?? 'Quản Gia Aicenty');
                    }
                    if (preg_match('/(?:thành le|chưa chọn|chưa có|null)/iu', $bName) || mb_strlen($bName) < 4) {
                        $bName = 'Quản Gia Aicenty';
                    }

                    if (isset($familyMembers[$uStr])) {
                        if (empty($familyMembers[$uStr]['name']) || $familyMembers[$uStr]['name'] === 'con') {
                            if ($name) $familyMembers[$uStr]['name'] = $name;
                        }
                        if (empty($familyMembers[$uStr]['parent_phone']) && $pClean) {
                            $familyMembers[$uStr]['parent_phone'] = $pClean;
                        }
                        continue;
                    }

                    $familyMembers[$uStr] = [
                        'user_id'             => $uStr,
                        'name'                => $name ?: 'con',
                        'role'                => $cleanRole,
                        'parent_phone'        => $pClean,
                        'family_name'         => trim($h->family_name ?? ''),
                        'butler_name'         => $bName,
                        'dedicated_tenant_id' => $uc->tenant_id ?? ($h->dedicated_tenant_id ?? null),
                        'source'              => 'user_context_files',
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning("[Butler Executive] user_context_files scan note: " . $e->getMessage());
            }

            // =========================================================================
            // NGUỒN 3: USER_AGENT_PROFILES (Bổ sung các thành viên qua SIM hoặc bot khác)
            // =========================================================================
            foreach ($profiles as $prof) {
                $meta = is_string($prof->metadata) ? json_decode($prof->metadata, true) : (array) ($prof->metadata ?? []);
                $uStr = (string)$prof->user_id;

                $isMem = (!empty($meta['is_family_member']) && $meta['is_family_member'] !== 'false')
                      || in_array($meta['role'] ?? '', ['child', 'wife', 'husband', 'member', 'parent'], true);

                if ($isMem) {
                    if (str_starts_with($uStr, 'group:') || str_starts_with($uStr, 'phone_') || empty($uStr)) {
                        continue;
                    }
                    if (!ctype_digit($uStr) || strlen($uStr) < 10) {
                        continue;
                    }
                    if (isset($giaChuPhoneSet[$uStr])) {
                        continue;
                    }

                    $primaryP = preg_replace('/[^0-9]/', '', (string)($meta['parent_phone'] ?? ($meta['primary_phone'] ?? '')));
                    if (str_starts_with($primaryP, '84') && strlen($primaryP) === 11) {
                        $primaryP = '0' . substr($primaryP, 2);
                    }

                    // Nếu đã nạp từ Nguồn 1 hoặc 2, bổ sung trường còn khuyết
                    if (isset($familyMembers[$uStr])) {
                        if (empty($familyMembers[$uStr]['parent_phone']) && $primaryP) {
                            $familyMembers[$uStr]['parent_phone'] = $primaryP;
                        }
                        if (empty($familyMembers[$uStr]['dedicated_tenant_id']) && !empty($meta['dedicated_tenant_id'])) {
                            $familyMembers[$uStr]['dedicated_tenant_id'] = $meta['dedicated_tenant_id'];
                        }
                        continue;
                    }

                    $mRole = trim($meta['role'] ?? 'member');
                    $mName = trim($meta['name'] ?? '');
                    if (empty($mName)) {
                        if ($mRole === 'child' || str_contains($mRole, 'con')) {
                            $mName = (string) ($memberNameOverrides[$primaryP] ?? 'con');
                        } elseif ($mRole === 'wife') {
                            $mName = 'Mẹ';
                        } elseif ($mRole === 'husband') {
                            $mName = 'Ba';
                        } else {
                            $mName = 'thành viên';
                        }
                    }

                    $h = $handoversByPhone[$primaryP] ?? null;
                    $bName = trim($meta['butler_name'] ?? ($h->butler_name ?? 'Quản Gia Aicenty'));
                    if (preg_match('/(?:thành le|chưa chọn|chưa có|null)/iu', $bName) || mb_strlen($bName) < 4) {
                        $bName = 'Quản Gia Aicenty';
                    }

                    $familyMembers[$uStr] = [
                        'user_id'             => $uStr,
                        'name'                => $mName,
                        'role'                => $mRole,
                        'parent_phone'        => $primaryP,
                        'family_name'         => trim($meta['family_name'] ?? ($h->family_name ?? '')),
                        'butler_name'         => $bName,
                        'dedicated_tenant_id' => $meta['dedicated_tenant_id'] ?? ($prof->tenant_id ?? ($h->dedicated_tenant_id ?? null)),
                        'source'              => 'user_agent_profiles',
                    ];
                    continue;
                }

                // Cập nhật và bổ sung hồ sơ Gia Chủ từ user_agent_profiles
                $pRaw = $meta['phone'] ?? null;
                if (empty($pRaw) && str_starts_with($uStr, 'phone_')) {
                    $pRaw = substr($uStr, 6);
                }
                if (empty($pRaw) && isset($phoneByZaloId[$uStr])) {
                    $pRaw = $phoneByZaloId[$uStr];
                }

                $pClean = preg_replace('/[^0-9]/', '', (string) $pRaw);
                if (str_starts_with($pClean, '84') && strlen($pClean) === 11) {
                    $pClean = '0' . substr($pClean, 2);
                }

                if (empty($pClean) || strlen($pClean) < 9 || strlen($pClean) > 11) {
                    continue;
                }

                $existing = $customers[$pClean] ?? [];

                $name = $meta['name'] ?? ($existing['name'] ?? 'Gia Chủ');
                $honorific = $meta['honorific'] ?? ($existing['honorific'] ?? ('Gia chủ ' . $name));
                $familyName = $meta['family_name'] ?? ($existing['family_name'] ?? ('Gia Đình ' . $name));
                $butlerName = $meta['butler_name'] ?? ($existing['butler_name'] ?? 'Quản Gia Aicenty');

                if (preg_match('/(?:bạn là ai|chưa chọn|chưa có|tổ ấm|null)/iu', $familyName) || mb_strlen($familyName) < 5) {
                    $familyName = 'Gia Đình ' . $name;
                }
                if (preg_match('/(?:thành le|chưa chọn|chưa có|null)/iu', $butlerName) || mb_strlen($butlerName) < 4) {
                    $butlerName = 'Quản Gia Aicenty';
                }

                $zaloQId = $existing['claimed_zalo_personal_id'] ?? null;
                if (empty($zaloQId) && !empty($meta['zalo_quangia_id'])) {
                    $zaloQId = (string) $meta['zalo_quangia_id'];
                }
                if (empty($zaloQId) && strlen($uStr) >= 15 && ctype_digit($uStr)) {
                    $zaloQId = $uStr;
                }

                $zaloOaId = $existing['zalo_oa_user_id'] ?? null;
                if (empty($zaloOaId) && !empty($meta['zalo_user_id'])) {
                    $candOaId = (string) $meta['zalo_user_id'];
                    if (strlen($candOaId) >= 18 && ctype_digit($candOaId)) {
                        $zaloOaId = $candOaId;
                    }
                }

                $tenantId = $meta['dedicated_tenant_id'] ?? ($existing['dedicated_tenant_id'] ?? null);
                $agentId  = $meta['dedicated_agent_id'] ?? ($existing['dedicated_agent_id'] ?? null);

                $createdAtStr = $meta['provisioned_at'] ?? ($meta['created_at'] ?? ($existing['created_at'] ?? now()->toIso8601String()));

                $isVip = (!empty($meta['is_vip']) && $meta['is_vip'] !== 'false')
                         || !empty($meta['vip_plan']) 
                         || ($tenantId && isset($vipTenantRows[$tenantId]))
                         || ($existing['is_vip'] ?? false);

                $vipUntil = $meta['vip_until'] ?? ($existing['vip_until'] ?? null);
                if ($vipUntil) {
                    try {
                        if (Carbon::parse($vipUntil)->isPast()) {
                            $isVip = false;
                        }
                    } catch (\Throwable $e) {}
                }

                $customers[$pClean] = [
                    'phone'                    => $pClean,
                    'name'                     => $name,
                    'honorific'                => $honorific,
                    'family_name'              => $familyName,
                    'butler_name'              => $butlerName,
                    'child_info'               => $existing['child_info'] ?? '',
                    'claimed_zalo_personal_id' => $zaloQId,
                    'zalo_oa_user_id'          => $zaloOaId,
                    'dedicated_tenant_id'      => $tenantId,
                    'dedicated_agent_id'       => $agentId,
                    'created_at'               => $createdAtStr,
                    'is_vip'                   => $isVip,
                    'vip_plan'                 => $meta['vip_plan'] ?? ($existing['vip_plan'] ?? null),
                    'vip_until'                => $vipUntil,
                ];
            }

            // =========================================================================
            // NGUỒN 4: FAMILY_MEMBERS & FAMILY_UNITS (Mô hình quan hệ doanh nghiệp chuẩn)
            // =========================================================================
            try {
                $fmRows = $db->table('family_members')
                    ->join('family_units', 'family_members.family_id', '=', 'family_units.id')
                    ->select('family_members.*', 'family_units.butler_name as unit_butler_name', 'family_units.stealth_channel_zalo_id', 'family_units.goclaw_org_id')
                    ->get();

                foreach ($fmRows as $fm) {
                    $mZalo = (string)($fm->zalo_account_id ?? '');
                    if (empty($mZalo) || !ctype_digit($mZalo) || strlen($mZalo) < 9) {
                        continue;
                    }

                    $pClean = preg_replace('/[^0-9]/', '', (string)$fm->stealth_channel_zalo_id);
                    if (str_starts_with($pClean, '84') && strlen($pClean) === 11) {
                        $pClean = '0' . substr($pClean, 2);
                    }

                    // Không tính nếu là chính Gia Chủ tối cao
                    if ($mZalo === $pClean || in_array($fm->role, ['master_father', 'master_mother', 'master'], true)) {
                        continue;
                    }
                    if (isset($giaChuPhoneSet[$mZalo])) {
                        continue;
                    }

                    if (isset($familyMembers[$mZalo])) {
                        if (empty($familyMembers[$mZalo]['parent_phone']) && $pClean) {
                            $familyMembers[$mZalo]['parent_phone'] = $pClean;
                        }
                        continue;
                    }

                    $rRaw = mb_strtolower($fm->role ?? 'member');
                    if (str_contains($rRaw, 'child') || str_contains($rRaw, 'con') || str_contains($rRaw, 'student')) {
                        $cleanRole = 'child';
                    } elseif (str_contains($rRaw, 'wife') || str_contains($rRaw, 'mother') || str_contains($rRaw, 'mẹ') || str_contains($rRaw, 'vợ')) {
                        $cleanRole = 'wife';
                    } elseif (str_contains($rRaw, 'husband') || str_contains($rRaw, 'father') || str_contains($rRaw, 'ba') || str_contains($rRaw, 'bố')) {
                        $cleanRole = 'husband';
                    } else {
                        $cleanRole = 'member';
                    }

                    $bName = trim($fm->unit_butler_name ?? 'Quản Gia Aicenty');
                    if (preg_match('/(?:thành le|chưa chọn|chưa có|null)/iu', $bName) || mb_strlen($bName) < 3) {
                        $bName = 'Quản Gia Aicenty';
                    }

                    $fmName = trim($fm->full_name ?? '');
                    if ((empty($fmName) || $fmName === 'con') && isset($channelContacts[$mZalo])) {
                        $fmName = trim($channelContacts[$mZalo]->display_name);
                    }

                    $familyMembers[$mZalo] = [
                        'user_id'             => $mZalo,
                        'name'                => $fmName ?: ($fm->preferred_title ?: 'thành viên'),
                        'role'                => $cleanRole,
                        'parent_phone'        => $pClean,
                        'family_name'         => 'Gia Đình ' . $pClean,
                        'butler_name'         => $bName,
                        'dedicated_tenant_id' => $fm->goclaw_org_id ?: null,
                        'source'              => 'family_members',
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning("[Butler Executive] family_members scan note: " . $e->getMessage());
            }

            // =========================================================================
            // BỔ SUNG GIA CHỦ TỪ FAMILY_UNITS (Khách hàng từ hệ thống quan hệ mới)
            // =========================================================================
            try {
                $fUnits = $db->table('family_units')->get();
                foreach ($fUnits as $fu) {
                    $pClean = preg_replace('/[^0-9]/', '', (string)$fu->stealth_channel_zalo_id);
                    if (str_starts_with($pClean, '84') && strlen($pClean) === 11) {
                        $pClean = '0' . substr($pClean, 2);
                    }
                    if (empty($pClean) || strlen($pClean) < 9 || strlen($pClean) > 11) {
                        continue;
                    }

                    // Gắn family_id cho khách hàng hiện có để query lịch bảo mật
                    if (isset($customers[$pClean])) {
                        $customers[$pClean]['family_id'] = $fu->id;
                        continue;
                    }

                    // Tìm master parent name từ family_members nếu có
                    $masterParentName = 'Gia Chủ';
                    if (!empty($fu->master_parent_id)) {
                        $mp = $db->table('family_members')->where('id', $fu->master_parent_id)->first();
                        if ($mp && !empty($mp->full_name)) {
                            $masterParentName = $mp->full_name;
                        }
                    }

                    $isVip = ($fu->subscription_status === 'ACTIVE' || $fu->is_migrated_to_vip || str_contains(strtoupper($fu->subscription_plan), 'VIP'));
                    $bName = trim($fu->butler_name ?? 'Quản Gia Aicenty');
                    if (empty($bName) || mb_strlen($bName) < 3) $bName = 'Quản Gia Aicenty';

                    $customers[$pClean] = [
                        'phone'                    => $pClean,
                        'name'                     => $masterParentName,
                        'honorific'                => 'Gia chủ ' . $masterParentName,
                        'family_name'              => 'Gia Đình ' . $masterParentName,
                        'butler_name'              => $bName,
                        'child_info'               => '',
                        'claimed_zalo_personal_id' => $fu->dedicated_zalo_id ?: null,
                        'zalo_oa_user_id'          => null,
                        'dedicated_tenant_id'      => $fu->goclaw_org_id ?: null,
                        'dedicated_agent_id'       => null,
                        'created_at'               => $fu->created_at ? Carbon::parse($fu->created_at)->toIso8601String() : now()->toIso8601String(),
                        'is_vip'                   => $isVip,
                        'vip_plan'                 => $fu->subscription_plan,
                        'vip_until'                => $fu->subscription_expires_at,
                        'family_id'                => $fu->id,
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning("[Butler Executive] family_units scan note: " . $e->getMessage());
            }

            // Lọc deduplicate cho thành viên: Nếu có SĐT 10 chữ số nhưng đã có ID dài hơn cho cùng người, giữ lại ID dài hơn
            $longUids = array_filter(array_keys($familyMembers), fn($uid) => strlen((string)$uid) >= 12);
            foreach ($familyMembers as $uid => $mem) {
                $uidStr = (string)$uid;
                if (strlen($uidStr) === 10) {
                    foreach ($longUids as $lUid) {
                        $lUidStr = (string)$lUid;
                        if (($familyMembers[$lUidStr]['parent_phone'] ?? '') === ($mem['parent_phone'] ?? '') && ($familyMembers[$lUidStr]['name'] ?? '') === ($mem['name'] ?? '')) {
                            unset($familyMembers[$uid]);
                            break;
                        }
                    }
                }
            }

            $dispatchedCount = 0;

            foreach ($customers as $phoneClean => $cust) {
                if ($targetPhone) {
                    $cleanTarget = preg_replace('/[^0-9]/', '', $targetPhone);
                    if ($phoneClean !== $cleanTarget) {
                        continue;
                    }
                }

                $isVip = $cust['is_vip'];
                $createdAtVn = Carbon::parse($cust['created_at'])->setTimezone($tz);
                $diffDays = $forcedDay ?: ((int) $nowVn->copy()->startOfDay()->diffInDays($createdAtVn->copy()->startOfDay()) + 1);

                $msgTemplate = null;
                $jobPrefix = null;

                // =========================================================================
                // PHÂN NHÁNH 1: CHÀO BUỔI SÁNG & TỐI CHO ZALO QUẢN GIA (07:00 & 21:30 VN)
                // - Laravel là bộ điều phối buổi sáng duy nhất; proactive-morning cũ được vô hiệu hóa.
                // - Lịch thật được đọc từ cron_jobs, đúng tenant và đúng người nhận.
                // =========================================================================
                if ($activeType === 'morning') {
                    $todayEvents = [];
                    $agendaAvailable = true;
                    $agendaTenantId = $cust['dedicated_tenant_id'] ?: '0193a5b0-7000-7000-8000-000000000001';
                    $agendaRecipient = (string) ($cust['claimed_zalo_personal_id'] ?? '');
                    $dayStartUtc = $nowVn->copy()->startOfDay()->utc();
                    $dayEndUtc = $nowVn->copy()->endOfDay()->utc();

                    if ($agendaRecipient !== '') {
                        try {
                            $agendaRows = $db->table('cron_jobs')
                                ->where('enabled', true)
                                ->where('tenant_id', $agendaTenantId)
                                ->where('deliver', true)
                                ->where('deliver_to', $agendaRecipient)
                                ->where('user_id', '<>', 'system')
                                ->where('name', 'not like', 'proactive-%')
                                ->where('name', 'not like', 'morning-%')
                                ->where('name', 'not like', 'evening-%')
                                ->where('name', 'not like', 'chuc-sang-%')
                                ->where('name', 'not like', 'drip-%')
                                ->where('name', 'not like', 'ops-alert-%')
                                ->where('name', 'not like', 'handover-%')
                                ->where('name', 'not like', 'congrat-%')
                                ->where('name', 'not like', 'remind-expire-%')
                                ->where(function ($query) use ($dayStartUtc, $dayEndUtc) {
                                    $query->whereBetween('next_run_at', [$dayStartUtc, $dayEndUtc])
                                        ->orWhereBetween('run_at', [$dayStartUtc, $dayEndUtc]);
                                })
                                ->orderByRaw('COALESCE(next_run_at, run_at) ASC')
                                ->limit(10)
                                ->get();

                            foreach ($agendaRows as $agendaRow) {
                                $eventAt = $agendaRow->next_run_at ?: $agendaRow->run_at;
                                if (!$eventAt) {
                                    continue;
                                }
                                $todayEvents[] = [
                                    'time' => Carbon::parse($eventAt)->setTimezone($tz)->format('H:i'),
                                    'title' => $morningBriefingService->titleFromJobName((string) $agendaRow->name),
                                ];
                            }
                        } catch (\Throwable $e) {
                            $agendaAvailable = false;
                            Log::warning('[Butler Morning] Unable to load agenda', [
                                'tenant_id' => $agendaTenantId,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                    $shortQuanGia = $cust['butler_name'] ?: 'Quản Gia';
                    $msgTemplate = $morningBriefingService->buildMessage(
                        '{DANH_XUNG}',
                        $shortQuanGia,
                        $todayEvents,
                        $phoneClean . ':' . $today,
                        $agendaAvailable
                    );
                    $jobPrefix = "morning-briefing";
                }

                elseif ($activeType === 'evening') {
                    $hasProactiveCron = $db->table('cron_jobs')
                        ->where('name', 'proactive-evening-' . $phoneClean)
                        ->where('enabled', true)
                        ->exists();

                    if ($hasProactiveCron && !$isForce) {
                        continue; // Đã có proactive-evening định kỳ qua Quản Gia -> không gửi đúp
                    }

                    $shortQuanGia = $cust['butler_name'] ?: 'Quản Gia';
                    $msgTemplate = "Chúc {DANH_XUNG} và gia đình buổi tối bình an, ngủ ngon ạ 🌙!";
                    $jobPrefix = "evening-briefing";
                }

                // =========================================================================
                // PHÂN NHÁNH 2: ZALO OA CHĂM SÓC QUY TRÌNH 7 NGÀY (14:00 CHIỀU VN)
                // =========================================================================
                elseif ($activeType === 'trial') {
                    if ($isVip) {
                        continue;
                    }

                    $dayToSend = $forcedDay ?: $diffDays;
                    if (in_array($dayToSend, [1, 3, 5, 7], true) && isset($trialTemplates[$dayToSend])) {
                        $msgTemplate = $trialTemplates[$dayToSend];
                        $jobPrefix = "drip-trial-day-{$dayToSend}";
                    } else {
                        continue;
                    }
                }

                // =========================================================================
                // PHÂN NHÁNH 3: ZALO OA CẢNH BÁO HỌC TẬP 48H (14:15 CHIỀU VN)
                // =========================================================================
                elseif ($activeType === 'study_alert') {
                    // Kiểm tra xem gia đình có con không
                    $childInfo = $cust['child_info'];
                    if (empty($childInfo) && mb_strlen($childInfo) < 3) {
                        $hasChildProfile = $db->table('user_agent_profiles')
                            ->whereRaw("(metadata->>'parent_phone' = ? OR metadata->>'primary_phone' = ?)", [$phoneClean, $phoneClean])
                            ->exists();
                        if (!$hasChildProfile) {
                            continue;
                        }
                    }

                    // Kiểm tra tương tác gần nhất của các con trong gia đình
                    $lastChildActivity = null;
                    try {
                        $tenantId = $cust['dedicated_tenant_id'];
                        if (!empty($tenantId)) {
                            $excludedUsers = array_filter(['system', (string)$cust['claimed_zalo_personal_id'], (string)$cust['zalo_oa_user_id']]);
                            $latestTenantSession = $db->table('sessions')
                                ->where('tenant_id', $tenantId)
                                ->whereNotIn('user_id', $excludedUsers)
                                ->where('session_key', 'not like', '%cron%')
                                ->orderByDesc('updated_at')
                                ->first();
                            if ($latestTenantSession && !empty($latestTenantSession->updated_at)) {
                                $lastChildActivity = Carbon::parse($latestTenantSession->updated_at);
                            }
                        }

                        if (!$lastChildActivity) {
                            $childUserIds = $db->table('user_agent_profiles')
                                ->whereRaw("(metadata->>'parent_phone' = ? OR metadata->>'primary_phone' = ?)", [$phoneClean, $phoneClean])
                                ->pluck('user_id')
                                ->toArray();

                            if (!empty($childUserIds)) {
                                $latestSession = $db->table('sessions')
                                    ->whereIn('user_id', $childUserIds)
                                    ->orderByDesc('updated_at')
                                    ->first();
                                if ($latestSession && !empty($latestSession->updated_at)) {
                                    $lastChildActivity = Carbon::parse($latestSession->updated_at);
                                }
                            }
                        }
                    } catch (\Throwable $e) {}

                    if ($lastChildActivity && $nowVn->diffInHours($lastChildActivity) < 48 && !$isForce) {
                        continue;
                    }

                    $childName = "con";
                    if (!empty($childInfo)) {
                        $foundNames = [];
                        if (preg_match_all('/(?:tên\s+(?:là\s+)?|con\s+(?:trai|gái)\s+|Bé\s+)([A-ZÀ-Ỹ][a-zà-ỹ]+(?:\s+[A-ZÀ-Ỹ][a-zà-ỹ]+)?)/iu', $childInfo, $matches)) {
                            foreach ($matches[1] as $n) {
                                $n = trim($n);
                                if ($n && !in_array(mb_strtolower($n), ['test', 'gia đình', 'gái', 'trai', 'lớp', 'học', 'đa thế hệ'], true)) {
                                    if (!in_array($n, $foundNames, true)) {
                                        $foundNames[] = $n;
                                    }
                                }
                            }
                        }
                        if (count($foundNames) === 1) {
                            $childName = "bé " . $foundNames[0];
                        } elseif (count($foundNames) === 2) {
                            $childName = "bé " . $foundNames[0] . " và bé " . $foundNames[1];
                        } elseif (count($foundNames) > 2) {
                            $childName = "các con";
                        } elseif (preg_match('/(?:con|bé|cháu|lớp)/iu', $childInfo)) {
                            $childName = "con";
                        }
                    }

                    $msgTemplate = "Chào {DANH_XUNG}, 2 hôm nay chưa thấy {$childName} ôn bài 📚. Tối nay bé học có bài khó cứ nhắn Quản Gia hướng dẫn nhé ạ!";
                    $jobPrefix = "study-inactivity-alert";
                }

                // =========================================================================
                // PHÂN NHÁNH 4: ZALO OA ĐỀ XUẤT BÁO CÁO TUẦN / THÁNG (14:30 CHIỀU VN)
                // =========================================================================
                elseif ($activeType === 'report_offer') {
                    $isSunday = $nowVn->isSunday();
                    $isLastDayOfMonth = $nowVn->isLastOfMonth();

                    if ($isLastDayOfMonth) {
                        $msgTemplate = "Chào {DANH_XUNG}, cuối tháng rồi 📈. Cần tổng kết học tập của con, nhắn Quản Gia: \"Báo cáo tháng\" nhé ạ!";
                        $jobPrefix = "report-offer-month";
                    } elseif ($isSunday || $isForce) {
                        $msgTemplate = "Chào {DANH_XUNG}, cuối tuần rồi 🌟. Cần xem báo cáo học tập tuần của con, nhắn Quản Gia: \"Xuất báo cáo tuần\" nhé ạ!";
                        $jobPrefix = "report-offer-week";
                    } else {
                        continue;
                    }
                }

                if (!$msgTemplate || !$jobPrefix) {
                    continue;
                }

                $name       = $cust['name'];
                $honorific  = $cust['honorific'];
                $butlerName = $cust['butler_name'];
                $familyName = $cust['family_name'];

                $paymentUrl = "https://aicenty.com/butler/payment?phone=" . urlencode($phoneClean) . "&name=" . urlencode($cust['name'] ?? $honorific) . "&butler_name=" . urlencode($butlerName) . "&plan=1m&is_upgrade=1";
                $msg = str_replace(
                    ['{DANH_XUNG}', '{QUAN_GIA}', '{TO_AM}', '{LINK_THANH_TOAN}'],
                    [$honorific, $butlerName, $familyName, $paymentUrl],
                    $msgTemplate
                );

                $jobName = "{$jobPrefix}-{$phoneClean}-{$today}";

                $zaloPersonalId = $cust['claimed_zalo_personal_id'];
                $zaloOaId       = $cust['zalo_oa_user_id'];
                $tenantId       = $cust['dedicated_tenant_id'];

                $targetLabel = "SĐT: {$phoneClean} | " . ($isVip ? "VIP ({$cust['vip_plan']})" : "Ngày {$diffDays}/7") . " | Gia Chủ: {$honorific}";

                if ($isDryRun) {
                    $this->info("-> [DRY-RUN] [{$activeType}] {$targetLabel}");
                    $this->line("   Quản Gia ID: " . ($zaloPersonalId ?: 'Chưa có') . " | Zalo OA ID: " . ($zaloOaId ?: 'Chưa có'));
                    $this->line("   Nội dung:\n" . $msg . "\n");
                    $dispatchedCount++;
                    continue;
                }

                // KIỂM TRA CHỐNG GỬI TRÙNG (IDEMPOTENCY 3 LỚP)
                if (!$isForce) {
                    // 1. Kiểm tra Cache
                    if (Cache::has("butler_drip_sent_{$jobName}")) {
                        $this->warn("-> {$targetLabel} -> Đã gửi hôm nay (Bỏ qua - Cache).");
                        continue;
                    }

                    // 2. Kiểm tra Activity Log
                    $alreadyLogged = $db->table('activity_logs')
                        ->where('action', 'drip_message_sent')
                        ->where('entity_id', $phoneClean)
                        ->whereRaw("(details->>'job_name' = ? OR (details->>'type' = ? AND details->>'date' = ?))", [$jobName, $activeType, $today])
                        ->exists();

                    if ($alreadyLogged) {
                        $this->warn("-> {$targetLabel} -> Đã gửi hôm nay (Bỏ qua - Activity Log).");
                        continue;
                    }

                    // 3. Kiểm tra cron_jobs (nếu đang chờ GoClaw chạy)
                    $alreadyScheduled = $db->table('cron_jobs')
                        ->where('name', $jobName)
                        ->where('enabled', true)
                        ->exists();

                    if ($alreadyScheduled) {
                        $this->warn("-> {$targetLabel} -> Đang chờ gửi trong hàng đợi cron_jobs (Bỏ qua).");
                        continue;
                    }
                }

                $sentOa = false;
                $sentPersonal = false;

                // =========================================================================
                // PHÂN LUỒNG TÁCH KÊNH TUYỆT ĐỐI (KHÔNG BAO GIỜ GỬI TRÙNG 2 KÊNH):
                // 1. ZALO QUẢN GIA (morning / evening): CHỈ gửi qua Zalo Quản Gia cá nhân/SIM.
                // 2. ZALO OA (trial / study_alert / report_offer): CHỈ gửi qua Zalo OA API (tầm 14h chiều).
                // =========================================================================
                $isOaWorkflow = in_array($activeType, ['trial', 'study_alert', 'report_offer'], true);
                $isButlerGreeting = in_array($activeType, ['morning', 'evening'], true);

                // KÊNH 1: ZALO OA API (CHỈ CHO CÁC TÁC VỤ CHĂM SÓC QUY TRÌNH)
                if ($isOaWorkflow && !empty($zaloOaId)) {
                    $oaRes = $zaloOaService->sendTextMessage((string) $zaloOaId, $msg);
                    if ($oaRes['success'] ?? false) {
                        $this->info("-> {$targetLabel} -> ĐÃ GỬI QUA ZALO OA API (ID:{$zaloOaId})!");
                        Log::info("[Butler Executive] Dispatched [{$activeType}] via Zalo OA API to {$phoneClean} (OA:{$zaloOaId})");
                        $sentOa = true;
                    } else {
                        $oaError = $oaRes['error'] ?? 'Unknown error';
                        $this->warn("-> {$targetLabel} -> Zalo OA gửi thất bại: {$oaError}");
                        Log::warning("[Butler Executive] Failed via Zalo OA to {$phoneClean} (OA:{$zaloOaId}): {$oaError}");
                    }
                }

                // KÊNH 2: ZALO QUẢN GIA CÁ NHÂN / SIM (CHỈ CHO CHÀO SÁNG 07H & TỐI 21H30)
                if ($isButlerGreeting && !empty($zaloPersonalId) && strlen($zaloPersonalId) >= 15 && ctype_digit($zaloPersonalId)) {
                    $channel = 'quangia';
                    $deliverAgentId = '01a1ac58-1bd8-7c30-b4c0-65f661aad8fc';
                    $deliverTenantId = '0193a5b0-7000-7000-8000-000000000001';

                    if (!empty($tenantId) && isset($channelInstances[$tenantId])) {
                        $channel = $channelInstances[$tenantId]->name;
                        $deliverAgentId = $channelInstances[$tenantId]->agent_id;
                        $deliverTenantId = $tenantId;
                    }

                    $strictShortPrompt = "[CHỈ THỊ BẮT BUỘC: GỬI NGUYÊN VĂN NỘI DUNG BÊN DƯỚI. KHÔNG THÊM, BỚT, ĐỔI DANH XƯNG, THỜI GIAN HOẶC CÔNG VIỆC]:\n" . $msg;

                    $db->table('cron_jobs')->insert([
                        'id'               => (string) Str::uuid(),
                        'agent_id'         => $deliverAgentId,
                        'user_id'          => 'system',
                        'name'             => $jobName,
                        'enabled'          => true,
                        'stateless'        => true,
                        'schedule_kind'    => 'at',
                        'run_at'           => now(),
                        'next_run_at'      => now()->addSeconds(10),
                        'payload'          => json_encode(['kind' => 'agent_turn', 'message' => $strictShortPrompt], JSON_UNESCAPED_UNICODE),
                        'delete_after_run' => true,
                        'tenant_id'        => $deliverTenantId,
                        'deliver'          => true,
                        'deliver_channel'  => $channel,
                        'deliver_to'       => (string) $zaloPersonalId,
                        'created_at'       => now(),
                        'updated_at'       => now(),
                    ]);

                    $this->info("-> {$targetLabel} -> ĐÃ LÊN LỊCH QUA ZALO QUẢN GIA ({$channel}:{$zaloPersonalId})!");
                    Log::info("[Butler Executive] Dispatched [{$activeType}] via Zalo Personal to {$phoneClean} ({$channel}:{$zaloPersonalId})");
                    $sentPersonal = true;
                }

                $sentSuccessfully = $sentOa || $sentPersonal;

                if ($sentSuccessfully) {
                    $dispatchedCount++;
                    Cache::put("butler_drip_sent_{$jobName}", true, $nowVn->copy()->endOfDay());

                    try {
                        $activeChannels = [];
                        if ($sentOa) $activeChannels[] = 'zalo_oa';
                        if ($sentPersonal) $activeChannels[] = 'zalo_personal';

                        $db->table('activity_logs')->insert([
                            'id'          => (string) Str::uuid(),
                            'actor_type'  => 'system',
                            'actor_id'    => 'butler_executive_scheduler',
                            'action'      => 'drip_message_sent',
                            'entity_type' => 'customer_phone',
                            'entity_id'   => $phoneClean,
                            'details'     => json_encode([
                                'job_name' => $jobName,
                                'type'     => $activeType,
                                'date'     => $today,
                                'phone'    => $phoneClean,
                                'is_vip'   => $isVip,
                                'channels' => $activeChannels,
                            ], JSON_UNESCAPED_UNICODE),
                            'tenant_id'   => $tenantId ?: '0193a5b0-7000-7000-8000-000000000001',
                            'created_at'  => now(),
                        ]);
                    } catch (\Throwable $e) {
                        Log::warning("[Butler Executive] Failed to record activity log: " . $e->getMessage());
                    }
                } else {
                    if ($isOaWorkflow && empty($zaloOaId)) {
                        $this->line("-> {$targetLabel} -> Chưa có Zalo OA ID (Bỏ qua tác vụ OA).");
                    } elseif ($isButlerGreeting && empty($zaloPersonalId)) {
                        $this->line("-> {$targetLabel} -> Chưa có Zalo Quản Gia ID (Bỏ qua).");
                    } else {
                        $this->error("-> {$targetLabel} -> Chưa thể gửi qua kênh mục tiêu!");
                    }
                }
            }

            // =========================================================================
            // BƯỚC MỞ RỘNG: CHÀO SÁNG / TỐI SIÊU NGẮN CHO THÀNH VIÊN QUA ZALO QUẢN GIA
            // =========================================================================
            if (in_array($activeType, ['morning', 'evening'], true) && !empty($familyMembers)) {
                $this->info("\n--- BẮT ĐẦU GỬI CHÀO [{$activeType}] CHO THÀNH VIÊN TỔ CHỨC (" . count($familyMembers) . " thành viên) ---");

                foreach ($familyMembers as $mUid => $mem) {
                    $mName = $mem['name'];
                    $mRole = $mem['role'];
                    $parentPhone = $mem['parent_phone'];
                    if (empty($parentPhone)) {
                        continue;
                    }
                    $mButlerName = $mem['butler_name'] ?: 'Quản Gia Aicenty';
                    if (preg_match('/(?:thành le|chưa chọn|chưa có|null)/iu', $mButlerName) || mb_strlen($mButlerName) < 4) {
                        $mButlerName = 'Quản Gia Aicenty';
                    }

                    if ($targetPhone && $parentPhone !== $targetPhone) {
                        continue;
                    }

                    $memType = "{$activeType}_member";
                    $memJobName = "{$activeType}-member-{$mUid}-{$today}";
                    $mTargetLabel = "[Thành viên: {$mName} | Vai trò: {$mRole} | Gia chủ: {$parentPhone} | UID: {$mUid}]";

                    // Kiểm tra Idempotency chuẩn theo type (morning_member hoặc evening_member)
                    if (!$isForce) {
                        if (Cache::has("butler_drip_sent_{$memJobName}")) {
                            $this->line("-> {$mTargetLabel} -> Đã gửi {$activeType} hôm nay (Cache skip).");
                            continue;
                        }

                        $alreadyLogged = $db->table('activity_logs')
                            ->where('action', 'drip_member_message_sent')
                            ->where('entity_id', $mUid)
                            ->whereRaw("details->>'type' = ? AND details->>'date' = ?", [$memType, $today])
                            ->exists();

                        if ($alreadyLogged) {
                            $this->line("-> {$mTargetLabel} -> Đã gửi {$activeType} hôm nay (DB log skip).");
                            continue;
                        }
                    }

                    // Nội dung siêu ngắn gọn, không xưng "em"
                    $caller = $mButlerName ?: 'Quản Gia';
                    if (preg_match('/(?:thành le|chưa chọn|chưa có|null)/iu', $caller) || mb_strlen($caller) < 3) {
                        $caller = 'Quản Gia';
                    }
                    $shortCaller = (str_starts_with($caller, 'Quản Gia') || $caller === 'Quản Gia Aicenty') ? 'Quản Gia' : $caller;

                    if ($activeType === 'morning') {
                        if ($mRole === 'child' || str_contains($mRole, 'con')) {
                            $memMsg = "Chào {$mName}! {$shortCaller} chúc con ngày mới học vui nhé 🌟. Có bài khó cứ nhắn {$shortCaller}!";
                        } else {
                            $memMsg = "Chào {$mName}, {$caller} chúc {$mName} ngày mới tốt lành ạ!";
                        }
                    } else {
                        if ($mRole === 'child' || str_contains($mRole, 'con')) {
                            $memMsg = "Tối rồi, {$shortCaller} chúc {$mName} ngủ ngon nhé 🌙!";
                        } else {
                            $memMsg = "Chúc {$mName} và gia đình buổi tối bình an, ngủ ngon ạ 🌙!";
                        }
                    }

                    if ($isDryRun) {
                        $this->info("-> [DRY-RUN] {$mTargetLabel}:\n{$memMsg}\n");
                        $dispatchedCount++;
                        continue;
                    }

                    // Xác định channel & tenant của Quản Gia SIM
                    $memTenantId = $mem['dedicated_tenant_id'] ?? ($customers[$parentPhone]['dedicated_tenant_id'] ?? null);
                    $channel = 'quangia';
                    $deliverAgentId = '01a1ac58-1bd8-7c30-b4c0-65f661aad8fc';
                    $deliverTenantId = '0193a5b0-7000-7000-8000-000000000001';

                    if (!empty($memTenantId) && isset($channelInstances[$memTenantId])) {
                        $channel = $channelInstances[$memTenantId]->name;
                        $deliverAgentId = $channelInstances[$memTenantId]->agent_id;
                        $deliverTenantId = $memTenantId;
                    }

                    $strictMemPrompt = "[CHỈ THỊ BẮT BUỘC: CHỈ TRẢ VỀ ĐÚNG NGUYÊN VĂN CÂU SAU, KHÔNG THÊM BẤT KỲ CHỮ NÀO, KHÔNG XƯNG 'EM']:\n" . $memMsg;

                    try {
                        $db->table('cron_jobs')->insert([
                            'id'               => (string) Str::uuid(),
                            'agent_id'         => $deliverAgentId,
                            'user_id'          => 'system',
                            'name'             => $memJobName,
                            'enabled'          => true,
                            'stateless'        => true,
                            'schedule_kind'    => 'at',
                            'run_at'           => now(),
                            'next_run_at'      => now()->addSeconds(10),
                            'payload'          => json_encode(['kind' => 'agent_turn', 'message' => $strictMemPrompt], JSON_UNESCAPED_UNICODE),
                            'delete_after_run' => true,
                            'tenant_id'        => $deliverTenantId,
                            'deliver'          => true,
                            'deliver_channel'  => $channel,
                            'deliver_to'       => (string) $mUid,
                            'created_at'       => now(),
                            'updated_at'       => now(),
                        ]);

                        Cache::put("butler_drip_sent_{$memJobName}", true, $nowVn->copy()->endOfDay());

                        $db->table('activity_logs')->insert([
                            'id'          => (string) Str::uuid(),
                            'actor_type'  => 'system',
                            'actor_id'    => 'butler_executive_scheduler',
                            'action'      => 'drip_member_message_sent',
                            'entity_type' => 'family_member_uid',
                            'entity_id'   => $mUid,
                            'details'     => json_encode([
                                'job_name'     => $memJobName,
                                'type'         => $memType,
                                'date'         => $today,
                                'member_name'  => $mName,
                                'role'         => $mRole,
                                'parent_phone' => $parentPhone,
                                'channel'      => $channel,
                            ], JSON_UNESCAPED_UNICODE),
                            'tenant_id'   => $deliverTenantId,
                            'created_at'  => now(),
                        ]);

                        $this->info("-> {$mTargetLabel} -> ĐÃ LÊN LỊCH CHÀO [{$activeType}] ({$channel}:{$mUid})!");
                        Log::info("[Butler Executive] Dispatched {$activeType} member drip to {$mName} ({$mRole}) at {$mUid}");
                        $dispatchedCount++;
                    } catch (\Throwable $e) {
                        $this->error("-> {$mTargetLabel} -> Gửi thất bại: " . $e->getMessage());
                        Log::error("[Butler Executive] Member drip error for {$mUid}: " . $e->getMessage());
                    }
                }
            }

            $this->info("Hoàn tất tác vụ [{$activeType}]. Tổng số lượng đã gửi: {$dispatchedCount}");
            return 0;
        } catch (\Throwable $e) {
            $this->error("Lỗi Butler Executive Command: " . $e->getMessage() . " tại dòng " . $e->getLine());
            Log::error("[Butler Executive] Execution error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return 1;
        }
    }
}
