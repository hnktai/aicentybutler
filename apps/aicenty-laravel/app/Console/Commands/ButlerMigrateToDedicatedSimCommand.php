<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ButlerAutomationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ButlerMigrateToDedicatedSimCommand extends Command
{
    protected $signature = 'butler:migrate-to-dedicated-sim 
                            {tenant_id : UUID của Tenant gia đình} 
                            {sim_phone : Số điện thoại của SIM Quản gia riêng}';

    protected $description = 'Bàn giao và kích hoạt SIM Riêng cho Tenant gia đình: Tự động Re-map phiên chat, nạp bản tóm tắt ký ức 7 ngày và chuyển đổi trọn vẹn 100%';

    public function handle(ButlerAutomationService $automationService): int
    {
        $tenantId = trim((string) $this->argument('tenant_id'));
        $simPhone = trim((string) $this->argument('sim_phone'));
        $cleanPhone = preg_replace('/[^0-9]/', '', $simPhone);

        $this->info("=== BẮT ĐẦU QUY TRÌNH BÀN GIAO SIM RIÊNG CHO GIA ĐÌNH ===");
        $this->line("Tenant ID : {$tenantId}");
        $this->line("SIM Phone : {$cleanPhone}");

        try {
            $db = DB::connection('butler_pgsql');

            $tenant = $db->table('tenants')->where('id', $tenantId)->first();
            if (!$tenant) {
                $this->error("Lỗi: Không tìm thấy Tenant với ID: {$tenantId}");
                return 1;
            }

            $this->info("Đang kiểm tra và kích hoạt Channel SIM riêng...");
            $result = $automationService->registerDedicatedSimChannel($tenantId, $cleanPhone, [
                'status'  => 'active',
                'network' => 'SIM Chuyên Dụng 4G/Zalo',
            ]);

            $this->info("✓ Channel Name      : " . $result['channel_name']);
            $this->info("✓ Dedicated Agent   : " . $result['agent_id']);
            $this->info("✓ Sessions Remapped : " . $result['remapped_sessions'] . " phiên chat");
            $this->info("✓ Family User IDs   : " . implode(', ', $result['family_user_ids']));
            $this->info("✓ Ký Ức 7 Ngày      : Đã compile và nạp vào episodic_summaries & memory_documents");

            // Tạo cron job phát thông báo chào mừng chuyển giao SIM thành công (tùy chọn)
            $primaryUid = $result['family_user_ids'][0] ?? null;
            if ($primaryUid && !str_starts_with($primaryUid, 'phone_')) {
                $tenantSettings = is_string($tenant->settings) ? json_decode($tenant->settings, true) : (array) $tenant->settings;
                $familyName = $tenant->name ?? 'Gia Đình';

                $welcomeMsg = "🎩 Kính chào Quý Gia Chủ! Quản gia riêng của {$familyName} đã chính thức có mặt trên số Zalo chuyên dụng này ({$cleanPhone}).

"
                    . "Toàn bộ thông tin học tập của các bé và nề nếp gia đình trong 7 ngày qua Quản gia đều đã ghi nhớ trọn vẹn. Quản gia đã sẵn sàng nhận lệnh phục vụ tổ ấm 24/7 ạ!";

                $db->table('cron_jobs')->insert([
                    'id'               => (string) Str::uuid(),
                    'agent_id'         => $result['agent_id'],
                    'user_id'          => $primaryUid,
                    'name'             => 'welcome-dedicated-sim-' . $cleanPhone . '-' . time(),
                    'enabled'          => true,
                    'schedule_kind'    => 'at',
                    'run_at'           => now(),
                    'next_run_at'      => now()->addSeconds(10),
                    'payload'          => json_encode(['kind' => 'agent_turn', 'message' => $welcomeMsg], JSON_UNESCAPED_UNICODE),
                    'delete_after_run' => true,
                    'tenant_id'        => $tenantId,
                    'deliver'          => true,
                    'deliver_channel'  => $result['channel_name'],
                    'deliver_to'       => $primaryUid,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
                $this->info("✓ Đã lên lịch tin nhắn chào mừng đầu tiên từ SIM mới đến Gia chủ ({$primaryUid}).");
            }

            $this->info("=== HOÀN TẤT CHUYỂN GIAO SIM RIÊNG 100% THÀNH CÔNG ===");
            return 0;
        } catch (\Throwable $e) {
            $this->error("Lỗi khi bàn giao SIM riêng: " . $e->getMessage());
            Log::error("[Butler Dedicated SIM] Failed: " . $e->getMessage());
            return 1;
        }
    }
}
