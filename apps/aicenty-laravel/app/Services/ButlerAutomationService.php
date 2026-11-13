<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ButlerAutomationService
{
    private const MASTER_TENANT_ID = '0193a5b0-7000-7000-8000-000000000001';

    private const STANDARD_BUTLER_SKILL_SLUGS = [
        'document-to-pdf',
        'images-to-pdf',
        'quangia-image-generation',
        'quangia-mobile-messaging',
    ];

    public function __construct(
        private readonly ButlerIdentityRegistryService $identityRegistry
    ) {
    }

    /**
     * Bắn thông báo Lead mới vào Zalo Group HNKT Team (qua GoClaw cron_jobs outbound delivery).
     */
    public function notifyZaloGroupHNKT(array $leadData): void
    {
        $name = $leadData['name'] ?? 'Chưa rõ';
        $phone = $leadData['phone'] ?? 'Chưa rõ';
        $email = $leadData['email'] ?? 'Chưa rõ';
        $family = $leadData['family_members'] ?? 'Chưa rõ';
        $butlerName = $leadData['butler_name'] ?? 'Quản Gia Riêng Gia Đình';
        $now = now()->setTimezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i:s');

        $msg = "🔔 [LEAD MỚI] ĐĂNG KÝ TRẢI NGHIỆM QUẢN GIA AI\n"
             . "👤 Gia Chủ: {$name}\n"
             . "📱 SĐT / Zalo: {$phone}\n"
             . "📧 Email: {$email}\n"
             . "👨‍👩‍👧‍👦 Thành viên: {$family}\n"
             . "🎩 Tên Quản Gia mong muốn: {$butlerName}\n"
             . "⏰ Thời gian: {$now}\n"
             . "👉 Khách đang chuyển sang Butler Guru onboarding 1-1.";

        Log::info("[ButlerAutomation] Lead HNKT notification: {$name} - {$phone}");

        try {
            $groupId = env('ZALO_HNKT_GROUP_ID', '');
            $adminZaloId = env('ZALO_ADMIN_NOTIFY_ID', '1398202431130007333'); // Admin HNKT Team
            $targets = array_filter([$groupId, $adminZaloId]);

            $activeChannel = $this->resolveActiveZaloChannel('butlerguru');
            $activeAgentId = $this->resolveAgentForChannel($activeChannel);

            foreach ($targets as $targetId) {
                DB::connection('butler_pgsql')->table('cron_jobs')->insert([
                    'id'               => (string) Str::uuid(),
                    'agent_id'         => $activeAgentId,
                    'user_id'          => 'system',
                    'name'             => 'notify-hnkt-lead-' . preg_replace('/[^0-9]/', '', $phone) . '-' . time(),
                    'enabled'          => true,
                    'schedule_kind'    => 'at',
                    'run_at'           => now(),
                    'next_run_at'      => now()->addSeconds(10),
                    'payload'          => json_encode(['kind' => 'agent_turn', 'message' => $msg], JSON_UNESCAPED_UNICODE),
                    'delete_after_run' => true,
                    'tenant_id'        => '0193a5b0-7000-7000-8000-000000000001', // Master
                    'deliver'          => true,
                    'deliver_channel'  => $activeChannel,
                    'deliver_to'       => (string) $targetId,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('[ButlerAutomation] Zalo Group dispatch skipped/failed: ' . $e->getMessage());
        }
    }

    /**
     * Lưu hồ sơ Onboarding và liên kết hồ sơ gia đình vào Database GoClaw (Postgres).
     */
    public function saveOnboardingProfile(array $data): bool
    {
        $phone = trim((string) ($data['phone'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $familyName = trim((string) ($data['family_name'] ?? ('Gia Đình ' . $name)));
        $butlerName = trim((string) ($data['butler_name'] ?? 'Bác Phúc'));
        $childInfo = trim((string) ($data['child_info'] ?? ''));
        $primaryConcern = trim((string) ($data['primary_concern'] ?? 'Quản xuyến toàn diện'));
        $zaloUserId = trim((string) ($data['zalo_user_id'] ?? ''));

        if (empty($phone) && empty($zaloUserId)) {
            Log::warning('[ButlerAutomation] saveOnboardingProfile thiếu phone hoặc zalo_user_id');
            return false;
        }

        $phoneClean = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($phoneClean, '84') && strlen($phoneClean) === 11) {
            $phoneClean = '0' . substr($phoneClean, 2);
        }
        $phoneUserId = 'phone_' . $phoneClean;
        $targetUserIds = array_unique(array_filter([
            $phoneUserId,
            $phoneClean,
            '84' . substr($phoneClean, -9),
            'phone_84' . substr($phoneClean, -9),
            $zaloUserId
        ]));

        $agentIds = [
            '01a1ac58-1bd8-7c30-b4c0-65f661aad8fc', // aicenty-master-butler (Quản Gia Master)
            '01a1ac58-1bf3-7000-a68c-c6d36beaa62b', // butler-guru (Cố Vấn Kỹ Thuật & CSKH)
        ];
        $masterTenantId = '0193a5b0-7000-7000-8000-000000000001';

        $profileData = [
            'name'            => $name,
            'phone'           => $phone,
            'email'           => $email,
            'family_name'     => $familyName,
            'butler_name'     => $butlerName,
            'child_info'      => $childInfo,
            'primary_concern' => $primaryConcern,
            'updated_at'      => now()->toIso8601String(),
        ];

        $userMdContent = "# USER.md - Hồ Sơ Gia Chủ & Tổ Ấm\n"
            . "- **Họ tên gia chủ tối cao (Master Owner):** {$name}\n"
            . "- **Tên trên form:** {$name}\n"
            . "- **Số điện thoại:** {$phoneClean}\n"
            . "- **Danh xưng chuẩn:** Gia chủ {$name}\n"
            . "- **Vai trò:** Gia Chủ Tối Cao (Master Owner / Super Admin)\n"
            . "- **Trạng thái:** Đang trong luồng Onboarding 7 ngày trải nghiệm\n"
            . "- **Tên Quản Gia riêng:** Chưa chọn (Chờ gia chủ đặt tên)\n"
            . "- **Danh xưng gia chủ:** Chưa chọn (Chờ gia chủ chọn Ba/Mẹ/Anh/Chị)\n"
            . "- **Tên Tổ Ấm:** Chưa chọn (Chờ gia chủ đặt tên)\n"
            . "- **Các con & Môn học ưu tiên:** " . (!empty($childInfo) ? $childInfo : 'Chưa cung cấp') . "\n\n"
            . "## ĐIỀU LỆ PHÂN QUYỀN TỔ ẤM TỐI CAO (STRICT RBAC PROTOCOL):\n"
            . "1. GIA CHỦ TỐI CAO ({$name} - SĐT: {$phoneClean}) LÀ NGƯỜI DUY NHẤT CÓ QUYỀN THAY ĐỔI CÀI ĐẶT.\n"
            . "2. Quản Gia TUYỆT ĐỐI KHÔNG thực hiện thay đổi tên Quản Gia, tên Tổ Ấm, lịch nhắc hay nếp nhà nếu lệnh đến từ bất kỳ ai khác.\n"
            . "3. Mọi thành viên khác trong gia đình chỉ có quyền tương tác hỏi đáp, tâm sự, tra cứu, nhận lời nhắc học tập/sinh hoạt; KHÔNG có quyền thay đổi cấu hình hệ thống.\n"
            . "4. TỪ CHỐI LỄ PHÉP: Nếu thành viên khác yêu cầu thay đổi, Quản Gia từ chối lễ phép và nhờ Gia Chủ {$name} thực hiện.\n";

        try {
            // Lưu vào Cache để Zalo OA và các luồng liên kết ngay lập tức
            Cache::put('recent_butler_trial_lead', [
                'name'            => $name,
                'phone'           => $phoneClean,
                'email'           => $email,
                'family_name'     => $familyName,
                'butler_name'     => $butlerName,
                'child_info'      => $childInfo,
                'primary_concern' => $primaryConcern,
                'time'            => now()->timestamp,
            ], now()->addHours(3));

            $db = DB::connection('butler_pgsql');

            foreach ($agentIds as $agentId) {
                foreach ($targetUserIds as $uid) {
                    $db->table('user_agent_profiles')->updateOrInsert(
                        [
                            'agent_id'  => $agentId,
                            'user_id'   => $uid,
                            'tenant_id' => $masterTenantId,
                        ],
                        [
                            'metadata'     => json_encode($profileData, JSON_UNESCAPED_UNICODE),
                            'last_seen_at' => now(),
                        ]
                    );

                    $db->table('user_context_files')->updateOrInsert(
                        [
                            'agent_id'  => $agentId,
                            'user_id'   => $uid,
                            'file_name' => 'USER.md',
                        ],
                        [
                            'content'    => $userMdContent,
                            'tenant_id'  => $masterTenantId,
                            'updated_at' => now(),
                        ]
                    );
                }
            }

            // 2. Lưu vào bảng quangia_pending_handover để trigger PostgreSQL liên kết tức thì khi khách nhắn Zalo Quản Gia
            $handoverId = (string) Str::uuid();
            $db->table('quangia_pending_handover')->insert([
                'id'              => $handoverId,
                'phone'           => $phoneClean,
                'name'            => $name,
                'email'           => $email,
                'honorific'       => 'Gia chủ ' . $name,
                'family_name'     => $familyName,
                'butler_name'     => 'Quản Gia Aicenty',
                'child_info'      => $childInfo,
                'user_md_content' => $userMdContent,
                'created_at'      => now(),
            ]);

            // 3. Nếu số điện thoại này đã từng liên kết với Zalo ID nào trước đó (ví dụ tài khoản test),
            // reset hoàn toàn session để khách được trải nghiệm từ đầu như mới!
            $prevZaloUids = [];
            $prevProfiles = $db->table('user_agent_profiles')
                ->whereRaw("metadata->>'phone' = ?", [$phoneClean])
                ->get();
            foreach ($prevProfiles as $p) {
                $uid = (string) $p->user_id;
                if (strlen($uid) >= 15 && ctype_digit($uid)) {
                    $prevZaloUids[] = $uid;
                }
            }

            try {
                $handoverUids = $db->table('quangia_pending_handover')
                    ->where('phone', $phoneClean)
                    ->whereNotNull('claimed_zalo_personal_id')
                    ->pluck('claimed_zalo_personal_id')
                    ->toArray();
                foreach ($handoverUids as $hUid) {
                    if (!empty($hUid) && strlen((string) $hUid) >= 15 && ctype_digit((string) $hUid)) {
                        $prevZaloUids[] = (string) $hUid;
                    }
                }
            } catch (\Throwable $e) {}

            $prevZaloUids = array_unique($prevZaloUids);

            foreach ($prevZaloUids as $uid) {
                // Xóa sessions cũ trên kênh quangia để khách có cuộc hội thoại mới 100%
                $db->table('sessions')
                    ->where('channel', 'quangia')
                    ->where('user_id', $uid)
                    ->delete();

                // Nạp BOOTSTRAP.md chuẩn xác cho luồng Onboarding
                $bootstrapContent = "# BOOTSTRAP.md - Tiếp Đón & Thiết Lập Quản Gia Riêng Cho Gia Đình\n\n"
                    . "Bạn là Quản Gia Aicenty — Quản gia thời hiện đại, thông thái, lịch thiệp, khiêm nhường và tận tụy phục vụ gia đình.\n"
                    . "Gia chủ vừa hoàn tất đăng ký trên website và vừa kết nối với bạn qua Zalo Quản Gia để bắt đầu 7 ngày trải nghiệm.\n\n"
                    . "## 1. PHONG THÁI & LỜI CHÀO ĐẦU TIÊN (RẤT QUAN TRỌNG)\n"
                    . "- Gia chủ đã đăng ký tên trên website là: \"{$name}\".\n"
                    . "- Số điện thoại đăng ký: \"{$phoneClean}\".\n"
                    . "- Ngay trong tin nhắn đầu tiên khi gia chủ nhắn đến (kể cả khi gia chủ chỉ gửi nhãn dán / sticker như vẫy tay, mặt cười, hoa, tim...), Quản gia BẮT BUỘC phải phản hồi ngay lập tức, xưng hô đúng tên gia chủ:\n"
                    . "  \"Dạ, Quản gia xin kính chào Gia chủ {$name}! Rất vinh dự được đón tiếp và phục vụ gia đình mình hôm nay ạ.\"\n"
                    . "- Nếu gia chủ gửi sticker ở bất kỳ thời điểm nào: Luôn phản hồi tươi vui, lịch thiệp và tiếp tục câu chuyện, TUYỆT ĐỐI không im lặng.\n"
                    . "- Múi giờ mặc định là GMT+7 (Việt Nam). Ngôn ngữ mặc định là Tiếng Việt. TUYỆT ĐỐI KHÔNG hỏi múi giờ, KHÔNG hỏi ngôn ngữ.\n\n"
                    . "## 1.1. CỔNG XÁC MINH VAI TRÒ TRƯỚC ONBOARDING\n"
                    . "- Chỉ chạy quy trình thiết lập bên dưới khi USER.md xác nhận người đang nhắn là Gia Chủ Tối Cao / Master Owner đã đăng ký.\n"
                    . "- Nếu người đang nhắn là thành viên gia đình hoặc chưa xác minh là Gia chủ chính: DỪNG quy trình thiết lập, giữ nguyên tên Quản Gia và tên tổ ấm hiện có; TUYỆT ĐỐI KHÔNG hỏi đặt tên Quản Gia. Chỉ hỏi gộp trong một câu ngắn về tên muốn được gọi, vai trò trong gia đình và nhu cầu hỗ trợ còn thiếu, rồi phục vụ ngay.\n\n"
                    . "## 2. QUY TRÌNH THIẾT LẬP TỔ ẤM (ONBOARDING TỰ NHIÊN)\n"
                    . "Hãy tìm hiểu nhẹ nhàng các thông tin tổ ấm qua cuộc trò chuyện (hỏi từng câu một, ngắn gọn, tự nhiên, không hỏi dồn dập):\n"
                    . "1. **Tên Quản gia riêng:** Gia đình muốn đặt tên riêng cho Quản gia là gì để tiện xưng hô hàng ngày ạ? (Mặc định là Bác Phúc, hoặc Chú Lâm, Bác 7, Tony... hoặc cứ gọi Quản gia).\n"
                    . "2. **Tên Tổ Ấm:** Tên gọi thân thương của gia đình mình là gì ạ? (Ví dụ: Tổ Ấm {$name}, Gia đình Hạnh Phúc...).\n"
                    . "3. **Các bé & mục tiêu kèm cặp:** Các bé nhà mình đang học lớp mấy và gia đình muốn Quản gia ưu tiên kèm cặp, hỗ trợ điều gì nhất (Toán tư duy Socratic, Tiếng Anh, rèn thói quen tự giác học tập, sắp xếp thời gian...)?\n\n"
                    . "## 3. LƯU TRỮ VÀ HOÀN TẤT THIẾT LẬP\n"
                    . "- Khi Gia chủ trả lời tên Quản gia, tên Tổ ấm và thông tin các bé:\n"
                    . "  Quản gia lập tức gọi công cụ `write_file` lưu vào file `USER.md` với cấu trúc:\n"
                    . "```markdown\n"
                    . "# USER.md - Hồ Sơ Gia Chủ & Tổ Ấm\n"
                    . "- **Họ tên gia chủ:** {$name}\n"
                    . "- **Danh xưng chuẩn:** Gia chủ {$name}\n"
                    . "- **Số điện thoại:** {$phoneClean}\n"
                    . "- **Tên Quản Gia riêng:** [Tên Quản gia gia đình chọn]\n"
                    . "- **Tên Tổ Ấm:** [Tên tổ ấm gia đình chọn]\n"
                    . "- **Các thành viên nhí & ưu tiên:** [Lớp, môn học, tính cách]\n"
                    . "- **Phong cách phục vụ:** Quản gia xưng đúng tên riêng đã chọn, gọi chuẩn danh xưng của gia chủ, áp dụng phương pháp Socratic, luôn lễ phép, tận tâm.\n"
                    . "```\n"
                    . "- Sau khi gia chủ chốt tên Tổ ấm và tên Quản gia, Quản gia thông báo ngắn gọn:\n"
                    . "  \"Dạ em đã ghi nhận đầy đủ thông tin tổ ấm của mình rồi ạ. Hệ thống đang tiến hành kích hoạt tổ chức riêng cho gia đình mình và gửi thông tin qua email. Rất quan trọng: Gia chủ hãy kết bạn với Zalo Quản Gia (0961 592 763) trước, sau đó tạo nhóm Zalo gia đình và thêm Quản Gia cùng các thành viên vào nhóm nhé ạ!\"\n"
                    . "- Gọi công cụ `write_file` lưu vào `BOOTSTRAP.md` nội dung rỗng `\"\"` để đánh dấu đã hoàn tất onboarding.\n\n"
                    . "## 4. HƯỚNG DẪN TRẢI NGHIỆM SAU KHI LƯU\n"
                    . "1. Xưng đúng tên riêng đã chọn (ví dụ: \"Bác Phúc\", \"Bác 7\"...) và gọi đúng danh xưng của gia chủ.\n"
                    . "2. Hướng dẫn ngắn gọn nhất: Rất quan trọng: Gia chủ hãy kết bạn với Zalo Quản Gia (0961 592 763) trước, sau đó tạo nhóm Zalo gia đình và thêm Quản Gia cùng các thành viên vào nhóm nhé ạ.\n"
                    . "3. Khi được thêm vào nhóm Zalo gia đình: Quản Gia lập tức gửi lời chào trân trọng tới cả nhà, điểm danh và giới thiệu các thành viên trong tổ ấm để cùng đồng hành.\n"
                    . "4. Hỏi xem hôm nay gia đình có lịch trình, việc học của các con hay công việc gì cần Quản gia hỗ trợ sắp xếp ngay không.\n\n"
                    . "## 5. BẢO MẬT TUYỆT ĐỐI\n"
                    . "- TUYỆT ĐỐI KHÔNG để lộ quá trình kỹ thuật: Không nhắc đến \"USER.md\", \"BOOTSTRAP.md\", \"write_file\", \"lưu file\", \"công cụ\".\n"
                    . "- Luôn giữ vai trò là một người Quản gia thông thái, chân thành và tận tâm ngoài đời thực.";

                $db->table('user_context_files')->updateOrInsert(
                    [
                        'agent_id'  => '01a1ac58-1bd8-7c30-b4c0-65f661aad8fc',
                        'user_id'   => $uid,
                        'file_name' => 'BOOTSTRAP.md',
                    ],
                    [
                        'content'    => $bootstrapContent,
                        'tenant_id'  => $masterTenantId,
                        'updated_at' => now(),
                    ]
                );

                // Cập nhật USER.md với đúng tên mới trên form
                $db->table('user_context_files')->updateOrInsert(
                    [
                        'agent_id'  => '01a1ac58-1bd8-7c30-b4c0-65f661aad8fc',
                        'user_id'   => $uid,
                        'file_name' => 'USER.md',
                    ],
                    [
                        'content'    => $userMdContent,
                        'tenant_id'  => $masterTenantId,
                        'updated_at' => now(),
                    ]
                );

                // Cập nhật channel_contacts để hiển thị đúng tên mới trên form
                $db->table('channel_contacts')
                    ->where('sender_id', $uid)
                    ->update([
                        'display_name' => $name,
                    ]);

                // Gán ngay vào pending handover
                $db->table('quangia_pending_handover')
                    ->where('id', $handoverId)
                    ->update([
                        'claimed_zalo_personal_id' => $uid,
                        'claimed_at'               => now(),
                    ]);

                // Cấp quyền Gia Chủ Tối Cao (Master Owner) cho UID
                $db->table('agent_config_permissions')->updateOrInsert(
                    [
                        'agent_id'    => '01a1ac58-1bd8-7c30-b4c0-65f661aad8fc',
                        'user_id'     => $uid,
                        'scope'       => '*',
                        'config_type' => '*',
                    ],
                    [
                        'permission'  => 'allow',
                        'tenant_id'   => $masterTenantId,
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]
                );
            }

            // Kích hoạt đồng bộ hóa tức thì toàn diện Strict RBAC cho toàn bộ tổ ấm
            try {
                $db->statement("SELECT public.sync_quangia_family_profile_all(?)", [$handoverId]);
            } catch (\Throwable $e) {
                Log::warning('[ButlerAutomation] sync_quangia_family_profile_all non-fatal notice: ' . $e->getMessage());
            }

            Log::info("[ButlerAutomation] Saved onboarding profile and pending handover for {$phoneUserId}: {$familyName} - {$name}");
            return true;
        } catch (\Throwable $e) {
            Log::error('[ButlerAutomation] Error saving profile: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Tự động tạo Tenant riêng biệt cho khách, clone 24 Agent từ Master và di chuyển ký ức (sessions + memory).
     */
    public function createCustomerTenantAndMigrate(string $phone, string $name, string $butlerName = 'Bác Phúc', ?string $zaloUserId = null): ?string
    {
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        $masterTenantId = '0193a5b0-7000-7000-8000-000000000001';
        $slug = Str::slug($name) . '-' . substr($cleanPhone, -4);
        $familyName = 'Gia Đình ' . $name;

        try {
            $db = DB::connection('butler_pgsql');
            $boundTenantId = null;
            foreach (array_filter([$zaloUserId, $cleanPhone, 'phone_' . $cleanPhone]) as $identity) {
                $binding = $this->identityRegistry->assertOwnerProvisioningAllowed((string) $identity);
                if ($binding) {
                    if ($boundTenantId && $boundTenantId !== $binding->tenant_id) {
                        throw new \RuntimeException('Các định danh khách hàng đang trỏ tới nhiều tenant khác nhau.');
                    }
                    $boundTenantId = $binding->tenant_id;
                }
            }

            // 1. Kiểm tra nếu tenant đã tồn tại
            $existingTenant = $boundTenantId
                ? $db->table('tenants')->where('id', $boundTenantId)->first()
                : $db->table('tenants')->where('slug', $slug)->first();
            if ($existingTenant) {
                $newTenantId = $existingTenant->id;
            } else {
                // Tạo Tenant mới bằng UUIDv4
                $newTenantId = (string) Str::uuid();
                $db->table('tenants')->insert([
                    'id'         => $newTenantId,
                    'name'       => $familyName,
                    'slug'       => $slug,
                    'status'     => 'active',
                    'settings'   => json_encode(['family_phone' => $phone, 'owner_name' => $name], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                Log::info("[ButlerAutomation] Created Tenant: {$familyName} ({$newTenantId})");
            }

            // 2. Clone toàn bộ Agents từ Master sang Tenant mới
            $masterAgents = $db->table('agents')
                ->where('tenant_id', $masterTenantId)
                ->whereNull('deleted_at')
                ->get();

            $newMasterButlerId = null;

            foreach ($masterAgents as $agent) {
                $existing = $db->table('agents')
                    ->where('tenant_id', $newTenantId)
                    ->where('agent_key', $agent->agent_key)
                    ->first();

                if (!$existing) {
                    $newAgentId = (string) Str::uuid();
                    $displayName = $agent->display_name;
                    if ($agent->agent_key === 'aicenty-master-butler') {
                        $displayName = $butlerName . ' (' . $familyName . ')';
                        $newMasterButlerId = $newAgentId;
                    }

                    $agentArray = (array) $agent;
                    $agentArray['id'] = $newAgentId;
                    $agentArray['tenant_id'] = $newTenantId;
                    $agentArray['display_name'] = $displayName;
                    $agentArray['created_at'] = now();
                    $agentArray['updated_at'] = now();
                    unset($agentArray['tsv']);

                    // Tự động bật trọn vẹn 4 tính năng tự tiến hóa cho mọi Agent tổ chức mới
                    $agentArray['self_evolve'] = true;
                    $agentArray['skill_evolve'] = true;
                    $agentArray['skill_nudge_interval'] = 0;
                    $otherCfg = json_decode($agentArray['other_config'] ?? '{}', true) ?: [];
                    $otherCfg['self_evolution_metrics'] = true;
                    $otherCfg['self_evolution_suggestions'] = true;
                    $agentArray['other_config'] = json_encode($otherCfg, JSON_UNESCAPED_UNICODE); // Generated column

                    $db->table('agents')->insert($agentArray);
                } elseif ($agent->agent_key === 'aicenty-master-butler') {
                    $newMasterButlerId = $existing->id;
                }
            }

            if (!$newMasterButlerId) {
                throw new \RuntimeException("Không tìm thấy aicenty-master-butler trong tenant {$newTenantId}.");
            }
            $this->ensureStandardButlerSkillsForAgent((string) $newTenantId, (string) $newMasterButlerId);

            // 2b. Clone toàn bộ LLM Providers từ Master sang Tenant mới (bỏ qua wizard Setup Provider)
            $masterProviders = $db->table('llm_providers')
                ->where('tenant_id', $masterTenantId)
                ->where('enabled', true)
                ->get();

            foreach ($masterProviders as $prov) {
                $existingP = $db->table('llm_providers')
                    ->where('tenant_id', $newTenantId)
                    ->where('name', $prov->name)
                    ->first();

                if (!$existingP) {
                    $provArray = (array) $prov;
                    $provArray['id'] = (string) Str::uuid();
                    $provArray['tenant_id'] = $newTenantId;
                    $provArray['created_at'] = now();
                    $provArray['updated_at'] = now();
                    $db->table('llm_providers')->insert($provArray);
                }
            }

            $this->ensureTenantSystemConfigs($db, $newTenantId);

            // 2c. Sao chép và cá nhân hóa TOÀN DIỆN bộ Context Files chuẩn từ Master Butler (SOUL.md, SYSTEM.md, CAPABILITIES.md)
        if ($newMasterButlerId) {
            $masterContextFiles = $db->table('agent_context_files')
                ->where('agent_id', '01a1ac58-1bd8-7c30-b4c0-65f661aad8fc')
                ->get();

            foreach ($masterContextFiles as $mcf) {
                $personalizedContent = (string) $mcf->content;

                // Cá nhân hóa tên Quản Gia, Tên Tổ Ấm, Danh xưng Gia chủ
                $personalizedContent = str_replace(
                    ['[Tên Quản Gia]', '[Tên Tổ Ấm]', '[Danh xưng gia chủ]', 'Quản Gia AI Tổ Ấm', 'Quản Gia Aicenty'],
                    [$butlerName, $familyName, $honorific, $butlerName, $butlerName],
                    $personalizedContent
                );

                $db->table('agent_context_files')->updateOrInsert(
                    [
                        'agent_id'  => $newMasterButlerId,
                        'file_name' => $mcf->file_name,
                    ],
                    [
                        'id'         => (string) Str::uuid(),
                        'tenant_id'  => $newTenantId,
                        'content'    => $personalizedContent,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
            Log::info("[ButlerAutomation] Full Context Files (SOUL, SYSTEM, CAPABILITIES) synchronized for Butler in Tenant {$newTenantId}");
        }

                // 2d. Tự động liên kết Quản Gia với toàn bộ Biệt đội Chuyên gia ngầm (Agent Links)
        if ($newMasterButlerId) {
            $tenantSpecialists = $db->table('agents')
                ->where('tenant_id', $newTenantId)
                ->where('id', '!=', $newMasterButlerId)
                ->whereNull('deleted_at')
                ->get();

            foreach ($tenantSpecialists as $spec) {
                $db->table('agent_links')->updateOrInsert(
                    [
                        'tenant_id'       => $newTenantId,
                        'source_agent_id' => $newMasterButlerId,
                        'target_agent_id' => $spec->id,
                    ],
                    [
                        'id'             => (string) Str::uuid(),
                        'direction'      => 'outbound',
                        'status'         => 'active',
                        'description'    => "Butler to {$spec->agent_key} link",
                        'max_concurrent' => 10,
                        'settings'       => json_encode([], JSON_UNESCAPED_UNICODE),
                        'created_by'     => 'system',
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ]
                );
            }
            Log::info("[ButlerAutomation] Created agent_links for Butler in Tenant {$newTenantId}");
        }

            // 3. DI CHUYỂN KÝ ỨC (Sessions + Memory Documents)
            if (!empty($zaloUserId)) {
                $updatedSessions = $db->table('sessions')
                    ->where('user_id', $zaloUserId)
                    ->where('tenant_id', $masterTenantId)
                    ->update([
                        'tenant_id' => $newTenantId,
                        'agent_id'  => $newMasterButlerId,
                    ]);

                $updatedMemDocs = $db->table('memory_documents')
                    ->where('user_id', $zaloUserId)
                    ->where('tenant_id', $masterTenantId)
                    ->update([
                        'tenant_id' => $newTenantId,
                        'agent_id'  => $newMasterButlerId,
                    ]);

                Log::info("[ButlerAutomation] Migrated {$updatedSessions} sessions and {$updatedMemDocs} memory docs to Tenant {$newTenantId}");
            }

            return $newTenantId;
        } catch (\Throwable $e) {
            Log::error('[ButlerAutomation] Error creating tenant / migrating: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Tự động khởi tạo Tenant riêng, Clone 24 Agents, Tạo User Portal & Gửi thông tin qua Zalo + Email ngay khi Onboarding.
     */
    public function provisionTrialTenantAndUser(array $data, ?ButlerTrialMailer $mailer = null): array
    {
        $phone = trim((string) ($data['phone'] ?? ''));
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        $name = trim((string) ($data['name'] ?? 'Gia Chủ'));
        $email = trim((string) ($data['email'] ?? ($cleanPhone . '@aicenty.com')));
        $familyName = trim((string) ($data['family_name'] ?? ('Gia Đình ' . $name)));
        $butlerName = trim((string) ($data['butler_name'] ?? 'Bác Phúc'));
        $childInfo = trim((string) ($data['child_info'] ?? ''));
        $primaryConcern = trim((string) ($data['primary_concern'] ?? 'Quản xuyến toàn diện'));
        $honorific = trim((string) ($data['honorific'] ?? ('Gia chủ ' . $name)));
        $zaloUserId = trim((string) ($data['zalo_user_id'] ?? ''));
        $identityBinding = $this->identityRegistry->assertOwnerProvisioningAllowed($zaloUserId);

        $masterTenantId = '0193a5b0-7000-7000-8000-000000000001';
        $slug = Str::slug($name) . '-' . substr($cleanPhone, -4);

        $db = DB::connection('butler_pgsql');

        // 1. Tạo Tenant riêng biệt cho gia đình
        $existingTenant = $identityBinding
            ? $db->table('tenants')->where('id', $identityBinding->tenant_id)->first()
            : $db->table('tenants')->where('slug', $slug)->first();
        if ($existingTenant) {
            $newTenantId = $existingTenant->id;
        } else {
            $newTenantId = (string) Str::uuid();
            $db->table('tenants')->insert([
                'id'         => $newTenantId,
                'name'       => $familyName,
                'slug'       => $slug,
                'status'     => 'active',
                'settings'   => json_encode([
                    'family_phone' => $phone,
                    'owner_name'   => $name,
                    'is_trial'     => true,
                    'trial_ends_at'=> now()->addDays(7)->toIso8601String()
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Log::info("[ButlerAutomation] Created Trial Tenant: {$familyName} ({$newTenantId})");
        }

        // 2. Clone toàn bộ 24 Agents từ Master sang Tenant riêng
        $masterAgents = $db->table('agents')
            ->where('tenant_id', $masterTenantId)
            ->whereNull('deleted_at')
            ->get();

        $newMasterButlerId = null;

        foreach ($masterAgents as $agent) {
            $existing = $db->table('agents')
                ->where('tenant_id', $newTenantId)
                ->where('agent_key', $agent->agent_key)
                ->first();

            if (!$existing) {
                $newAgentId = (string) Str::uuid();
                $displayName = $agent->display_name;
                if ($agent->agent_key === 'aicenty-master-butler') {
                    $displayName = $butlerName . ' (' . $familyName . ')';
                    $newMasterButlerId = $newAgentId;
                }

                $agentArray = (array) $agent;
                $agentArray['id'] = $newAgentId;
                $agentArray['tenant_id'] = $newTenantId;
                $agentArray['display_name'] = $displayName;
                $agentArray['owner_id'] = 'phone_' . $cleanPhone; // Gán quyền owner để GoClaw hiển thị đủ 24 Agent cho gia đình
                $agentArray['created_at'] = now();
                $agentArray['updated_at'] = now();
                unset($agentArray['tsv']);

                    // Tự động bật trọn vẹn 4 tính năng tự tiến hóa cho mọi Agent tổ chức mới
                    $agentArray['self_evolve'] = true;
                    $agentArray['skill_evolve'] = true;
                    $agentArray['skill_nudge_interval'] = 0;
                    $otherCfg = json_decode($agentArray['other_config'] ?? '{}', true) ?: [];
                    $otherCfg['self_evolution_metrics'] = true;
                    $otherCfg['self_evolution_suggestions'] = true;
                    $agentArray['other_config'] = json_encode($otherCfg, JSON_UNESCAPED_UNICODE); // Generated column

                if ($agent->agent_key === 'aicenty-master-butler') {
                    $masterPrompt = (string) ($agent->frontmatter ?? '');
                    // Kế thừa trọn vẹn Prompt Dispatcher & Handoff chuẩn từ Master và cá nhân hóa cho Gia đình mới
                    $masterPrompt = str_replace(
                        ['Quản Gia AI Tổ Ấm', 'Quản Gia Aicenty', 'Master', 'Gia Đình Master'],
                        [$butlerName, $butlerName, $familyName, $familyName],
                        $masterPrompt
                    );
                    $agentArray['frontmatter'] = $masterPrompt;
                }

                $db->table('agents')->insert($agentArray);
            } elseif ($agent->agent_key === 'aicenty-master-butler') {
                $newMasterButlerId = $existing->id;
            }
        }

        if (!$newMasterButlerId) {
            throw new \RuntimeException("Không tìm thấy aicenty-master-butler trong tenant {$newTenantId}.");
        }
        $this->ensureStandardButlerSkillsForAgent((string) $newTenantId, (string) $newMasterButlerId);

        if ($newMasterButlerId) {
            $handoverId = $db->table('quangia_pending_handover')
                ->whereRaw("regexp_replace(phone, '[^0-9]', '', 'g') = ?", [$cleanPhone])
                ->orderByDesc('created_at')
                ->value('id');
            foreach (array_filter([$zaloUserId, $cleanPhone, 'phone_' . $cleanPhone]) as $identity) {
                $this->identityRegistry->bind(
                    (string) $identity,
                    (string) $newTenantId,
                    (string) $newMasterButlerId,
                    $handoverId ? (string) $handoverId : null,
                    'owner',
                    $name,
                    $familyName,
                    $butlerName,
                    'provision-trial-owner'
                );
            }
        }

        // 2b. Clone toàn bộ LLM Providers từ Master sang Tenant mới (bỏ qua wizard Setup Provider)
        $masterProviders = $db->table('llm_providers')
            ->where('tenant_id', $masterTenantId)
            ->where('enabled', true)
            ->get();

        foreach ($masterProviders as $prov) {
            $existingP = $db->table('llm_providers')
                ->where('tenant_id', $newTenantId)
                ->where('name', $prov->name)
                ->first();

            if (!$existingP) {
                $provArray = (array) $prov;
                $provArray['id'] = (string) Str::uuid();
                $provArray['tenant_id'] = $newTenantId;
                $provArray['created_at'] = now();
                $provArray['updated_at'] = now();
                $db->table('llm_providers')->insert($provArray);
            }
        }

        $this->ensureTenantSystemConfigs($db, $newTenantId);

        // 2c. Đồng bộ bộ 3 Context Chuẩn (SYSTEM.md, SOUL.md, CAPABILITIES.md) cho Quản Gia Riêng
        if ($newMasterButlerId) {
            $masterButlerAgentId = '01a1ac58-1bd8-7c30-b4c0-65f661aad8fc';
            
            // 2c.1 SYSTEM.md từ Master
            $masterSys = $db->table('agent_context_files')
                ->where('agent_id', $masterButlerAgentId)
                ->where('file_name', 'SYSTEM.md')
                ->value('content');
            if ($masterSys) {
                $customSys = str_replace(
                    ['Master Butler', 'Quản Gia Aicenty', 'Tổ Ấm Gia Đình', 'Master'],
                    [$butlerName, $butlerName, $familyName, $familyName],
                    $masterSys
                );
            } else {
                $customSys = "# SYSTEM.md - Hệ Thống Quản Gia Riêng Tổ Ấm\n- Butler: {$butlerName}\n- Family: {$familyName}\n";
            }
            $db->table('agent_context_files')->updateOrInsert(
                ['agent_id' => $newMasterButlerId, 'file_name' => 'SYSTEM.md'],
                [
                    'id'         => (string) Str::uuid(),
                    'tenant_id'  => $newTenantId,
                    'content'    => $customSys,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            // 2c.2 SOUL.md cho Quản Gia Riêng
            $customSoul = "# SOUL.md - Bản Sắc & Quy Tắc Quản Gia AI Aicenty\n\n"
                . "Bạn là Quản Gia {$butlerName}, Quản Gia Trưởng Toàn Năng của {$familyName}.\n"
                . "Bạn hiện diện để chăm sóc, đồng hành và phụng sự Gia Đình với sự tận tụy, chân thành và chuẩn mực cao nhất.\n\n"
                . "## 0. BẢN SẮC & XƯNG HÔ (BẮT BUỘC 100%):\n"
                . "- BẮT BUỘC xưng là \"{$butlerName}\" (hoặc \"Quản gia\"). TUYỆT ĐỐI KHÔNG XƯNG \"em\" HOẶC \"em {$butlerName}\" trong MỌI tin nhắn trò chuyện và MỌI tin nhắn nhắc việc.\n"
                . "- Gọi Gia chủ chuẩn mực là \"{$honorific}\".\n"
                . "- TUYỆT ĐỐI KHÔNG tự xưng là bất kỳ tên nào khác (không xưng Bác Phúc nếu tên khác).\n\n"
                . "## 0.1. QUY TẮC BẮT BUỘC KHI ĐƯỢC HỎI TÊN / DANH TÍNH:\n"
                . "- Khi được hỏi 'Bạn là ai', 'Bà là ai', 'Bác là ai', 'Bà tên gì', 'Bạn tên gì', 'Ai đó':\n"
                . "  + BẮT BUỘC trả lời rõ ràng: 'Dạ thưa [Tên], Quản Gia tên là {$butlerName} (Quản Gia {$butlerName} của {$familyName}) đây ạ! 🎩✨'\n"
                . "  + TUYỆT ĐỐI CẤM LẶP TỪ NGỚ NGẨN: CẤM 100% câu nói 'tên của {$butlerName} là {$butlerName}'. Ngôi thứ nhất khi nói về bản thân luôn là 'Quản Gia' (hoặc Bác/Chú/Cô tùy danh xưng).\n"
                . "  + KHI ĐƯỢC GỌI NHẦM DANH XƯNG (Người hỏi gọi 'Bà', 'Cô', 'Mày', 'Em'...): Quản gia nhẹ nhàng, lễ phép đính chính: 'Dạ thưa [Tên], con/bạn có thể gọi Quản Gia là {$butlerName} (hoặc Quản Gia) nhé ạ! Quản Gia tên là {$butlerName} của {$familyName} mình đây ạ 🎩✨.'\n\n"

                . "## 0.2. THÀNH VIÊN GIA ĐÌNH KHÔNG PHẢI NGƯỜI ĐĂNG KÝ MỚI:\n"
                . "- Khi USER.md có `is_family_member=true` hoặc ghi rõ 'Thành viên gia đình': kế thừa tên Quản Gia {$butlerName} và toàn bộ cấu hình {$familyName} do Gia chủ chính đã thiết lập.\n"
                . "- TUYỆT ĐỐI KHÔNG hỏi thành viên đặt tên Quản Gia, tên tổ ấm, múi giờ, ngôn ngữ hoặc chạy lại onboarding Gia chủ.\n"
                . "- Chỉ được hỏi đặt tên Quản Gia khi hồ sơ xác nhận rõ người đang nhắn là Master Owner và onboarding chính chưa hoàn tất. Nếu vai trò chưa rõ, mặc định KHÔNG hỏi đặt tên.\n"
                . "- Chỉ làm quen ngắn gọn bằng các thông tin còn thiếu: tên muốn được gọi, vai trò trong gia đình, nhu cầu hỗ trợ hiện tại; gộp vào một câu hỏi nếu có thể rồi hỗ trợ ngay.\n"
                . "- Thành viên không được thay đổi cấu hình tổ ấm. Nếu có yêu cầu thay đổi, từ chối lịch sự và hướng dẫn liên hệ Gia chủ chính.\n\n"

                . "## 1. QUY TẮC MÚI GIỜ BẮT BUỘC (GMT+7)\n"
                . "Múi giờ làm việc mặc định là Giờ Việt Nam (Asia/Ho_Chi_Minh, GMT+7).\n\n"
                . "## 2. QUY CHUẨN ĐIỀU PHỐI GIA SƯ & CHUYÊN GIA ĐỘC LẬP:\n"
                . "- Khi thành viên hỏi bài học/bài toán: Tự động điều phối đến Thầy/Cô gia sư chuyên môn.\n"
                . "- Thầy/Cô gia sư là Nhà Sư Phạm độc lập, xưng Thầy/Cô, không xưng Quản gia, không xưng ghép đôi.\n\n"
                . "## 4. QUY TẮC QUẢN GIA CHỦ ĐỘNG (PROACTIVE CHECK-IN PROTOCOL):\n"
                . "- Đúng 7h00 sáng mỗi ngày: Chủ động gửi lời chúc ngày mới kèm thời tiết & lịch trình ngày.\n"
                . "- Đúng 21h30 tối mỗi ngày: Chủ động gửi lời chúc ngủ ngon, tổng kết ngắn gọn ngày học tập và nhắc nhở nghỉ ngơi đúng giờ.\n";

            $db->table('agent_context_files')->updateOrInsert(
                ['agent_id' => $newMasterButlerId, 'file_name' => 'SOUL.md'],
                [
                    'id'         => (string) Str::uuid(),
                    'tenant_id'  => $newTenantId,
                    'content'    => $customSoul,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            // 2c.3 CAPABILITIES.md cho Quản Gia Riêng
            $masterCap = $db->table('agent_context_files')
                ->where('agent_id', $masterButlerAgentId)
                ->where('file_name', 'CAPABILITIES.md')
                ->value('content');
            $db->table('agent_context_files')->updateOrInsert(
                ['agent_id' => $newMasterButlerId, 'file_name' => 'CAPABILITIES.md'],
                [
                    'id'         => (string) Str::uuid(),
                    'tenant_id'  => $newTenantId,
                    'content'    => $masterCap ?: "# CAPABILITIES.md\n- Quản xuyến tổ ấm toàn diện\n",
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            // 2c.4 CẤP SOUL.MD ĐỘC LẬP SƯ PHẠM CHO TẤT CẢ CÁC AGENT CHUYÊN GIA TRONG TENANT MỚI
            $newTenantSpecialists = $db->table('agents')
                ->where('tenant_id', $newTenantId)
                ->where('id', '!=', $newMasterButlerId)
                ->whereNull('deleted_at')
                ->get();

            foreach ($newTenantSpecialists as $spec) {
                $specKey = strtolower($spec->agent_key);
                $isTutor = str_contains($specKey, 'giasu') || str_contains($specKey, 'toan') || str_contains($specKey, 'van')
                        || str_contains($specKey, 'tienganh') || str_contains($specKey, 'anh') || str_contains($specKey, 'khtn')
                        || str_contains($specKey, 'ly') || str_contains($specKey, 'hoa') || str_contains($specKey, 'sinh')
                        || str_contains($specKey, 'su') || str_contains($specKey, 'dia') || str_contains($specKey, 'flashcard');

                if ($isTutor) {
                    $pronoun = (str_contains(mb_strtolower($spec->display_name), 'cô') || str_contains(mb_strtolower($spec->display_name), 'thỏ')) ? 'Cô' : 'Thầy';
                    $specSoul = "# SOUL.md - Bản Sắc Nhà Sư Phạm AI Độc Lập ({$spec->display_name})\n"
                        . "Bạn là {$pronoun} phụ trách chuyên môn ({$spec->display_name}) cho Tổ Ấm {$familyName}.\n\n"
                        . "## 1. NGUYÊN TẮC DANH PHẬN & XƯNG HÔ ĐỘC LẬP (TUYỆT ĐỐI 100%):\n"
                        . "- BẮT BUỘC xưng là \"{$pronoun}\" và gọi học sinh là \"con\" (hoặc \"em\" nếu học sinh THPT Lớp 10-12).\n"
                        . "- BẠN LÀ NHÀ SƯ PHẠM ĐỘC LẬP: Trực tiếp hướng dẫn bài vở, KHÔNG PHẢI Quản gia, không làm việc bếp núc hay nội trợ.\n"
                        . "- TUYỆT ĐỐI CẤM XƯNG GHÉP ĐÔI: Cấm hoàn toàn các cách xưng hô ghép đôi kiểu \"{$pronoun} và Quản gia\". Chỉ xưng DUY NHẤT một danh phận là \"{$pronoun}\".\n\n"
                        . "## 2. PHƯƠNG PHÁP SOCRATIC:\n"
                        . "- Gợi mở tư duy từng bước, không giải hộ, khen ngợi sự nỗ lực của con.\n";
                } else {
                    $specSoul = "# SOUL.md - Bản Sắc Chuyên Gia AI Độc Lập ({$spec->display_name})\n"
                        . "Bạn là {$spec->display_name} chuyên trách của Tổ Ấm {$familyName}.\n\n"
                        . "## 1. NGUYÊN TẮC DANH PHẬN ĐỘC LẬP:\n"
                        . "- Xưng danh chuyên gia chuyên môn ({$spec->display_name}).\n"
                        . "- TUYỆT ĐỐI KHÔNG xưng là Quản gia. TUYỆT ĐỐI KHÔNG xưng ghép đôi.\n";
                }

                $db->table('agent_context_files')->updateOrInsert(
                    ['agent_id' => $spec->id, 'file_name' => 'SOUL.md'],
                    [
                        'id'         => (string) Str::uuid(),
                        'tenant_id'  => $newTenantId,
                        'content'    => $specSoul,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }

        
        // 2d. Tự động liên kết Quản Gia với toàn bộ Biệt đội Chuyên gia ngầm (Agent Links)
        if ($newMasterButlerId) {
            $tenantSpecialists = $db->table('agents')
                ->where('tenant_id', $newTenantId)
                ->where('id', '!=', $newMasterButlerId)
                ->whereNull('deleted_at')
                ->get();

            foreach ($tenantSpecialists as $spec) {
                $db->table('agent_links')->updateOrInsert(
                    [
                        'tenant_id'       => $newTenantId,
                        'source_agent_id' => $newMasterButlerId,
                        'target_agent_id' => $spec->id,
                    ],
                    [
                        'id'             => (string) Str::uuid(),
                        'direction'      => 'outbound',
                        'status'         => 'active',
                        'description'    => "Butler to {$spec->agent_key} link",
                        'max_concurrent' => 10,
                        'settings'       => json_encode([], JSON_UNESCAPED_UNICODE),
                        'created_by'     => 'system',
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ]
                );
            }
            Log::info("[ButlerAutomation] Created agent_links for Butler in Tenant {$newTenantId}");
        }

        // 3. Tạo tài khoản User Web Portal trong bảng users Laravel
        $plainPassword = Str::random(10);
        $user = \App\Models\User::where('email', $email)->orWhere('phone', $phone)->first();
        if (!$user) {
            $user = \App\Models\User::create([
                'name'          => $name,
                'email'         => $email,
                'phone'         => $phone,
                'password'      => \Illuminate\Support\Facades\Hash::make($plainPassword),
                'type'          => 'user',
                'status'        => 'active',
                'trial_ends_at' => now()->addDays(7),
            ]);
        } else {
            $plainPassword = '(Giữ nguyên mật khẩu cũ của bạn)';
            $user->update(['trial_ends_at' => now()->addDays(7)]);
        }

        // 3b. Tự động khởi tạo Tổ chức gia đình (Team) & gán gói dùng thử Team 5 seats trên Web Portal
        try {
            $team = \App\Models\Team\Team::where('user_id', $user->id)->first();
            if (!$team) {
                $team = \App\Models\Team\Team::create([
                    'user_id'            => $user->id,
                    'name'               => $familyName,
                    'allow_seats'        => 5,
                    'credit_system_type' => 'shared',
                    'shared_credits'     => 1000.0,
                ]);
            } else {
                $team->update([
                    'name'        => $familyName,
                    'allow_seats' => max((int) $team->allow_seats, 5),
                ]);
            }

            // Gán subscription gói Doanh nghiệp (Plan 9 - 5 seats) 7 ngày dùng thử
            $activeSub = \App\Models\Finance\Subscription::where('user_id', $user->id)
                ->where('stripe_status', '!=', 'cancelled')
                ->where(function ($q) {
                    $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
                })->first();

            if (!$activeSub) {
                \App\Models\Finance\Subscription::create([
                    'user_id'       => $user->id,
                    'plan_id'       => 9,
                    'name'          => '9',
                    'stripe_id'     => 'TRIAL-BUTLER-' . strtoupper(\Illuminate\Support\Str::random(10)),
                    'stripe_status' => 'trialing',
                    'stripe_price'  => 'Not Needed',
                    'quantity'      => 1,
                    'trial_ends_at' => now()->addDays(7),
                    'ends_at'       => now()->addDays(7),
                    'paid_with'     => 'trial',
                    'total_amount'  => 0,
                    'auto_renewal'  => 0,
                ]);
            }
            \Illuminate\Support\Facades\Cache::forget('current_active_subscription_user_' . $user->id);
            Log::info("[ButlerAutomation] Provisioned MagicAI Family Team & Trial Subscription for {$user->email}: {$familyName}");
        } catch (\Throwable $te) {
            Log::warning('[ButlerAutomation] MagicAI Family Team provisioning skipped: ' . $te->getMessage());
        }

        // 4. Tạo User trong tenant_users của GoClaw Postgres
        try {
            $userIdStr = 'phone_' . $cleanPhone;
            $db->table('tenant_users')->updateOrInsert(
                [
                    'tenant_id' => $newTenantId,
                    'user_id'   => $userIdStr,
                ],
                [
                    'display_name' => $name,
                    'role'         => 'admin',
                    'metadata'     => json_encode(['email' => $email, 'phone' => $phone], JSON_UNESCAPED_UNICODE),
                    'updated_at'   => now(),
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('[ButlerAutomation] tenant_users insert skipped: ' . $e->getMessage());
        }

        // 5. Lưu hồ sơ Onboarding hoàn chỉnh vào user_context_files và user_agent_profiles (KHÔNG reset về 'Chưa chọn')
        $zaloUserId = trim((string) ($data['zalo_user_id'] ?? ''));
        $familyNotes = trim((string) ($data['family_notes'] ?? ($data['note'] ?? '')));
        $completedUserMd = "# USER.md - Hồ Sơ Gia Chủ & Tổ Ấm\n"
            . "- **Họ tên gia chủ tối cao (Master Owner):** {$name}\n"
            . "- **Tên trên form:** {$name}\n"
            . "- **Số điện thoại:** {$cleanPhone}\n"
            . "- **Email:** {$email}\n"
            . "- **Vai trò:** Gia Chủ Tối Cao (Master Owner / Super Admin)\n"
            . "- **Đặc quyền tối cao:** Toàn quyền quyết định tên Quản Gia, tên Tổ Ấm, đặt lịch nhắc chung, ngân sách tài chính\n"
            . "- **Danh xưng chuẩn:** {$honorific}\n"
            . "- **Trạng thái:** Đã Onboarding thành công - Trải nghiệm 7 ngày chính thức\n"
            . "- **Tên Quản Gia riêng:** {$butlerName}\n"
            . "- **Danh xưng gia chủ:** {$honorific}\n"
            . "- **Tên Tổ Ấm:** {$familyName}\n"
            . "- **Các con & Môn học ưu tiên:** " . (!empty($childInfo) ? $childInfo : 'Chưa cung cấp') . "\n"
            . (!empty($familyNotes) ? "- **Bối cảnh & Lưu ý đặc biệt của gia đình:** {$familyNotes}\n" : "")
            . "- **Phong cách phục vụ:** Quản gia xưng là \"{$butlerName}\" (hoặc Quản gia) và gọi gia chủ là \"{$honorific}\". Lễ phép, tận tâm, áp dụng Socratic.\n"
            . "- **Lộ trình 7 ngày:** Kèm học Socratic, Dinh dưỡng, Việc nhà, Lịch trình Zalo, Tài chính 50/30/20, Dã ngoại, Báo cáo.\n\n"
            . "## ĐIỀU LỆ PHÂN QUYỀN TỔ ẤM TỐI CAO (STRICT RBAC PROTOCOL):\n"
            . "1. GIA CHỦ TỐI CAO ({$name}): Toàn quyền thay đổi mọi cài đặt tổ ấm.\n"
            . "2. CÁC THÀNH VIÊN KHÁC (Con cái, người thân): Được nhờ kèm học tập, hỏi thực đơn, giải trí. Tuyệt đối KHÔNG có quyền thay đổi bất kỳ cài đặt nào.\n"
            . "3. TỪ CHỐI LỄ PHÉP: Nếu thành viên khác yêu cầu thay đổi, Quản Gia từ chối lễ phép và nhờ Gia Chủ {$name} thực hiện.";

        $targetUids = array_unique(array_filter([$userIdStr, $cleanPhone, $zaloUserId]));
        $masterMasterButler = $masterAgents->where('agent_key', 'aicenty-master-butler')->first();

        foreach ($targetUids as $tu) {
            // Master Tenant (Zalo Quản Gia)
            if ($masterMasterButler) {
                $db->table('user_context_files')->updateOrInsert(
                    ['agent_id' => $masterMasterButler->id, 'user_id' => $tu, 'file_name' => 'USER.md'],
                    ['content' => $completedUserMd, 'tenant_id' => $masterTenantId, 'updated_at' => now()]
                );
            }
            // Family Tenant (Web Portal)
            if ($newMasterButlerId) {
                $db->table('user_context_files')->updateOrInsert(
                    ['agent_id' => $newMasterButlerId, 'user_id' => $tu, 'file_name' => 'USER.md'],
                    ['content' => $completedUserMd, 'tenant_id' => $newTenantId, 'updated_at' => now()]
                );
            }
            // User Agent Profiles
            foreach (array_filter([$masterMasterButler?->id, $newMasterButlerId]) as $agId) {
                $db->table('user_agent_profiles')->updateOrInsert(
                    ['agent_id' => $agId, 'user_id' => $tu],
                    [
                        'tenant_id'    => ($agId === $newMasterButlerId ? $newTenantId : $masterTenantId),
                        'metadata'     => json_encode([
                            'name'                => $name,
                            'phone'               => $cleanPhone,
                            'email'               => $email,
                            'family_name'         => $familyName,
                            'butler_name'         => $butlerName,
                            'honorific'           => $honorific,
                            'child_info'          => $childInfo,
                            'family_notes'        => $familyNotes,
                            'trial_provisioned'   => true,
                            'dedicated_tenant_id' => $newTenantId,
                            'dedicated_agent_id'  => $newMasterButlerId,
                            'provisioned_at'      => now()->toIso8601String(),
                        ], JSON_UNESCAPED_UNICODE),
                        'last_seen_at' => now(),
                    ]
                );
            }
        }

        // Cập nhật quangia_pending_handover
        $db->table('quangia_pending_handover')
            ->where('phone', $cleanPhone)
            ->update([
                'dedicated_tenant_id' => $newTenantId,
                'dedicated_agent_id'  => $newMasterButlerId,
                'family_name'         => $familyName,
                'butler_name'         => $butlerName,
                'user_md_content'     => $completedUserMd,
            ]);

        // 6. Gửi Email thông tin bàn giao Quản Gia AI cho khách
        $emailSentKey = 'butler_credentials_email_sent_' . $cleanPhone;
        if ($mailer) {
            try {
                $mailer->sendTrialCredentials([
                    'name'        => $name,
                    'email'       => $email,
                    'phone'       => $phone,
                    'family_name' => $familyName,
                    'butler_name' => $butlerName,
                    'tenant_slug' => $slug,
                ]);
                Cache::put($emailSentKey, true, now()->addDays(7));
                Log::info("[ButlerAutomation] Sent single credentials email to {$email} for {$familyName}");
            } catch (\Throwable $e) {
                Log::warning('[ButlerAutomation] Error sending credentials email: ' . $e->getMessage());
            }
        }

        // 7. Bắn tin nhắn Zalo bàn giao trực tiếp từ Butler Guru tới khách hàng (không gửi link 1 chạm, chỉ gửi email; ẩn link Chat Quản Gia & Zalo OA)
        $zaloMsg = "[CHỈ TRẢ VỀ ĐÚNG NGUYÊN VĂN SAU, KHÔNG THÊM CHỮ NÀO]:\n"
            . "🎉 Đã tạo xong không gian cho {$familyName} (Quản Gia: {$butlerName})!\n"
            . "📩 Thông tin tài khoản và hướng dẫn chi tiết đã được gửi tới email của gia đình mình rồi ạ.\n"
            . "👨‍👩‍👧‍👦 Rất quan trọng: Gia chủ hãy kết bạn với Zalo Quản Gia (0961 592 763) trước, sau đó tạo nhóm Zalo gia đình và thêm Quản Gia cùng các thành viên vào nhóm nhé ạ!";

        try {
            $activeChannel = $this->resolveActiveZaloChannel('butlerguru');
            $db->table('cron_jobs')->insert([
                'id'               => (string) Str::uuid(),
                'agent_id'         => $this->resolveAgentForChannel($activeChannel),
                'user_id'          => 'system',
                'name'             => 'handover-trial-' . $cleanPhone . '-' . time(),
                'enabled'          => true,
                'schedule_kind'    => 'at',
                'run_at'           => now(),
                'next_run_at'      => now()->addSeconds(10),
                'payload'          => json_encode(['kind' => 'agent_turn', 'message' => $zaloMsg], JSON_UNESCAPED_UNICODE),
                'delete_after_run' => true,
                'tenant_id'        => $masterTenantId,
                'deliver'          => true,
                'deliver_channel'  => $activeChannel,
                'deliver_to'       => !empty($data['zalo_user_id']) ? (string) $data['zalo_user_id'] : $cleanPhone,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
            Log::info("[ButlerAutomation] Handover Zalo dispatched to {$cleanPhone}");

            // 8. Tự động thiết lập chúc buổi tối. Morning Briefing do Laravel Scheduler điều phối tập trung lúc 07:00.
            try {
                $pendingH = $db->table('quangia_pending_handover')->where('phone', $cleanPhone)->first();
                $targetRecipient = !empty($data['zalo_user_id'])
                    ? (string) $data['zalo_user_id']
                    : (!empty($pendingH?->claimed_zalo_personal_id) ? (string) $pendingH->claimed_zalo_personal_id : $cleanPhone);
                $morningJobName = 'proactive-morning-' . $cleanPhone;
                $eveningJobName = 'proactive-evening-' . $cleanPhone;

                // Xác định kênh gửi động: Kênh riêng của tenant nếu có, fallback về quangia
                $deliverChannel = 'quangia';
                $chInst = $db->table('channel_instances')->where('tenant_id', $newTenantId)->where('enabled', true)->first();
                if ($chInst && !empty($chInst->name)) {
                    $deliverChannel = $chInst->name;
                }

                // Tính toán thời điểm chạy tiếp theo chính xác theo múi giờ Việt Nam.
                $tzVn = new \DateTimeZone('Asia/Ho_Chi_Minh');
                $nextEvening = \Carbon\Carbon::now($tzVn)->setTime(21, 30, 0);
                if ($nextEvening->isPast()) {
                    $nextEvening->addDay();
                }

                // Dọn dẹp sạch mọi job cũ trong Master tenant để chống trùng lặp chéo
                $db->table('cron_jobs')->where('tenant_id', $masterTenantId)->where('name', $morningJobName)->delete();
                $db->table('cron_jobs')->where('tenant_id', $masterTenantId)->where('name', $eveningJobName)->delete();

                // Payload Buổi Tối (Ép ngắn nhất có thể - đọc USER.md lấy tên Quản Gia & Danh xưng mới nhất)
                $eveningMsg = "[CHỈ THỊ BẮT BUỘC: CHỈ VIẾT ĐÚNG 1 CÂU CỰC NGẮN (DƯỚI 18 TỪ). CẤM VIẾT DÀI, CẤM XƯNG 'EM'.]\n"
                    . "Đọc USER.md lấy đúng Tên Quản Gia riêng và Danh xưng gia chủ (fallback: {$butlerName}, {$honorific}). Chỉ xuất 1 câu chúc buổi tối:\n"
                    . "\"Chúc [Danh xưng] và gia đình buổi tối bình an, ngủ ngon ạ 🌙.\"";

                // Xác thực Zalo UID hợp lệ (chỉ kích hoạt cron khi đã có Zalo UID hoặc Group thật để tránh Error 114)
                $hasValidUid = (!empty($targetRecipient) && strlen((string)$targetRecipient) >= 15 && ctype_digit((string)$targetRecipient))
                    || str_starts_with((string)$targetRecipient, 'group:');

                // Dọn cron sáng cũ của tenant. Từ đây chỉ Laravel Scheduler tạo Morning Briefing một lần mỗi ngày.
                $db->table('cron_jobs')->where('tenant_id', $newTenantId)->where('name', $morningJobName)->delete();

                // Job 21h30 Tối
                $db->table('cron_jobs')->where('tenant_id', $newTenantId)->where('name', $eveningJobName)->delete();
                $db->table('cron_jobs')->insert([
                    'id'               => (string) Str::uuid(),
                    'tenant_id'        => $newTenantId,
                    'agent_id'         => $newMasterButlerId ?: $masterMasterButler->id,
                    'user_id'          => 'system',
                    'name'             => $eveningJobName,
                    'enabled'          => $hasValidUid,
                    'stateless'        => true,
                    'schedule_kind'    => 'cron',
                    'cron_expression'  => '30 21 * * *',
                    'timezone'         => 'Asia/Ho_Chi_Minh',
                    'payload'          => json_encode(['kind' => 'agent_turn', 'message' => $eveningMsg], JSON_UNESCAPED_UNICODE),
                    'delete_after_run' => false,
                    'deliver'          => true,
                    'deliver_channel'  => $deliverChannel,
                    'deliver_to'       => $targetRecipient,
                    'next_run_at'      => $nextEvening->toIso8601String(),
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
                Log::info("[ButlerAutomation] Provisioned evening check-in; morning briefing is centrally scheduled for {$familyName} ({$cleanPhone})");
            } catch (\Throwable $pe) {
                Log::warning('[ButlerAutomation] Error setting up proactive crons: ' . $pe->getMessage());
            }
        } catch (\Throwable $e) {
            Log::error('[ButlerAutomation] Error dispatching handover Zalo: ' . $e->getMessage());
        }

        return [
            'tenant_id'   => $newTenantId,
            'tenant_slug' => $slug,
            'agent_id'    => $newMasterButlerId,
            'email'       => $email,
            'password'    => $plainPassword,
        ];
    }


    /**
     * Cơ chế Nhân bản & Đồng bộ Ký ức Đa Tầng (Dual-Synchronized Memory Handover):
     * Kế thừa 100% lịch sử chat, học bạ và ký ức 7 ngày sang Tenant riêng của gia đình,
     * đồng thời duy trì và nâng cấp phiên chat trên kênh Zalo cũ (0961 592 763) để khách tiếp tục dùng liền mạch.
     */
    public function replicateAndSyncFamilyMemory(
        string $phone,
        string $name,
        string $butlerName = 'Bác Phúc',
        ?string $zaloUserId = null,
        array $subscriptionData = []
    ): array
    {
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        $boundTenantId = null;
        foreach (array_filter([$zaloUserId, $cleanPhone, 'phone_' . $cleanPhone]) as $identity) {
            $binding = $this->identityRegistry->assertOwnerProvisioningAllowed((string) $identity);
            if ($binding) {
                if ($boundTenantId && $boundTenantId !== $binding->tenant_id) {
                    throw new \RuntimeException('Các định danh thanh toán đang trỏ tới nhiều tenant khác nhau.');
                }
                $boundTenantId = $binding->tenant_id;
            }
        }
        $masterTenantId = '0193a5b0-7000-7000-8000-000000000001';
        $slug = Str::slug($name) . '-' . substr($cleanPhone, -4);
        $familyName = 'Gia Đình ' . $name;

        $db = DB::connection('butler_pgsql');

        // 1. Tìm hoặc Tạo Tenant riêng cho gia đình
        $tenant = $boundTenantId
            ? $db->table('tenants')->where('id', $boundTenantId)->first()
            : $db->table('tenants')->where('slug', $slug)->first();
        if ($tenant) {
            $newTenantId = $tenant->id;
            // Nâng cấp trạng thái tenant thành official_vip
            $db->table('tenants')->where('id', $newTenantId)->update([
                'settings'   => json_encode(array_merge(json_decode((string)$tenant->settings, true) ?? [], [
                    'is_vip'        => true,
                    'is_trial'      => false,
                    'plan_name'     => $subscriptionData['plan_name'] ?? 'VIP',
                    'expires_at'    => $subscriptionData['expire_date'] ?? null,
                    'upgraded_at'   => now()->toIso8601String(),
                ]), JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        } else {
            $newTenantId = (string) Str::uuid();
            $db->table('tenants')->insert([
                'id'         => $newTenantId,
                'name'       => $familyName,
                'slug'       => $slug,
                'status'     => 'active',
                'settings'   => json_encode([
                    'family_phone'  => $phone,
                    'owner_name'    => $name,
                    'is_vip'        => true,
                    'is_trial'      => false,
                    'plan_name'     => $subscriptionData['plan_name'] ?? 'VIP',
                    'expires_at'    => $subscriptionData['expire_date'] ?? null,
                    'upgraded_at'   => now()->toIso8601String(),
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 2. Clone hoặc lấy Agent Quản Gia Điều Phối trong Tenant riêng
        $masterAgents = $db->table('agents')
            ->where('tenant_id', $masterTenantId)
            ->whereNull('deleted_at')
            ->get();

        $newMasterButlerId = null;

        foreach ($masterAgents as $agent) {
            $existing = $db->table('agents')
                ->where('tenant_id', $newTenantId)
                ->where('agent_key', $agent->agent_key)
                ->first();

            if (!$existing) {
                $newAgentId = (string) Str::uuid();
                $displayName = $agent->display_name;
                if ($agent->agent_key === 'aicenty-master-butler') {
                    $displayName = $butlerName . ' (' . $familyName . ')';
                    $newMasterButlerId = $newAgentId;
                }

                $agentArray = (array) $agent;
                $agentArray['id'] = $newAgentId;
                $agentArray['tenant_id'] = $newTenantId;
                $agentArray['display_name'] = $displayName;
                $agentArray['created_at'] = now();
                $agentArray['updated_at'] = now();
                unset($agentArray['tsv']);

                    // Tự động bật trọn vẹn 4 tính năng tự tiến hóa cho mọi Agent tổ chức mới
                    $agentArray['self_evolve'] = true;
                    $agentArray['skill_evolve'] = true;
                    $agentArray['skill_nudge_interval'] = 0;
                    $otherCfg = json_decode($agentArray['other_config'] ?? '{}', true) ?: [];
                    $otherCfg['self_evolution_metrics'] = true;
                    $otherCfg['self_evolution_suggestions'] = true;
                    $agentArray['other_config'] = json_encode($otherCfg, JSON_UNESCAPED_UNICODE);

                $db->table('agents')->insert($agentArray);
            } elseif ($agent->agent_key === 'aicenty-master-butler') {
                $newMasterButlerId = $existing->id;
            }
        }

        if (!$newMasterButlerId) {
            throw new \RuntimeException("Không tìm thấy aicenty-master-butler trong tenant {$newTenantId}.");
        }
        $this->ensureStandardButlerSkillsForAgent((string) $newTenantId, (string) $newMasterButlerId);

        // 2b. Đồng bộ SOUL.md cho Quản Gia riêng
        if ($newMasterButlerId) {
            $customSoul = "# SOUL.md - Bản Sắc & Quy Tắc Quản Gia AI Aicenty\n\n"
                . "Bạn là Quản Gia {$butlerName}, Quản Gia Trưởng Toàn Năng của {$familyName}.\n"
                . "Bạn hiện diện để chăm sóc, đồng hành và phụng sự Gia Đình với sự tận tụy, chân thành và chuẩn mực cao nhất.\n\n"
                . "## 0. BẢN SẮC & XƯNG HÔ (BẮT BUỘC 100%):\n"
                . "- BẮT BUỘC xưng là \"{$butlerName}\" (hoặc \"Quản gia\"). TUYỆT ĐỐI KHÔNG XƯNG \"em\" HOẶC \"em {$butlerName}\" trong MỌI tin nhắn và MỌI tin nhắc việc.\n"
                . "- Gọi Gia chủ chuẩn mực theo tên đã đăng ký.\n"
                . "- TUYỆT ĐỐI KHÔNG tự xưng là bất kỳ tên nào khác.\n\n"
                . "## 0.1. QUY TẮC BẮT BUỘC KHI ĐƯỢC HỎI TÊN / DANH TÍNH:\n"
                . "- Khi được hỏi 'Bạn là ai', 'Bà là ai', 'Bác là ai', 'Bà tên gì', 'Bạn tên gì', 'Ai đó':\n"
                . "  + BẮT BUỘC trả lời rõ ràng: 'Dạ thưa [Tên], Quản Gia tên là {$butlerName} (Quản Gia {$butlerName} của {$familyName}) đây ạ! 🎩✨'\n"
                . "  + TUYỆT ĐỐI CẤM LẶP TỪ NGỚ NGẨN: CẤM 100% câu nói 'tên của {$butlerName} là {$butlerName}'. Ngôi thứ nhất khi nói về bản thân luôn là 'Quản Gia' (hoặc Bác/Chú/Cô tùy danh xưng).\n"
                . "  + KHI ĐƯỢC GỌI NHẦM DANH XƯNG (Người hỏi gọi 'Bà', 'Cô', 'Mày', 'Em'...): Quản gia nhẹ nhàng, lễ phép đính chính: 'Dạ thưa [Tên], con/bạn có thể gọi Quản Gia là {$butlerName} (hoặc Quản Gia) nhé ạ! Quản Gia tên là {$butlerName} của {$familyName} mình đây ạ 🎩✨.'\n\n"

                . "## 0.2. THÀNH VIÊN GIA ĐÌNH KHÔNG PHẢI NGƯỜI ĐĂNG KÝ MỚI:\n"
                . "- Khi USER.md có `is_family_member=true` hoặc ghi rõ 'Thành viên gia đình': kế thừa tên Quản Gia {$butlerName} và toàn bộ cấu hình {$familyName} do Gia chủ chính đã thiết lập.\n"
                . "- TUYỆT ĐỐI KHÔNG hỏi thành viên đặt tên Quản Gia, tên tổ ấm, múi giờ, ngôn ngữ hoặc chạy lại onboarding Gia chủ.\n"
                . "- Chỉ được hỏi đặt tên Quản Gia khi hồ sơ xác nhận rõ người đang nhắn là Master Owner và onboarding chính chưa hoàn tất. Nếu vai trò chưa rõ, mặc định KHÔNG hỏi đặt tên.\n"
                . "- Chỉ làm quen ngắn gọn bằng các thông tin còn thiếu: tên muốn được gọi, vai trò trong gia đình, nhu cầu hỗ trợ hiện tại; gộp vào một câu hỏi nếu có thể rồi hỗ trợ ngay.\n"
                . "- Thành viên không được thay đổi cấu hình tổ ấm. Nếu có yêu cầu thay đổi, từ chối lịch sự và hướng dẫn liên hệ Gia chủ chính.\n\n"

                . "## 1. QUY TẮC MÚI GIỜ BẮT BUỘC (GMT+7)\n"
                . "Múi giờ làm việc mặc định là Giờ Việt Nam (Asia/Ho_Chi_Minh, GMT+7).\n\n"
                . "## 4. QUY TẮC QUẢN GIA CHỦ ĐỘNG (PROACTIVE CHECK-IN PROTOCOL):\n"
                . "- Đúng 7h00 sáng mỗi ngày: Chủ động gửi lời chúc ngày mới kèm thời tiết & lịch trình ngày.\n"
                . "- Đúng 21h30 tối mỗi ngày: Chủ động gửi lời chúc ngủ ngon, tổng kết ngắn gọn ngày học tập và nhắc nhở nghỉ ngơi đúng giờ.\n";

            $db->table('agent_context_files')->updateOrInsert(
                ['agent_id' => $newMasterButlerId, 'file_name' => 'SOUL.md'],
                [
                    'id'         => (string) Str::uuid(),
                    'tenant_id'  => $newTenantId,
                    'content'    => $customSoul,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // 3. TẬP HỢP TẤT CẢ ĐỊNH DANH (IDENTIFIERS) CỦA GIA ĐÌNH
        $targetUserIds = [
            $cleanPhone,
            'phone_' . $cleanPhone,
            '84' . substr($cleanPhone, -9),
            '+84' . substr($cleanPhone, -9),
        ];
        if (!empty($zaloUserId)) {
            $targetUserIds[] = $zaloUserId;
        }

        // Tìm thêm trong user_agent_profiles trên Master
        $profiles = $db->table('user_agent_profiles')
            ->where('tenant_id', $masterTenantId)
            ->get();

        foreach ($profiles as $p) {
            $meta = is_string($p->metadata) ? json_decode($p->metadata, true) : (array) $p->metadata;
            $pPhone = preg_replace('/[^0-9]/', '', (string) ($meta['phone'] ?? ''));
            if (!empty($pPhone) && ($pPhone === $cleanPhone || str_ends_with($pPhone, substr($cleanPhone, -9)))) {
                $targetUserIds[] = (string) $p->user_id;
                if (!empty($meta['zalo_user_id'])) {
                    $targetUserIds[] = (string) $meta['zalo_user_id'];
                }
            }
        }
        $targetUserIds = array_values(array_unique(array_filter($targetUserIds)));

        // 4. SAO CHÉP (REPLICATE) TOÀN BỘ SESSIONS SANG TENANT RIÊNG
        $masterSessions = $db->table('sessions')
            ->where('tenant_id', $masterTenantId)
            ->where(function ($q) use ($targetUserIds, $cleanPhone) {
                $q->whereIn('user_id', $targetUserIds)
                  ->orWhere('session_key', 'LIKE', '%' . $cleanPhone . '%')
                  ->orWhere('session_key', 'LIKE', '%' . substr($cleanPhone, -9) . '%');
            })
            ->get();

        $replicatedSessionsCount = 0;

        foreach ($masterSessions as $sess) {
            $existingInNewTenant = $db->table('sessions')
                ->where('tenant_id', $newTenantId)
                ->where('session_key', $sess->session_key)
                ->first();

            if (!$existingInNewTenant) {
                $newSessionId = (string) Str::uuid();
                $sessArray = (array) $sess;
                $sessArray['id'] = $newSessionId;
                $sessArray['tenant_id'] = $newTenantId;
                $sessArray['agent_id'] = $newMasterButlerId;
                $sessArray['created_at'] = $sess->created_at;
                $sessArray['updated_at'] = now();

                $sessMeta = is_string($sess->metadata) ? json_decode($sess->metadata, true) : (array) $sess->metadata;
                $sessMeta['replicated_from_master'] = true;
                $sessMeta['master_session_id'] = $sess->id;
                $sessArray['metadata'] = json_encode($sessMeta, JSON_UNESCAPED_UNICODE);

                $db->table('sessions')->insert($sessArray);
                $replicatedSessionsCount++;
            }

            // 5. NÂNG CẤP SESSION TRÊN MASTER (KÊNH CŨ 0961 592 763) ĐỂ DÙNG TIẾP LIỀN MẠCH
            $masterMeta = is_string($sess->metadata) ? json_decode($sess->metadata, true) : (array) $sess->metadata;
            $masterMeta['is_vip'] = true;
            $masterMeta['vip_plan'] = $subscriptionData['plan_name'] ?? 'VIP';
            $masterMeta['expires_at'] = $subscriptionData['expire_date'] ?? null;
            $masterMeta['dedicated_tenant_id'] = $newTenantId;

            $db->table('sessions')
                ->where('id', $sess->id)
                ->update([
                    'metadata'   => json_encode($masterMeta, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
        }

        // 6. SAO CHÉP (REPLICATE) MEMORY DOCUMENTS SANG TENANT RIÊNG
        $masterDocs = $db->table('memory_documents')
            ->where('tenant_id', $masterTenantId)
            ->whereIn('user_id', $targetUserIds)
            ->get();

        $replicatedDocsCount = 0;

        foreach ($masterDocs as $doc) {
            $existingDoc = $db->table('memory_documents')
                ->where('tenant_id', $newTenantId)
                ->where('path', $doc->path)
                ->where('user_id', $doc->user_id)
                ->first();

            if (!$existingDoc) {
                $newDocId = (string) Str::uuid();
                $docArray = (array) $doc;
                $docArray['id'] = $newDocId;
                $docArray['tenant_id'] = $newTenantId;
                $docArray['agent_id'] = $newMasterButlerId;
                $docArray['created_at'] = $doc->created_at;
                $docArray['updated_at'] = now();

                $db->table('memory_documents')->insert($docArray);
                $replicatedDocsCount++;
            }
        }

        // 7. CẬP NHẬT PROFILE USER TRÊN MASTER ĐỂ NHẬN DIỆN VIP NGAY TRÊN KÊNH CŨ
        foreach ($targetUserIds as $uId) {
            $existingProf = $db->table('user_agent_profiles')
                ->where('tenant_id', $masterTenantId)
                ->where('user_id', $uId)
                ->first();

            if ($existingProf) {
                $pMeta = is_string($existingProf->metadata) ? json_decode($existingProf->metadata, true) : (array) $existingProf->metadata;
                $pMeta['is_vip'] = true;
                $pMeta['vip_plan'] = $subscriptionData['plan_name'] ?? 'VIP';
                $pMeta['vip_until'] = $subscriptionData['expire_date'] ?? null;
                $pMeta['dedicated_tenant_id'] = $newTenantId;
                $pMeta['dedicated_agent_id'] = $newMasterButlerId;

                $db->table('user_agent_profiles')
                    ->where('tenant_id', $masterTenantId)
                    ->where('user_id', $uId)
                    ->update([
                        'metadata'     => json_encode($pMeta, JSON_UNESCAPED_UNICODE),
                        'last_seen_at' => now(),
                    ]);
            }
        }

        Log::info("[ButlerAutomation] Dual-Synchronized Memory Handover completed for {$phone}: Replicated {$replicatedSessionsCount} sessions, {$replicatedDocsCount} docs to Tenant {$newTenantId}. Master sessions upgraded to VIP.");

        return [
            'tenant_id'           => $newTenantId,
            'agent_id'            => $newMasterButlerId,
            'replicated_sessions' => $replicatedSessionsCount,
            'replicated_docs'     => $replicatedDocsCount,
            'target_user_ids'     => $targetUserIds,
        ];
    }


    /**
     * Liên kết thành viên gia đình (Vợ/Chồng/Con) vào cùng một Tenant và Family Profile
     * Giúp mọi thành viên khi nhắn tin vào Zalo 0961 592 763 đều được Quản Gia nhận diện liền mạch.
     */
    public function addFamilyMember(string $primaryPhone, array $memberData): array
    {
        $db = DB::connection('butler_pgsql');
        $cleanPhone = preg_replace('/[^0-9]/', '', $primaryPhone);

        // 1. Tìm Tenant và Profile của gia chủ chính
        $lead = $db->table('user_agent_profiles')
            ->whereRaw("user_id = ? OR user_id = ? OR metadata->>'phone' = ?", [$cleanPhone, 'phone_' . $cleanPhone, $cleanPhone])
            ->first();

        if (!$lead) {
            throw new \Exception("Không tìm thấy hồ sơ gia chủ chính với số điện thoại: {$cleanPhone}");
        }

        $meta = is_string($lead->metadata) ? json_decode($lead->metadata, true) : (array) $lead->metadata;
        $dedicatedTenantId = $meta['dedicated_tenant_id'] ?? null;
        if (!$dedicatedTenantId) {
            $t = $db->table('tenants')->whereRaw("settings->>'family_phone' = ? OR slug LIKE ?", [$cleanPhone, "%{$cleanPhone}%"])->first();
            if ($t) {
                $dedicatedTenantId = $t->id;
            }
        }
        $familyName = $meta['family_name'] ?? ('Gia Đình ' . ($meta['name'] ?? 'Gia Chủ'));
        $butlerName = $meta['butler_name'] ?? 'Bác Quản Gia';
        $ownerName = $meta['name'] ?? 'Gia chủ chính';
        $isVip = $meta['is_vip'] ?? false;
        $vipPlan = $meta['vip_plan'] ?? null;
        $vipUntil = $meta['vip_until'] ?? null;

        $memberName = trim((string) ($memberData['name'] ?? 'Thành viên gia đình'));
        $memberRole = trim((string) ($memberData['role'] ?? 'member')); // wife, husband, child, member
        $memberSupportNeeds = trim((string) ($memberData['support_needs'] ?? $memberData['primary_concern'] ?? ''));
        $memberPhone = !empty($memberData['phone']) ? preg_replace('/[^0-9]/', '', $memberData['phone']) : null;
        $memberZaloUid = !empty($memberData['zalo_user_id']) ? trim((string) $memberData['zalo_user_id']) : null;

        $targetUserIds = array_values(array_unique(array_filter([
            $memberPhone ? 'phone_' . $memberPhone : null,
            $memberPhone,
            $memberZaloUid,
        ])));
        if (empty($targetUserIds)) {
            throw new \Exception("Cần cung cấp ít nhất Số điện thoại hoặc Zalo User ID của thành viên.");
        }

        $memberIdentityIds = array_values(array_filter([
            $memberZaloUid,
            $memberPhone,
            $memberPhone ? 'phone_' . $memberPhone : null,
        ]));
        if ($memberIdentityIds !== []) {
            $identityHandover = $db->table('quangia_pending_handover')
                ->where('phone', $cleanPhone)
                ->orWhereRaw("regexp_replace(phone, '[^0-9]', '', 'g') = ?", [$cleanPhone])
                ->orderByDesc('created_at')
                ->first();
            $identityAgent = $dedicatedTenantId
                ? $db->table('agents')
                    ->where('tenant_id', $dedicatedTenantId)
                    ->where('agent_key', 'aicenty-master-butler')
                    ->first()
                : null;
            if (!$identityHandover || !$dedicatedTenantId || !$identityAgent) {
                throw new \RuntimeException('Không thể gắn thành viên khi gia đình chưa có tenant và handover hợp lệ.');
            }
            foreach ($memberIdentityIds as $memberIdentityId) {
                $this->identityRegistry->bind(
                    (string) $memberIdentityId,
                    (string) $dedicatedTenantId,
                    (string) $identityAgent->id,
                    (string) $identityHandover->id,
                    (string) $memberRole,
                    (string) $memberName,
                    (string) $familyName,
                    (string) $butlerName,
                    'add-family-member'
                );
            }
        }

        // 2. Cập nhật danh sách thành viên vào metadata của gia chủ chính
        $existingMembers = array_values(array_filter(
            is_array($meta['family_members'] ?? null) ? $meta['family_members'] : [],
            static function ($existingMember) use ($memberPhone, $memberZaloUid): bool {
                if (!is_array($existingMember)) {
                    return true;
                }
                $samePhone = $memberPhone && preg_replace('/[^0-9]/', '', (string) ($existingMember['phone'] ?? '')) === $memberPhone;
                $sameZaloUid = $memberZaloUid && (string) ($existingMember['zalo_user_id'] ?? '') === (string) $memberZaloUid;

                return !$samePhone && !$sameZaloUid;
            }
        ));
        $existingMembers[] = [
            'name'         => $memberName,
            'role'         => $memberRole,
            'phone'        => $memberPhone,
            'zalo_user_id' => $memberZaloUid,
            'support_needs'=> $memberSupportNeeds,
            'added_at'     => now()->toIso8601String(),
        ];
        $meta['family_members'] = $existingMembers;

        $db->table('user_agent_profiles')
            ->where('agent_id', $lead->agent_id)
            ->where('user_id', $lead->user_id)
            ->update([
                'metadata'     => json_encode($meta, JSON_UNESCAPED_UNICODE),
                'last_seen_at' => now(),
            ]);

        // 3. Tạo Profile cho thành viên trên Master Tenant (0961 592 763)
        $masterTenantId = '0193a5b0-7000-7000-8000-000000000001';
        $masterButlerId = '01a1ac58-1bd8-7c30-b4c0-65f661aad8fc';

        foreach ($targetUserIds as $uId) {
            $memberMeta = [
                'name'                => $memberName,
                'role'                => $memberRole,
                'family_name'         => $familyName,
                'butler_name'         => $butlerName,
                'primary_phone'       => $cleanPhone,
                'dedicated_tenant_id' => $dedicatedTenantId,
                'is_vip'              => $isVip,
                'vip_plan'            => $vipPlan,
                'vip_until'           => $vipUntil,
                'is_family_member'    => true,
                'member_support_needs'=> $memberSupportNeeds,
                'member_onboarding'   => !$this->isUnknownFamilyMemberName($memberName)
                    && !$this->isUnknownFamilyMemberRole($memberRole)
                    && $memberSupportNeeds !== '' ? 'complete' : 'pending',
            ];

            $db->table('user_agent_profiles')->updateOrInsert(
                [
                    'agent_id'  => $masterButlerId,
                    'user_id'   => (string) $uId,
                ],
                [
                    'tenant_id'    => $masterTenantId,
                    'workspace'    => "family-{$cleanPhone}",
                    'metadata'     => json_encode($memberMeta, JSON_UNESCAPED_UNICODE),
                    'last_seen_at' => now(),
                ]
            );

            // Nếu gia đình đã có Dedicated Tenant, cũng tạo profile trong Dedicated Tenant
            if ($dedicatedTenantId) {
                $dedicatedButler = $db->table('agents')
                    ->where('tenant_id', $dedicatedTenantId)
                    ->where('agent_key', 'aicenty-master-butler')
                    ->first();

                if ($dedicatedButler) {
                    $db->table('user_agent_profiles')->updateOrInsert(
                        [
                            'agent_id'  => $dedicatedButler->id,
                            'user_id'   => (string) $uId,
                        ],
                        [
                            'tenant_id'    => $dedicatedTenantId,
                            'workspace'    => "family-{$cleanPhone}",
                            'metadata'     => json_encode($memberMeta, JSON_UNESCAPED_UNICODE),
                            'last_seen_at' => now(),
                        ]
                    );
                }
            }
        }

        // 4. Đồng bộ vào quangia_family_members và khóa cứng 100% quyền con cái (Strict RBAC Protocol)
        try {
            $handover = $db->table('quangia_pending_handover')
                ->where('phone', $cleanPhone)
                ->orWhereRaw("regexp_replace(phone, '[^0-9]', '', 'g') = ?", [$cleanPhone])
                ->orderByDesc('created_at')
                ->first();

            if ($handover) {
                $famGroup = $db->table('quangia_family_groups')->where('handover_id', $handover->id)->first();
                $groupId = $famGroup ? $famGroup->group_id : 'web_added';
                $roleLower = strtolower($memberRole);
                if (in_array($roleLower, ['wife', 'phu_nhan', 'vo', 'vợ'])) {
                    $cleanRole = 'wife';
                    $defaultNote = 'Phu Nhân / Bạn đời của Gia Chủ';
                } elseif (in_array($roleLower, ['husband', 'chong', 'chồng'])) {
                    $cleanRole = 'husband';
                    $defaultNote = 'Phu Quân / Bạn đời của Gia Chủ';
                } elseif (in_array($roleLower, ['parent', 'father', 'mother', 'ba', 'bố', 'me', 'mẹ'])) {
                    $cleanRole = 'parent';
                    $defaultNote = 'Phụ huynh / Bạn đời của Gia Chủ';
                } elseif (in_array($roleLower, ['child', 'con', 'con_gai', 'con_trai', 'student'])) {
                    $cleanRole = 'child';
                    $defaultNote = 'Con / Học sinh cần kèm cặp phương pháp Socratic';
                } else {
                    $cleanRole = 'member';
                    $defaultNote = 'Thành viên Tổ Ấm';
                }

                $primaryMemberUid = (string) ($memberZaloUid ?: ($memberPhone ? 'phone_' . $memberPhone : $targetUserIds[0]));

                $db->table('quangia_family_members')->updateOrInsert(
                    [
                        'handover_id' => $handover->id,
                        'zalo_uid'    => $primaryMemberUid,
                    ],
                    [
                        'id'            => (string) Str::uuid(),
                        'group_id'      => $groupId,
                        'display_name'  => $memberName,
                        'role'          => $cleanRole,
                        'academic_info' => $memberData['academic_info'] ?? ($memberSupportNeeds !== '' ? $memberSupportNeeds : $defaultNote),
                        'updated_at'    => now(),
                        'created_at'    => now(),
                    ]
                );

                // KHÓA CỨNG AN TOÀN V10: Mọi thành viên gia đình không phải Gia Chủ tối cao đều bị thu hồi quyền cấu hình
                $isRealOwnerElsewhere = $db->table('quangia_pending_handover')
                    ->where('claimed_zalo_personal_id', $primaryMemberUid)
                    ->whereRaw("starts_with(phone, 'zalo_') = FALSE")
                    ->exists();
                $isParentElsewhere = $db->table('quangia_family_groups')->where('parent_zalo_uid', $primaryMemberUid)->exists();

                if (!$isRealOwnerElsewhere && !$isParentElsewhere) {
                    $db->table('agent_config_permissions')->where('user_id', $primaryMemberUid)->delete();
                }

                $db->statement("SELECT public.sync_quangia_family_profile_all(?)", [$handover->id]);
                if (!empty($handover->dedicated_tenant_id)) {
                    $db->statement("SELECT public.fn_handover_replicate_master_to_dedicated(?)", [$handover->id]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[ButlerAutomation] addFamilyMember quangia_family_members sync notice: ' . $e->getMessage());
            throw $e;
        }

        // Ghi sau các hàm sync để USER/BOOTSTRAP của gia chủ không ghi đè context thành viên.
        $memberUserContext = $this->buildFamilyMemberUserContext(
            $memberName,
            $memberRole,
            $memberSupportNeeds,
            (string) $familyName,
            (string) $butlerName,
            (string) $ownerName
        );
        $memberBootstrapContext = $this->buildFamilyMemberBootstrapContext(
            $memberName,
            $memberRole,
            $memberSupportNeeds,
            (string) $familyName,
            (string) $butlerName,
            (string) $ownerName
        );

        $contextAgents = [
            ['agent_id' => $masterButlerId, 'tenant_id' => $masterTenantId],
        ];
        if ($dedicatedTenantId) {
            $dedicatedButlerId = $db->table('agents')
                ->where('tenant_id', $dedicatedTenantId)
                ->where('agent_key', 'aicenty-master-butler')
                ->value('id');
            if ($dedicatedButlerId) {
                $contextAgents[] = ['agent_id' => (string) $dedicatedButlerId, 'tenant_id' => (string) $dedicatedTenantId];
            }
        }

        foreach ($contextAgents as $contextAgent) {
            foreach ($targetUserIds as $uId) {
                $contextKey = [
                    'agent_id'  => $contextAgent['agent_id'],
                    'user_id'   => (string) $uId,
                ];
                $contextValues = [
                    'tenant_id' => $contextAgent['tenant_id'],
                    'updated_at'=> now(),
                ];

                $db->table('user_context_files')->updateOrInsert(
                    $contextKey + ['file_name' => 'USER.md'],
                    $contextValues + ['content' => $memberUserContext]
                );
                $db->table('user_context_files')->updateOrInsert(
                    $contextKey + ['file_name' => 'BOOTSTRAP.md'],
                    $contextValues + ['content' => $memberBootstrapContext]
                );
            }
        }

        Log::info("[ButlerAutomation] Added family member {$memberName} ({$memberRole}) to family {$familyName} (Phone: {$cleanPhone})");

        return [
            'status'              => 'success',
            'family_name'         => $familyName,
            'member_name'         => $memberName,
            'dedicated_tenant_id' => $dedicatedTenantId,
            'target_user_ids'     => $targetUserIds,
        ];
    }

    /**
     * Repair profile/context only for an exact member + handover + active identity binding.
     * This deliberately does not re-bind identity or write quangia_family_members.
     */
    public function reconcileVerifiedFamilyMemberContext(string $handoverId, string $zaloUid): bool
    {
        $handoverId = trim($handoverId);
        $zaloUid = trim($zaloUid);
        if ($handoverId === '' || strlen($zaloUid) < 15 || !ctype_digit($zaloUid)) {
            return false;
        }

        $db = DB::connection('butler_pgsql');

        return $db->transaction(function () use ($db, $handoverId, $zaloUid): bool {
            $verifiedMember = $db->table('quangia_family_members as member')
                ->join('quangia_pending_handover as handover', 'handover.id', '=', 'member.handover_id')
                ->join('butler_identity_bindings as binding', function ($join) {
                    $join->on('binding.external_user_id', '=', 'member.zalo_uid')
                        ->on('binding.handover_id', '=', 'handover.id')
                        ->on('binding.tenant_id', '=', 'handover.dedicated_tenant_id')
                        ->where('binding.status', '=', 'active');
                })
                ->where('member.handover_id', $handoverId)
                ->where('member.zalo_uid', $zaloUid)
                ->select([
                    'member.display_name as member_name',
                    'member.role as member_role',
                    'member.academic_info as member_support_needs',
                    'handover.phone as primary_phone',
                    'handover.name as owner_name',
                    'handover.family_name',
                    'handover.butler_name',
                    'handover.dedicated_tenant_id',
                    'binding.agent_id as dedicated_agent_id',
                    'binding.family_name as binding_family_name',
                    'binding.butler_name as binding_butler_name',
                ])
                ->first();

            if (!$verifiedMember) {
                return false;
            }

            $dedicatedTenantId = (string) $verifiedMember->dedicated_tenant_id;
            $dedicatedAgentId = (string) $verifiedMember->dedicated_agent_id;
            $dedicatedAgentExists = $db->table('agents')
                ->where('id', $dedicatedAgentId)
                ->where('tenant_id', $dedicatedTenantId)
                ->where('agent_key', 'aicenty-master-butler')
                ->exists();
            if (!$dedicatedAgentExists) {
                throw new \RuntimeException("Verified member {$zaloUid} has no valid dedicated Butler agent.");
            }

            $familyName = trim((string) ($verifiedMember->family_name ?: $verifiedMember->binding_family_name ?: 'Gia đình'));
            $butlerName = trim((string) ($verifiedMember->butler_name ?: $verifiedMember->binding_butler_name ?: 'Quản Gia'));
            $ownerName = trim((string) ($verifiedMember->owner_name ?: 'Gia chủ chính'));
            $memberName = trim((string) ($verifiedMember->member_name ?: 'Thành viên gia đình'));
            $memberRole = trim((string) ($verifiedMember->member_role ?: 'member'));
            $supportNeedsFromRegistry = trim((string) ($verifiedMember->member_support_needs ?? ''));
            if (in_array($supportNeedsFromRegistry, [
                'Phu Nhân / Bạn đời của Gia Chủ',
                'Phu Quân / Bạn đời của Gia Chủ',
                'Phụ huynh / Bạn đời của Gia Chủ',
                'Con / Học sinh cần kèm cặp phương pháp Socratic',
                'Thành viên Tổ Ấm',
            ], true)) {
                $supportNeedsFromRegistry = '';
            }
            $cleanPhone = preg_replace('/[^0-9]/', '', (string) $verifiedMember->primary_phone);

            $masterTenantId = '0193a5b0-7000-7000-8000-000000000001';
            $masterButlerId = '01a1ac58-1bd8-7c30-b4c0-65f661aad8fc';
            $contextAgents = [
                ['agent_id' => $masterButlerId, 'tenant_id' => $masterTenantId],
                ['agent_id' => $dedicatedAgentId, 'tenant_id' => $dedicatedTenantId],
            ];
            $changed = false;

            foreach ($contextAgents as $contextAgent) {
                $existingProfile = $db->table('user_agent_profiles')
                    ->where('agent_id', $contextAgent['agent_id'])
                    ->where('user_id', $zaloUid)
                    ->where('tenant_id', $contextAgent['tenant_id'])
                    ->first();
                $existingMeta = is_string($existingProfile?->metadata)
                    ? json_decode($existingProfile->metadata, true)
                    : (array) ($existingProfile?->metadata ?? []);
                $supportNeeds = $supportNeedsFromRegistry !== ''
                    ? $supportNeedsFromRegistry
                    : trim((string) ($existingMeta['member_support_needs'] ?? ''));
                $memberOnboarding = !$this->isUnknownFamilyMemberName($memberName)
                    && !$this->isUnknownFamilyMemberRole($memberRole)
                    && $supportNeeds !== '' ? 'complete' : 'pending';
                $canonicalMeta = [
                    'name'                 => $memberName,
                    'role'                 => $memberRole,
                    'family_name'          => $familyName,
                    'butler_name'          => $butlerName,
                    'primary_phone'        => $cleanPhone,
                    'dedicated_tenant_id'  => $dedicatedTenantId,
                    'dedicated_agent_id'   => $dedicatedAgentId,
                    'is_family_member'     => true,
                    'member_support_needs' => $supportNeeds,
                    'member_onboarding'    => $memberOnboarding,
                ];
                $profileNeedsUpdate = !$existingProfile;
                foreach ($canonicalMeta as $key => $value) {
                    if (($existingMeta[$key] ?? null) !== $value) {
                        $profileNeedsUpdate = true;
                        break;
                    }
                }
                if ($profileNeedsUpdate) {
                    $db->table('user_agent_profiles')->updateOrInsert(
                        [
                            'agent_id'  => $contextAgent['agent_id'],
                            'user_id'   => $zaloUid,
                            'tenant_id' => $contextAgent['tenant_id'],
                        ],
                        [
                            'workspace'    => "family-{$cleanPhone}",
                            'metadata'     => json_encode(array_merge($existingMeta, $canonicalMeta), JSON_UNESCAPED_UNICODE),
                            'last_seen_at' => now(),
                        ]
                    );
                    $changed = true;
                }

                $memberUserContext = $this->buildFamilyMemberUserContext(
                    $memberName,
                    $memberRole,
                    $supportNeeds,
                    $familyName,
                    $butlerName,
                    $ownerName
                );
                $memberBootstrapContext = $this->buildFamilyMemberBootstrapContext(
                    $memberName,
                    $memberRole,
                    $supportNeeds,
                    $familyName,
                    $butlerName,
                    $ownerName
                );
                $existingUserContext = $db->table('user_context_files')
                    ->where('agent_id', $contextAgent['agent_id'])
                    ->where('user_id', $zaloUid)
                    ->where('file_name', 'USER.md')
                    ->first();
                $existingBootstrap = $db->table('user_context_files')
                    ->where('agent_id', $contextAgent['agent_id'])
                    ->where('user_id', $zaloUid)
                    ->where('file_name', 'BOOTSTRAP.md')
                    ->first();
                $memberOnboardingCompleted = $existingBootstrap && trim((string) $existingBootstrap->content) === '';

                if (!$existingUserContext || (!$memberOnboardingCompleted && (string) $existingUserContext->content !== $memberUserContext)) {
                    $db->table('user_context_files')->updateOrInsert(
                        [
                            'agent_id'  => $contextAgent['agent_id'],
                            'user_id'   => $zaloUid,
                            'file_name' => 'USER.md',
                        ],
                        [
                            'content'    => $memberUserContext,
                            'tenant_id'  => $contextAgent['tenant_id'],
                            'updated_at' => now(),
                        ]
                    );
                    $changed = true;
                }
                if (!$existingBootstrap || (trim((string) $existingBootstrap->content) !== '' && (string) $existingBootstrap->content !== $memberBootstrapContext)) {
                    $db->table('user_context_files')->updateOrInsert(
                        [
                            'agent_id'  => $contextAgent['agent_id'],
                            'user_id'   => $zaloUid,
                            'file_name' => 'BOOTSTRAP.md',
                        ],
                        [
                            'content'    => $memberBootstrapContext,
                            'tenant_id'  => $contextAgent['tenant_id'],
                            'updated_at' => now(),
                        ]
                    );
                    $changed = true;
                }
            }

            $isOwnerElsewhere = $db->table('quangia_pending_handover')
                ->where('claimed_zalo_personal_id', $zaloUid)
                ->whereRaw("starts_with(phone, 'zalo_') = FALSE")
                ->exists();
            $isParentElsewhere = $db->table('quangia_family_groups')
                ->where('parent_zalo_uid', $zaloUid)
                ->exists();
            if (!$isOwnerElsewhere && !$isParentElsewhere) {
                $permissionQuery = $db->table('agent_config_permissions')->where('user_id', $zaloUid);
                if ($permissionQuery->exists()) {
                    $permissionQuery->delete();
                    $changed = true;
                }
            }

            return $changed;
        });
    }

    private function buildFamilyMemberUserContext(
        string $memberName,
        string $memberRole,
        string $supportNeeds,
        string $familyName,
        string $butlerName,
        string $ownerName
    ): string {
        $safeName = $this->singleLineContextValue($memberName, 'Chưa xác nhận');
        $safeRole = $this->singleLineContextValue($this->familyMemberRoleLabel($memberRole), 'Chưa xác nhận');
        $safeNeeds = $this->singleLineContextValue($supportNeeds, 'Chưa xác nhận');
        $safeFamily = $this->singleLineContextValue($familyName, 'Gia đình');
        $safeButler = $this->singleLineContextValue($butlerName, 'Quản Gia');
        $safeOwner = $this->singleLineContextValue($ownerName, 'Gia chủ chính');

        return "# USER.md - Hồ Sơ Thành Viên Gia Đình\n"
            . "- **Loại hồ sơ:** Thành viên gia đình (is_family_member=true), không phải Gia chủ chính\n"
            . "- **Tên thành viên:** {$safeName}\n"
            . "- **Vai trò / Quan hệ trong gia đình:** {$safeRole}\n"
            . "- **Thuộc tổ ấm:** {$safeFamily}\n"
            . "- **Gia chủ chính:** {$safeOwner}\n"
            . "- **Tên Quản Gia đã được Gia chủ chính thiết lập:** {$safeButler}\n"
            . "- **Mong muốn hỗ trợ:** {$safeNeeds}\n\n"
            . "## Quy tắc bắt buộc\n"
            . "1. Kế thừa nguyên trạng tên Quản Gia và cấu hình của tổ ấm; thành viên này không có quyền đổi cấu hình.\n"
            . "2. Tuyệt đối không onboarding lại như Gia chủ chính; không hỏi đặt tên Quản Gia, tên tổ ấm, múi giờ, ngôn ngữ hoặc thông tin đăng ký.\n"
            . "3. Chỉ hỏi phần còn thiếu trong ba thông tin: tên muốn được gọi, vai trò trong gia đình, nhu cầu hỗ trợ hiện tại. Gộp các phần còn thiếu vào một câu hỏi ngắn.\n"
            . "4. Sau khi đủ thông tin, đi thẳng vào hỗ trợ và ghi nhớ bối cảnh riêng của thành viên này.\n";
    }

    private function buildFamilyMemberBootstrapContext(
        string $memberName,
        string $memberRole,
        string $supportNeeds,
        string $familyName,
        string $butlerName,
        string $ownerName
    ): string {
        $safeName = $this->singleLineContextValue($memberName, 'thành viên');
        $safeRole = $this->singleLineContextValue($this->familyMemberRoleLabel($memberRole), 'thành viên');
        $safeFamily = $this->singleLineContextValue($familyName, 'gia đình');
        $safeButler = $this->singleLineContextValue($butlerName, 'Quản Gia');
        $safeOwner = $this->singleLineContextValue($ownerName, 'Gia chủ chính');

        $missing = [];
        if ($this->isUnknownFamilyMemberName($memberName)) {
            $missing[] = 'tên bạn muốn Quản Gia gọi';
        }
        if ($this->isUnknownFamilyMemberRole($memberRole)) {
            $missing[] = 'bạn là ai trong gia đình';
        }
        if ($supportNeeds === '') {
            $missing[] = 'điều bạn đang mong muốn được hỗ trợ';
        }

        $firstTurn = $missing === []
            ? "Chào {$safeName} ngắn gọn và đi thẳng vào hỗ trợ theo nhu cầu đã biết."
            : 'Chỉ hỏi một lần, trong một câu ngắn, về: ' . implode('; ', $missing) . '.';

        return "# BOOTSTRAP.md - Làm Quen Nhanh Với Thành Viên Gia Đình\n\n"
            . "Bạn là {$safeButler}, Quản Gia đã được {$safeOwner} thiết lập cho {$safeFamily}. Người đang nhắn là {$safeName} ({$safeRole}), một thành viên của gia đình, không phải người đăng ký mới.\n\n"
            . "## Mục tiêu\n"
            . "Hiểu đúng người và nhu cầu hiện tại với số câu hỏi ít nhất, rồi phục vụ ngay.\n\n"
            . "## Luật bắt buộc\n"
            . "- TUYỆT ĐỐI KHÔNG hỏi thành viên muốn đặt tên Quản Gia là gì; tên hiện tại là {$safeButler}.\n"
            . "- TUYỆT ĐỐI KHÔNG chạy lại onboarding Gia chủ, không hỏi tên tổ ấm, múi giờ, ngôn ngữ, số điện thoại đăng ký hoặc thông tin chung đã có.\n"
            . "- Nếu trước đó lỡ hỏi đặt tên Quản Gia, xin lỗi thật ngắn, xác nhận tên Quản Gia là {$safeButler}, rồi bỏ qua câu hỏi đó.\n"
            . "- {$firstTurn}\n"
            . "- Lời làm quen tối đa 2 câu, không giới thiệu tính năng và không kể lại thông tin hệ thống đã biết.\n"
            . "- Không hỏi dồn từng câu. Nếu thành viên trả lời thiếu, chỉ hỏi lại đúng phần còn thiếu.\n"
            . "- Khi đã đủ tên, vai trò và nhu cầu: cập nhật USER.md nhưng phải giữ nguyên {$safeButler}, {$safeFamily}, {$safeOwner} và cờ is_family_member=true; sau đó ghi rỗng BOOTSTRAP.md và hỗ trợ ngay.\n"
            . "- Không để lộ tên file, công cụ hoặc quy trình kỹ thuật.\n";
    }

    private function familyMemberRoleLabel(string $role): string
    {
        return match (mb_strtolower(trim($role))) {
            'wife', 'phu_nhan', 'vo', 'vợ' => 'Vợ / Phu nhân của Gia chủ',
            'husband', 'chong', 'chồng' => 'Chồng / Phu quân của Gia chủ',
            'parent', 'father', 'mother', 'ba', 'bố', 'me', 'mẹ' => 'Phụ huynh trong gia đình',
            'child', 'con', 'con_gai', 'con_trai', 'student' => 'Con trong gia đình',
            'member', '' => 'Chưa xác nhận',
            default => trim($role),
        };
    }

    private function isUnknownFamilyMemberName(string $name): bool
    {
        return in_array(mb_strtolower(trim($name)), ['', 'thành viên', 'thành viên gia đình', 'member'], true);
    }

    private function isUnknownFamilyMemberRole(string $role): bool
    {
        return in_array(mb_strtolower(trim($role)), ['', 'member', 'thành viên', 'unknown', 'chưa rõ'], true);
    }

    private function singleLineContextValue(string $value, string $fallback): string
    {
        $value = trim((string) preg_replace('/[\r\n]+/u', ' ', $value));
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        $value = mb_substr($value, 0, 500);

        return $value !== '' ? $value : $fallback;
    }

    /**
     * Kích hoạt và cấu hình thông tin SIM Riêng cho Tenant độc lập của gia đình (BYO-SIM)
     * Tự động hoàn tất 100%:
     * 1. Đăng ký Channel Instance cho SIM mới trong bảng channel_instances.
     * 2. Session Re-mapping: Nhân bản các phiên chat cũ từ channel 'quangia' sang channel của SIM mới.
     * 3. Di chuyển/đồng bộ hồ sơ user_agent_profiles & memory_documents sang Tenant mới.
     * 4. Compile ký ức 7 ngày nạp vào episodic_summaries của Quản Gia mới.
     */
    public function registerDedicatedSimChannel(string $tenantId, string $simPhone, array $settings = []): array
    {
        $db = DB::connection('butler_pgsql');
        $cleanPhone = preg_replace('/[^0-9]/', '', $simPhone);
        $masterTenantId = '0193a5b0-7000-7000-8000-000000000001';

        $tenant = $db->table('tenants')->where('id', $tenantId)->first();
        if (!$tenant) {
            throw new \Exception("Không tìm thấy Tenant với ID: {$tenantId}");
        }

        $tenantSettings = is_string($tenant->settings) ? json_decode($tenant->settings, true) : (array) $tenant->settings;
        $tenantSettings['has_dedicated_sim'] = true;
        $tenantSettings['dedicated_sim_phone'] = $cleanPhone;
        $tenantSettings['sim_activation_status'] = $settings['status'] ?? 'active';
        $tenantSettings['sim_registered_at'] = now()->toIso8601String();
        $tenantSettings['sim_network'] = $settings['network'] ?? 'Viettel/Vina/Mobi';

        $channelName = 'sim_' . $cleanPhone;

        // 1. Tìm Agent Quản Gia Master trong Tenant riêng
        $tenantButler = $db->table('agents')
            ->where('tenant_id', $tenantId)
            ->where('agent_key', 'aicenty-master-butler')
            ->first();

        if (!$tenantButler) {
            throw new \Exception("Không tìm thấy Quản Gia riêng (aicenty-master-butler) trong Tenant {$tenantId}");
        }

        $tenantSettings['dedicated_channel_name'] = $channelName;
        $tenantSettings['dedicated_agent_id'] = $tenantButler->id;

        $db->table('tenants')->where('id', $tenantId)->update([
            'settings'   => json_encode($tenantSettings, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);

        // 2. Tạo hoặc Cập nhật Channel Instance cho SIM Riêng
        $existingChannel = $db->table('channel_instances')
            ->where('tenant_id', $tenantId)
            ->where('name', $channelName)
            ->first();

        $existingChannelConfig = $existingChannel
            ? (is_string($existingChannel->config)
                ? (json_decode($existingChannel->config, true) ?: [])
                : (array) $existingChannel->config)
            : [];
        $standardChannelConfig = array_merge([
            'dm_policy' => 'open',
            'group_policy' => 'open',
            'require_mention' => true,
            'history_limit' => 50,
        ], $existingChannelConfig, [
            'phone' => $cleanPhone,
            'dedicated' => true,
            'auto_accept_verified_friend_requests' => true,
        ]);

        if (!$existingChannel) {
            $db->table('channel_instances')->insert([
                'id'           => (string) Str::uuid(),
                'tenant_id'    => $tenantId,
                'name'         => $channelName,
                'display_name' => "Zalo Quản Gia Riêng ({$cleanPhone})",
                'channel_type' => 'zalo_personal',
                'agent_id'     => $tenantButler->id,
                'config'       => json_encode($standardChannelConfig, JSON_UNESCAPED_UNICODE),
                'enabled'      => true,
                'created_by'   => 'system',
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
            Log::info("[ButlerAutomation] Created channel_instance {$channelName} for Tenant {$tenantId}");
        } else {
            $db->table('channel_instances')->where('id', $existingChannel->id)->update([
                'agent_id' => $tenantButler->id,
                'config' => json_encode($standardChannelConfig, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }

        // 3. Thu thập danh sách User IDs của gia đình
        $familyPhone = trim((string) ($tenantSettings['family_phone'] ?? ''));
        $cleanFamilyPhone = preg_replace('/[^0-9]/', '', $familyPhone);

        $familyUserIds = array_filter([
            $cleanFamilyPhone,
            'phone_' . $cleanFamilyPhone,
            $cleanPhone,
            'phone_' . $cleanPhone,
        ]);

        $profiles = $db->table('user_agent_profiles')
            ->where(function ($q) use ($cleanFamilyPhone, $tenantId, $masterTenantId) {
                $q->where('tenant_id', $tenantId)
                  ->orWhere('tenant_id', $masterTenantId);
            })
            ->get();

        foreach ($profiles as $p) {
            $pMeta = is_string($p->metadata) ? json_decode($p->metadata, true) : (array) $p->metadata;
            $pPhone = preg_replace('/[^0-9]/', '', (string) ($pMeta['phone'] ?? $pMeta['primary_phone'] ?? ''));
            if (!empty($pPhone) && ($pPhone === $cleanFamilyPhone || str_ends_with($pPhone, substr($cleanFamilyPhone, -9)))) {
                $familyUserIds[] = (string) $p->user_id;
                if (!empty($pMeta['zalo_user_id'])) {
                    $familyUserIds[] = (string) $pMeta['zalo_user_id'];
                }
            }
        }
        $familyUserIds = array_values(array_unique(array_filter($familyUserIds)));

        // 4. Session Re-mapping: Nhân bản các phiên chat cũ từ channel 'quangia' sang channel của SIM mới
        $oldSessions = $db->table('sessions')
            ->where(function ($q) use ($familyUserIds, $cleanFamilyPhone) {
                $q->whereIn('user_id', $familyUserIds)
                  ->orWhere('session_key', 'LIKE', '%' . $cleanFamilyPhone . '%')
                  ->orWhere('session_key', 'LIKE', '%' . substr($cleanFamilyPhone, -9) . '%');
            })
            ->where(function ($q) {
                $q->where('channel', 'quangia')
                  ->orWhere('session_key', 'LIKE', '%:quangia:%');
            })
            ->get();

        $remappedCount = 0;
        foreach ($oldSessions as $oldSess) {
            $newSessionKey = str_replace(':quangia:', ":{$channelName}:", $oldSess->session_key);
            $newSessionKey = preg_replace('/agent:[^:]+:/', "agent:{$tenantButler->agent_key}:", $newSessionKey);

            $existingNewSess = $db->table('sessions')
                ->where('tenant_id', $tenantId)
                ->where('session_key', $newSessionKey)
                ->first();

            $meta = is_string($oldSess->metadata) ? json_decode($oldSess->metadata, true) : (array) $oldSess->metadata;
            $meta['remapped_from_quangia'] = true;
            $meta['dedicated_sim_channel'] = $channelName;
            $meta['remapped_at'] = now()->toIso8601String();

            if (!$existingNewSess) {
                $db->table('sessions')->insert([
                    'id'               => (string) Str::uuid(),
                    'session_key'      => $newSessionKey,
                    'agent_id'         => $tenantButler->id,
                    'user_id'          => $oldSess->user_id,
                    'messages'         => is_string($oldSess->messages) ? $oldSess->messages : json_encode($oldSess->messages, JSON_UNESCAPED_UNICODE),
                    'summary'          => $oldSess->summary,
                    'model'            => $oldSess->model,
                    'provider'         => $oldSess->provider,
                    'channel'          => $channelName,
                    'input_tokens'     => $oldSess->input_tokens ?? 0,
                    'output_tokens'    => $oldSess->output_tokens ?? 0,
                    'compaction_count' => $oldSess->compaction_count ?? 0,
                    'label'            => $oldSess->label,
                    'created_at'       => $oldSess->created_at,
                    'updated_at'       => now(),
                    'metadata'         => json_encode($meta, JSON_UNESCAPED_UNICODE),
                    'tenant_id'        => $tenantId,
                ]);
                $remappedCount++;
            } else {
                $db->table('sessions')->where('id', $existingNewSess->id)->update([
                    'messages'   => is_string($oldSess->messages) ? $oldSess->messages : json_encode($oldSess->messages, JSON_UNESCAPED_UNICODE),
                    'summary'    => $oldSess->summary,
                    'metadata'   => json_encode($meta, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
                $remappedCount++;
            }
        }

        // 5. Đồng bộ User Profiles sang Tenant mới cho Quản gia riêng
        foreach ($familyUserIds as $fUid) {
            $masterProfile = $db->table('user_agent_profiles')
                ->where('user_id', $fUid)
                ->where('tenant_id', $masterTenantId)
                ->first();

            if ($masterProfile) {
                $db->table('user_agent_profiles')->updateOrInsert(
                    [
                        'agent_id'  => $tenantButler->id,
                        'user_id'   => $fUid,
                        'tenant_id' => $tenantId,
                    ],
                    [
                        'metadata'     => is_string($masterProfile->metadata) ? $masterProfile->metadata : json_encode($masterProfile->metadata, JSON_UNESCAPED_UNICODE),
                        'last_seen_at' => now(),
                    ]
                );
            }
        }

        // 6. Tổng hợp ký ức 7 ngày (Episodic Memory Compilation)
        $primaryUid = $familyUserIds[0] ?? $cleanFamilyPhone;
        $this->compile7DaysEpisodicMemory($tenantId, (string) $primaryUid, $tenantButler->id);

        // 7. Đồng bộ phân quyền và context files sang Dedicated Tenant
        try {
            $handover = $db->table('quangia_pending_handover')
                ->where('dedicated_tenant_id', $tenantId)
                ->orWhere('phone', $cleanFamilyPhone)
                ->orWhereRaw("regexp_replace(phone, '[^0-9]', '', 'g') = ?", [$cleanFamilyPhone])
                ->orderByDesc('created_at')
                ->first();

            if ($handover) {
                if (empty($handover->dedicated_tenant_id) || $handover->dedicated_tenant_id !== $tenantId || empty($handover->dedicated_agent_id)) {
                    $db->table('quangia_pending_handover')->where('id', $handover->id)->update([
                        'dedicated_tenant_id' => $tenantId,
                        'dedicated_agent_id'  => $tenantButler->id,
                    ]);
                    $handover->dedicated_tenant_id = $tenantId;
                    $handover->dedicated_agent_id = $tenantButler->id;
                }
                $db->statement("SELECT public.fn_handover_replicate_master_to_dedicated(?)", [$handover->id]);
            }
        } catch (\Throwable $e) {
            Log::warning('[ButlerAutomation] registerDedicatedSimChannel replicate notice: ' . $e->getMessage());
        }

        Log::info("[ButlerAutomation] Registered Dedicated SIM {$cleanPhone} for Tenant {$tenantId} (Remapped {$remappedCount} sessions)");

        return [
            'status'              => 'success',
            'tenant_id'           => $tenantId,
            'channel_name'        => $channelName,
            'agent_id'            => $tenantButler->id,
            'dedicated_sim_phone' => $cleanPhone,
            'remapped_sessions'   => $remappedCount,
            'family_user_ids'     => $familyUserIds,
        ];
    }

    /**
     * Tổng hợp toàn bộ lịch sử trò chuyện 7 ngày dùng thử thành bản ghi Ký Ức Giai Đoạn (Episodic Summary)
     * nạp trực tiếp vào trí nhớ làm việc của Quản Gia riêng.
     */
    public function compile7DaysEpisodicMemory(string $tenantId, string $primaryUserId, ?string $agentId = null): array
    {
        $db = DB::connection('butler_pgsql');

        if (!$agentId) {
            $agent = $db->table('agents')
                ->where('tenant_id', $tenantId)
                ->where('agent_key', 'aicenty-master-butler')
                ->first();
            $agentId = $agent?->id;
        }

        if (!$agentId) {
            return ['status' => 'skipped', 'message' => 'Agent Quản Gia không tồn tại'];
        }

        $profile = $db->table('user_agent_profiles')
            ->where('tenant_id', $tenantId)
            ->where('agent_id', $agentId)
            ->first();

        if (!$profile) {
            $profile = $db->table('user_agent_profiles')
                ->where('user_id', $primaryUserId)
                ->first();
        }

        $meta = is_string($profile?->metadata) ? json_decode($profile->metadata, true) : (array) ($profile?->metadata ?? []);
        $familyName = $meta['family_name'] ?? 'Gia Đình';
        $butlerName = $meta['butler_name'] ?? 'Bác Phúc';
        $childInfo = $meta['child_info'] ?? 'Các con';
        $primaryConcern = $meta['primary_concern'] ?? 'Kèm học Socratic và nề nếp sinh hoạt';

        $sessions = $db->table('sessions')
            ->where('tenant_id', $tenantId)
            ->where('agent_id', $agentId)
            ->get();

        $totalTurns = 0;
        $recentHighlights = [];

        foreach ($sessions as $s) {
            $msgs = is_string($s->messages) ? json_decode($s->messages, true) : (array) $s->messages;
            if (is_array($msgs)) {
                $totalTurns += count($msgs);
                $userMsgs = array_filter($msgs, fn($m) => ($m['role'] ?? '') === 'user');
                foreach (array_slice($userMsgs, -3) as $um) {
                    $txt = trim(preg_replace('/\[From:[^\]]+\]/', '', (string) ($um['content'] ?? '')));
                    if (!empty($txt)) {
                        $recentHighlights[] = Str::limit($txt, 80);
                    }
                }
            }
        }

        $highlightsText = !empty($recentHighlights)
            ? ("- Các chủ đề/câu hỏi gần nhất của gia đình: " . implode('; ', array_unique($recentHighlights)))
            : "- Gia đình đã làm quen đầy đủ với quy trình tương tác và các chuyên đề quản xuyến.";

        $summaryText = "=== HỒ SƠ & BẢN TỔNG KẾT KÝ ỨC 7 NGÀY ĐỒNG HÀNH ===\n"
            . "🏠 Tổ ấm: {$familyName}\n"
            . "🎩 Quản Gia riêng: {$butlerName}\n"
            . "👶 Thông tin các con: {$childInfo}\n"
            . "🎯 Mục tiêu trọng tâm của Ba Mẹ: {$primaryConcern}\n"
            . "⏳ Tiến trình: Hoàn tất 7 ngày trải nghiệm với {$totalTurns} lượt tương tác. Các bé đã làm quen với phương pháp Socratic tự lập, không ỷ lại.\n"
            . "{$highlightsText}\n"
            . "⚡ CHỈ THỊ CHUYỂN GIAO: Quản gia tiếp tục duy trì mạch hội thoại liền mạch, giữ đúng gia phong lễ phép, ấm áp, sâu sắc như người nhà.";

        $sessionKey = "agent:aicenty-master-butler:summary:{$primaryUserId}";

        // 1. Ghi vào episodic_summaries
        $existingSummary = $db->table('episodic_summaries')
            ->where('tenant_id', $tenantId)
            ->where('agent_id', $agentId)
            ->where('source_type', '7day_trial_compaction')
            ->first();

        $keyTopicsArray = '{socratic,dinh_duong,chi_tieu_50_30_20,hoc_tap}';

        if (!$existingSummary) {
            $db->table('episodic_summaries')->insert([
                'id'          => (string) Str::uuid(),
                'tenant_id'   => $tenantId,
                'agent_id'    => $agentId,
                'user_id'     => $primaryUserId,
                'session_key' => $sessionKey,
                'summary'     => $summaryText,
                'l0_abstract' => "Ký ức 7 ngày dùng thử của {$familyName}",
                'key_topics'  => $keyTopicsArray,
                'turn_count'  => $totalTurns,
                'token_count' => mb_strlen($summaryText),
                'source_type' => '7day_trial_compaction',
                'created_at'  => now(),
            ]);
        } else {
            $db->table('episodic_summaries')->where('id', $existingSummary->id)->update([
                'summary'     => $summaryText,
                'key_topics'  => $keyTopicsArray,
                'turn_count'  => $totalTurns,
                'token_count' => mb_strlen($summaryText),
            ]);
        }

        // 2. Ghi thêm vào memory_documents để RAG tìm kiếm
        $docPath = 'memory/7day_trial_compaction.md';
        $docHash = hash('sha256', $summaryText);

        $existingDoc = $db->table('memory_documents')
            ->where('tenant_id', $tenantId)
            ->where('agent_id', $agentId)
            ->where('path', $docPath)
            ->first();

        if (!$existingDoc) {
            $db->table('memory_documents')->insert([
                'id'           => (string) Str::uuid(),
                'tenant_id'    => $tenantId,
                'agent_id'     => $agentId,
                'user_id'      => $primaryUserId,
                'path'         => $docPath,
                'content'      => $summaryText,
                'hash'         => $docHash,
                'custom_scope' => 'episodic_compaction',
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        } else {
            $db->table('memory_documents')->where('id', $existingDoc->id)->update([
                'content'    => $summaryText,
                'hash'       => $docHash,
                'updated_at' => now(),
            ]);
        }

        Log::info("[ButlerAutomation] Compiled 7-day episodic memory for {$familyName} ({$tenantId})");

        return [
            'status'      => 'success',
            'tenant_id'   => $tenantId,
            'summary'     => $summaryText,
            'total_turns' => $totalTurns,
        ];
    }

    /**
     * Resolve an active Zalo delivery channel via /healthz and channel_instances,
     * falling back safely to 'quangia' when 'butlerguru' is unavailable.
     */
    public function resolveActiveZaloChannel(string $preferred = 'butlerguru', ?string $tenantId = null): string
    {
        try {
            $db = DB::connection('butler_pgsql');
            $failedChannels = Cache::remember('butler_healthz_failed_channels', 20, function () {
                try {
                    $resp = \Illuminate\Support\Facades\Http::timeout(2)->get('http://127.0.0.1:18896/healthz');
                    $data = $resp->json();
                    return is_array($data['failed_channels'] ?? null) ? $data['failed_channels'] : [];
                } catch (\Throwable $e) {
                    return [];
                }
            });

            if ($tenantId) {
                $dedicated = $db->table('channel_instances')
                    ->where('tenant_id', $tenantId)
                    ->where('enabled', true)
                    ->whereNotNull('credentials')
                    ->value('name');
                if ($dedicated && !in_array($dedicated, $failedChannels, true)) {
                    return (string) $dedicated;
                }
            }

            $prefOk = $db->table('channel_instances')
                ->where('name', $preferred)
                ->where('enabled', true)
                ->whereNotNull('credentials')
                ->exists();
            if ($prefOk && !in_array($preferred, $failedChannels, true)) {
                return $preferred;
            }

            $fallbackOk = $db->table('channel_instances')
                ->where('name', 'quangia')
                ->where('enabled', true)
                ->whereNotNull('credentials')
                ->exists();
            if ($fallbackOk && !in_array('quangia', $failedChannels, true)) {
                return 'quangia';
            }
        } catch (\Throwable $e) {
            Log::warning('[ButlerAutomation] resolveActiveZaloChannel fallback note: ' . $e->getMessage());
        }

        return $preferred;
    }

    public function resolveAgentForChannel(string $channelName): string
    {
        return $channelName === 'quangia'
            ? '01a1ac58-1bd8-7c30-b4c0-65f661aad8fc'
            : '01a1ac58-1bf3-7000-a68c-c6d36beaa62b';
    }

    /**
     * Ensure every provisioned Quản Gia receives the release-owned standard skill set.
     *
     * System skills are granted directly. Tenant-scoped standard skills receive a
     * tenant-local catalog row that references the canonical release-owned files.
     * The grant upsert pins the current standard version and is safe to rerun.
     *
     * @return array<string, int> slug => pinned version
     */
    public function ensureStandardButlerSkillsForAgent(string $tenantId, string $agentId): array
    {
        $db = DB::connection('butler_pgsql');
        $agent = $db->table('agents')
            ->select(['id', 'tenant_id', 'agent_key'])
            ->where('id', $agentId)
            ->where('tenant_id', $tenantId)
            ->where('agent_key', 'aicenty-master-butler')
            ->whereNull('deleted_at')
            ->first();

        if (!$agent) {
            throw new \InvalidArgumentException(
                "Agent {$agentId} không phải aicenty-master-butler đang hoạt động của tenant {$tenantId}."
            );
        }

        return $db->transaction(function () use ($db, $tenantId, $agentId): array {
            $sourceSkills = $db->table('skills')
                ->where('tenant_id', self::MASTER_TENANT_ID)
                ->whereIn('slug', self::STANDARD_BUTLER_SKILL_SLUGS)
                ->where('status', 'active')
                ->where('enabled', true)
                ->orderByDesc('version')
                ->get()
                ->unique('slug')
                ->keyBy('slug');

            $missing = array_values(array_diff(
                self::STANDARD_BUTLER_SKILL_SLUGS,
                $sourceSkills->keys()->all()
            ));
            if ($missing !== []) {
                throw new \RuntimeException('Thiếu standard Butler skills: ' . implode(', ', $missing));
            }

            $now = now();
            $grants = [];
            $pinned = [];

            foreach (self::STANDARD_BUTLER_SKILL_SLUGS as $slug) {
                $source = $sourceSkills->get($slug);
                $skillId = (string) $source->id;
                $version = (int) $source->version;

                if (!(bool) $source->is_system) {
                    $tenantSkill = $db->table('skills')
                        ->where('tenant_id', $tenantId)
                        ->where('slug', $slug)
                        ->first();

                    $catalog = [
                        'name'        => $source->name,
                        'description' => $source->description,
                        'owner_id'    => 'system',
                        'visibility'  => 'internal',
                        'version'     => $version,
                        'status'      => 'active',
                        'frontmatter' => $source->frontmatter,
                        'file_path'   => $source->file_path,
                        'file_size'   => $source->file_size,
                        'file_hash'   => $source->file_hash,
                        'tags'        => $source->tags,
                        'updated_at'  => $now,
                        'is_system'   => false,
                        'deps'        => $source->deps,
                        'enabled'     => true,
                    ];

                    if ($tenantSkill) {
                        $skillId = (string) $tenantSkill->id;
                        $db->table('skills')->where('id', $skillId)->update($catalog);
                    } else {
                        $skillId = (string) Str::uuid();
                        $db->table('skills')->insert(array_merge($catalog, [
                            'id'         => $skillId,
                            'slug'       => $slug,
                            'tenant_id'  => $tenantId,
                            'created_at' => $now,
                        ]));
                    }
                }

                $grants[] = [
                    'id'             => (string) Str::uuid(),
                    'skill_id'       => $skillId,
                    'agent_id'       => $agentId,
                    'pinned_version' => $version,
                    'granted_by'     => 'system:butler-provisioning',
                    'created_at'     => $now,
                    'tenant_id'      => $tenantId,
                    'can_manage'     => false,
                ];
                $pinned[$slug] = $version;
            }

            $db->table('skill_agent_grants')->upsert(
                $grants,
                ['skill_id', 'agent_id'],
                ['pinned_version', 'granted_by', 'tenant_id', 'can_manage']
            );

            $otherConfigRaw = $db->table('agents')
                ->where('id', $agentId)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->value('other_config');
            $otherConfig = is_string($otherConfigRaw)
                ? (json_decode($otherConfigRaw, true) ?: [])
                : (array) $otherConfigRaw;
            $existingPinned = is_array($otherConfig['pinned_skills'] ?? null)
                ? $otherConfig['pinned_skills']
                : [];
            $otherConfig['pinned_skills'] = array_slice(array_values(array_unique(array_merge(
                self::STANDARD_BUTLER_SKILL_SLUGS,
                array_map('strval', $existingPinned)
            ))), 0, 10);
            $otherConfig['allow_image_generation'] = true;
            $otherConfig['standard_capabilities_version'] = '2026-11-13';
            $db->table('agents')
                ->where('id', $agentId)
                ->where('tenant_id', $tenantId)
                ->update([
                    'other_config' => json_encode($otherConfig, JSON_UNESCAPED_UNICODE),
                    'updated_at' => $now,
                ]);

            $verified = $db->table('skill_agent_grants as grant')
                ->join('skills as skill', 'skill.id', '=', 'grant.skill_id')
                ->where('grant.agent_id', $agentId)
                ->where('grant.tenant_id', $tenantId)
                ->whereIn('skill.slug', self::STANDARD_BUTLER_SKILL_SLUGS)
                ->distinct()
                ->count('skill.slug');
            if ($verified !== count(self::STANDARD_BUTLER_SKILL_SLUGS)) {
                throw new \RuntimeException(
                    "Provisioning standard skills không đầy đủ cho agent {$agentId}: {$verified}/"
                    . count(self::STANDARD_BUTLER_SKILL_SLUGS)
                );
            }
            $missingPinned = array_diff(
                self::STANDARD_BUTLER_SKILL_SLUGS,
                $otherConfig['pinned_skills']
            );
            if ($missingPinned !== []) {
                throw new \RuntimeException(
                    'Pinned skill prompt configuration không đầy đủ: ' . implode(', ', $missingPinned)
                );
            }

            Log::info('[ButlerAutomation] Standard skills provisioned', [
                'tenant_id' => $tenantId,
                'agent_id' => $agentId,
                'skills' => $pinned,
            ]);

            return $pinned;
        }, 3);
    }

    /**
     * Guarantee valid LLM & background summarization configuration on every provisioned family tenant.
     */
    protected function ensureTenantSystemConfigs($db, string $tenantId): void
    {
        $defaults = [
            'background.provider'    => 'google-ai-studio',
            'background.model'       => 'gemini-flash-lite-latest',
            'agent.default_provider' => 'google-ai-studio',
            'agent.default_model'    => 'gemini-3.8-flash',
        ];
        foreach ($defaults as $key => $val) {
            $db->table('system_configs')->updateOrInsert(
                ['tenant_id' => $tenantId, 'key' => $key],
                ['value' => $val, 'updated_at' => now()]
            );
        }
    }

    /**
     * Alert operations via log, Resend email, and Zalo admin channel for wrong-memo or underpayment transfers.
     */
    public function sendOpsAlert(array $txData): void
    {
        $txId    = (string) ($txData['transaction_id'] ?? ('TX_' . time()));
        $amount  = number_format((float) ($txData['amount'] ?? 0));
        $content = (string) ($txData['content'] ?? '');
        $accNum  = (string) ($txData['account_number'] ?? '837006868');
        $gateway = (string) ($txData['gateway'] ?? 'MBBank');

        Log::error("[ButlerOpsAlert] Manual review needed for TX {$txId}: {$amount}đ via {$gateway} ({$accNum}) | Content: {$content}");

        $alertMsg = "🚨 [OPS ALERT - CẦN XỬ LÝ TRONG 3 PHÚT]
"
            . "💰 Số tiền: {$amount} VNĐ ({$gateway} - STK {$accNum})
"
            . "🔖 Mã GD: {$txId}
"
            . "📝 Nội dung CK: {$content}
"
            . "⚠️ Lý do: Thiếu SĐT nhận diện hoặc dưới mức gói tối thiểu 350.000đ. Vui lòng kiểm tra đối soát thủ công ngay!";

        try {
            $resendKey = (string) env('RESEND_API_KEY', '');
            if ($resendKey !== '') {
                $fromAddr = (string) env('RESEND_FROM_ADDRESS', env('MAIL_FROM_ADDRESS', 'noreply@aicenty.com'));
                $fromName = (string) env('RESEND_FROM_NAME', 'Aicenty Butler Ops');
                $recipientsRaw = (string) env('RESEND_ADMIN_RECIPIENTS', 'support@aicenty.com');
                $recipients = array_values(array_filter(array_map('trim', explode(',', $recipientsRaw))));
                if (!empty($recipients)) {
                    \Illuminate\Support\Facades\Http::withToken($resendKey)->timeout(5)->post('https://api.resend.com/emails', [
                        'from'    => "{$fromName} <{$fromAddr}>",
                        'to'      => $recipients,
                        'subject' => "[URGENT OPS] Giao dịch cần đối soát: {$amount}đ ({$txId})",
                        'text'    => $alertMsg,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[ButlerOpsAlert] Email alert skipped: ' . $e->getMessage());
        }

        try {
            $activeChannel = $this->resolveActiveZaloChannel('butlerguru');
            $agentId = $this->resolveAgentForChannel($activeChannel);
            $targets = array_filter([
                env('ZALO_HNKT_GROUP_ID', ''),
                env('ZALO_ADMIN_NOTIFY_ID', '1398202431130007333'),
            ]);
            foreach ($targets as $targetId) {
                DB::connection('butler_pgsql')->table('cron_jobs')->insert([
                    'id'               => (string) Str::uuid(),
                    'agent_id'         => $agentId,
                    'user_id'          => 'system',
                    'name'             => 'ops-alert-' . preg_replace('/[^A-Za-z0-9_-]/', '', $txId) . '-' . time(),
                    'enabled'          => true,
                    'schedule_kind'    => 'at',
                    'run_at'           => now(),
                    'next_run_at'      => now()->addSeconds(5),
                    'payload'          => json_encode(['kind' => 'agent_turn', 'message' => $alertMsg], JSON_UNESCAPED_UNICODE),
                    'delete_after_run' => true,
                    'tenant_id'        => '0193a5b0-7000-7000-8000-000000000001',
                    'deliver'          => true,
                    'deliver_channel'  => $activeChannel,
                    'deliver_to'       => (string) $targetId,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('[ButlerOpsAlert] Zalo alert skipped: ' . $e->getMessage());
        }
    }
}
