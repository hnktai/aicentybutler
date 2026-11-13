<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ButlerTrialMailer
{
    private const ZALO_QUAN_GIA = '0961592763';
    private const ZALO_OA_URL   = 'https://zalo.me/97838442689718827';
    private const WEB_PORTAL    = 'https://butler.aicenty.com';

    /**
     * Tạo Magic Link đăng nhập 1 chạm vào hệ thống Quản Gia (không cần nhập user & token).
     */
    public static function buildMagicLink(string $phone, string $tenantSlug = ''): string
    {
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        $slug = ($tenantSlug !== '') ? $tenantSlug : 'master';
        $gatewayToken = (string) env('BUTLER_GATEWAY_TOKEN', '');
        return self::WEB_PORTAL . '/?token=' . urlencode($gatewayToken) . '&userId=' . urlencode('phone_' . $cleanPhone) . '&tenant=' . urlencode($slug);
    }

    /**
     * Gửi email thông báo cho Admin và email xác nhận cho phụ huynh đăng ký trải nghiệm miễn phí.
     */
    public function send(array $data): void
    {
        $key = (string) (config('services.resend.key') ?: env('RESEND_API_KEY'));
        $from = (string) (config('services.resend.from') ?: env('RESEND_FROM_ADDRESS', 'ai@aicenty.com'));
        $fromName = (string) (config('services.resend.from_name') ?: env('RESEND_FROM_NAME', 'Aicenty Butler'));

        if ($key === '') {
            Log::warning('[ButlerTrialMailer] RESEND_API_KEY không tồn tại, bỏ qua gửi email.');
            return;
        }

        $parentName = trim((string) ($data['name'] ?? 'Gia Chủ'));
        $parentEmail = trim((string) ($data['email'] ?? ''));
        $parentPhone = trim((string) ($data['phone'] ?? ''));
        $butlerName = trim((string) ($data['butler_name'] ?? 'Quản Gia Riêng Gia Đình'));
        $childInfo = trim((string) ($data['child_info'] ?? 'Chưa rõ'));
        $primaryConcern = trim((string) ($data['primary_concern'] ?? 'Quản xuyến toàn diện gia đình'));
        $note = trim((string) ($data['note'] ?? ''));
        $affiliateRef = trim((string) ($data['affiliate_ref'] ?? ''));
        $cleanPhone = preg_replace('/[^0-9]/', '', $parentPhone);
        $magicLink = self::buildMagicLink($parentPhone);

        // 1. GỬI EMAIL CHO ADMIN (hnkt@hnkt.vn, ai@aicenty.com, striverle@gmail.com)
        $adminRecipients = ['hnkt@hnkt.vn', 'ai@aicenty.com', 'striverle@gmail.com'];
        $adminSubject = "[Aicenty Butler] Gia Chủ Đăng Ký Trải Nghiệm Quản Gia Miễn Phí: {$parentName} - {$parentPhone}";

        $familyMembers = trim((string) ($data['family_members'] ?? ''));

        $adminHtml = "<div style='font-family: Arial, sans-serif; max-width: 620px; margin: 0 auto; line-height: 1.6; color: #1e293b;'>"
            . "<div style='background: #08080a; padding: 24px 20px 18px; text-align: center; border-radius: 8px 8px 0 0; border-bottom: 2px solid #22d3ee;'>"
            . "<a href='https://aicenty.com/butler' target='_blank' style='text-decoration: none; display: inline-block;'>"
            . "<img src='https://aicenty.com/assets/butler/aicenty-butler-logo.png' alt='Aicenty Butler' width='160' style='max-width: 160px; width: 160px; height: auto; display: block; margin: 0 auto 10px; border: 0; outline: none;' />"
            . "</a>"
            . "<h2 style='color: #22d3ee; margin: 0; font-size: 16px; letter-spacing: 0.5px;'>ĐĂNG KÝ QUẢN GIA THỜI HIỆN ĐẠI (TRẢI NGHIỆM MIỄN PHÍ)</h2>"
            . "<p style='color: #94a3b8; margin: 4px 0 0; font-size: 12px;'>Hệ thống Quản gia Toàn năng Gia đình Việt</p>"
            . "</div>"
            . "<div style='background: #ffffff; padding: 26px 22px; border: 1px solid #e2e8f0; border-top: none; border-radius: 0 0 8px 8px;'>"
            . "<p style='font-size: 15px;'>Có một Gia Chủ vừa hoàn tất đăng ký <strong>Trải Nghiệm Quản Gia Thời Hiện Đại Hoàn Toàn Miễn Phí</strong>:</p>"
            . "<table style='width: 100%; border-collapse: collapse; margin: 18px 0; font-size: 14px;'>"
            . "<tr style='border-bottom: 1px solid #f1f5f9;'><td style='padding: 10px 0; color: #64748b; width: 170px;'>Họ tên Gia Chủ:</td><td style='padding: 10px 0; font-weight: bold; color: #0f172a;'>" . e($parentName) . "</td></tr>"
            . "<tr style='border-bottom: 1px solid #f1f5f9;'><td style='padding: 10px 0; color: #64748b;'>Số điện thoại / Zalo:</td><td style='padding: 10px 0; font-weight: bold; color: #0284c7;'>" . e($parentPhone) . "</td></tr>"
            . "<tr style='border-bottom: 1px solid #f1f5f9;'><td style='padding: 10px 0; color: #64748b;'>Email liên hệ:</td><td style='padding: 10px 0; font-weight: bold;'>" . e($parentEmail) . "</td></tr>"
            . ($familyMembers !== '' ? "<tr style='border-bottom: 1px solid #f1f5f9;'><td style='padding: 10px 0; color: #64748b;'>Thành viên gia đình:</td><td style='padding: 10px 0; font-weight: bold; color: #0284c7;'>" . e($familyMembers) . "</td></tr>" : '')
            . "<tr style='border-bottom: 1px solid #f1f5f9;'><td style='padding: 10px 0; color: #64748b;'>Tên Quản gia mong muốn:</td><td style='padding: 10px 0; font-weight: bold; color: #7c3aed;'>" . e($butlerName) . "</td></tr>"
            . "<tr style='border-bottom: 1px solid #f1f5f9;'><td style='padding: 10px 0; color: #64748b;'>Lớp / Tuổi của con:</td><td style='padding: 10px 0; font-weight: bold; color: #15803d;'>" . e($childInfo) . "</td></tr>"
            . "<tr style='border-bottom: 1px solid #f1f5f9;'><td style='padding: 10px 0; color: #64748b;'>Nhu cầu quán xuyến nhất:</td><td style='padding: 10px 0;'>" . e($primaryConcern) . "</td></tr>"
            . ($note !== '' ? "<tr style='border-bottom: 1px solid #f1f5f9;'><td style='padding: 10px 0; color: #64748b;'>Mong muốn riêng:</td><td style='padding: 10px 0; color: #b45309;'>" . nl2br(e($note)) . "</td></tr>" : '')
            . ($affiliateRef !== '' ? "<tr style='border-bottom: 1px solid #f1f5f9;'><td style='padding: 10px 0; color: #64748b;'>Affiliate Ref:</td><td style='padding: 10px 0;'>" . e($affiliateRef) . "</td></tr>" : '')
            . "<tr><td style='padding: 10px 0; color: #64748b;'>Thời gian đăng ký:</td><td style='padding: 10px 0;'>" . now()->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i:s') . "</td></tr>"
            . "</table>"
            . "<div style='background: #f8fafc; border-left: 4px solid #3b82f6; padding: 12px; margin-top: 16px; font-size: 13px; color: #475569;'>"
            . "Lead đã được tự động đồng bộ vào <strong>ERP Odoo erp.hnkt.vn</strong>."
            . "</div>"
            . "</div>"
            . "</div>";

        try {
            Http::timeout(15)->withToken($key)->post('https://api.resend.com/emails', [
                'from' => $fromName . ' <' . $from . '>',
                'to' => $adminRecipients,
                'reply_to' => $parentEmail ?: $from,
                'subject' => $adminSubject,
                'html' => $adminHtml,
            ]);
            Log::info("[ButlerTrialMailer] Đã gửi thông báo lead Butler thành công đến Admin: " . implode(', ', $adminRecipients));
        } catch (\Throwable $e) {
            Log::error("[ButlerTrialMailer] Lỗi khi gửi email thông báo Admin: " . $e->getMessage());
        }

        // 2. GỬI EMAIL XÁC NHẬN CHO KHÁCH HÀNG (GIA CHỦ) - TINH GỌN, 1-CHẠM, TỰ ĐỘNG ĐĂNG NHẬP
        if ($parentEmail !== '') {
            $customerSubject = "[Aicenty Butler] Xác Nhận Đăng Ký & Kích Hoạt Quản Gia Cho Gia Đình " . $parentName;

            $customerHtml = "<div style='font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; max-width: 580px; margin: 0 auto; line-height: 1.6; color: #1e293b; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden;'>"
                . "<div style='background: #08080a; padding: 26px 20px 20px; text-align: center; border-bottom: 2px solid #22d3ee;'>"
                . "<a href='https://aicenty.com/butler' target='_blank' style='text-decoration: none; display: inline-block;'>"
                . "<img src='https://aicenty.com/assets/butler/aicenty-butler-logo.png' alt='Aicenty Butler' width='160' style='max-width: 160px; width: 160px; height: auto; display: block; margin: 0 auto 10px; border: 0; outline: none;' />"
                . "</a>"
                . "<h2 style='color: #22d3ee; margin: 0; font-size: 16px; letter-spacing: 0.5px; text-transform: uppercase;'>XÁC NHẬN ĐĂNG KÝ THÀNH CÔNG</h2>"
                . "<p style='color: #94a3b8; margin: 4px 0 0; font-size: 12px;'>Hệ Thống Quản Gia Thời Hiện Đại Dành Cho Mọi Nhà</p>"
                . "</div>"
                . "<div style='padding: 26px 24px;'>"
                . "<p style='font-size: 15px; margin: 0 0 14px;'>Dạ em kính chào Quý Gia Chủ <strong>" . e($parentName) . "</strong>,</p>"
                . "<p style='font-size: 14.5px; color: #334155; margin: 0 0 20px;'>"
                . "Hệ thống Aicenty Butler đã ghi nhận yêu cầu đăng ký trải nghiệm Quản Gia riêng cho tổ ấm của mình. Quý Gia Chủ có thể bấm nút đăng nhập 1 chạm bên dưới để vào thẳng không gian quản lý:"
                . "</p>"
                . "<div style='text-align: center; margin: 24px 0 16px;'>"
                . "<a href='{$magicLink}' target='_blank' style='display: inline-block; background: #0284c7; color: #ffffff; text-decoration: none; padding: 14px 28px; font-weight: bold; font-size: 15px; border-radius: 9999px; box-shadow: 0 4px 14px rgba(2, 132, 199, 0.35); text-transform: uppercase; letter-spacing: 0.3px;'>👉 TRUY CẬP CỔNG QUẢN GIA (ĐĂNG NHẬP 1 CHẠM) ↗</a>"
                . "<p style='font-size: 12.5px; color: #64748b; margin: 8px 0 0;'><em>(Bấm nút trên để vào thẳng không gian Quản Gia, tự động đăng nhập không cần mật khẩu)</em></p>"
                . "</div>"
                . "<div style='text-align: center; margin: 16px 0 24px;'>"
                . "<a href='https://zalo.me/" . self::ZALO_QUAN_GIA . "' target='_blank' style='display: inline-block; background: #059669; color: #ffffff; text-decoration: none; padding: 12px 24px; font-weight: bold; font-size: 14px; border-radius: 9999px; box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3);'>💬 KẾT NỐI ZALO QUẢN GIA (0961 592 763) ↗</a>"
                . "</div>"
                . "<div style='background: #f8fafc; border: 1px solid #e2e8f0; border-left: 4px solid #0284c7; padding: 14px 16px; border-radius: 8px; margin-bottom: 18px; font-size: 13.5px; color: #334155;'>"
                . "<strong>Hướng dẫn kết nối tổ ấm:</strong><br>"
                . "Rất quan trọng: Gia chủ hãy kết bạn với Zalo Quản Gia (0961 592 763) trước, sau đó tạo nhóm Zalo gia đình và thêm Quản Gia cùng các thành viên vào nhóm nhé ạ!"
                . "</div>"
                . "<div style='background: #fefce8; border: 1px solid #fef08a; padding: 12px 14px; border-radius: 6px; margin-bottom: 20px; font-size: 13px; color: #854d0e; line-height: 1.5;'>"
                . "💡 <strong>Lưu ý:</strong> Gói trải nghiệm 7 ngày hoàn toàn miễn phí. Sau 7 ngày, Quý Gia chủ có thể nâng cấp sử dụng SIM riêng & kênh Zalo riêng biệt để đảm bảo tính riêng tư và bảo mật dữ liệu tuyệt đối cho gia đình."
                . "</div>"
                . "<div style='border-top: 1px solid #e2e8f0; padding-top: 14px; font-size: 12.5px; color: #64748b; line-height: 1.6; text-align: center;'>"
                . "Aicenty Butler kính chúc gia đình luôn dồi dào sức khỏe và an yên!<br>"
                . "Hotline hỗ trợ: <strong>0947 604 877</strong> • Website: <a href='https://aicenty.com/butler' target='_blank' style='color: #0284c7; text-decoration: none;'>aicenty.com/butler</a>"
                . "</div>"
                . "</div>"
                . "</div>";

            try {
                Http::timeout(15)->withToken($key)->post('https://api.resend.com/emails', [
                    'from' => $fromName . ' <' . $from . '>',
                    'to' => [$parentEmail],
                    'reply_to' => $from,
                    'subject' => $customerSubject,
                    'html' => $customerHtml,
                ]);
                Log::info("[ButlerTrialMailer] Đã gửi email xác nhận đăng ký thành công cho khách hàng: {$parentEmail}");
            } catch (\Throwable $e) {
                Log::error("[ButlerTrialMailer] Lỗi khi gửi email xác nhận: " . $e->getMessage());
            }
        }
    }

    public function sendPurchaseNotification(array $data): void
    {
        $key = (string) (config('services.resend.key') ?: env('RESEND_API_KEY'));
        $from = (string) (config('services.resend.from') ?: env('RESEND_FROM_ADDRESS', 'ai@aicenty.com'));
        $fromName = (string) (config('services.resend.from_name') ?: env('RESEND_FROM_NAME', 'Aicenty Butler'));

        if ($key === '') {
            Log::warning('[ButlerTrialMailer] RESEND_API_KEY không tồn tại, bỏ qua gửi email purchase.');
            return;
        }

        $parentName = trim((string) ($data['name'] ?? 'Gia Chủ'));
        $parentEmail = trim((string) ($data['email'] ?? ''));
        $parentPhone = trim((string) ($data['phone'] ?? ''));
        $planName = trim((string) ($data['plan_name'] ?? 'Gói Quản Gia'));
        $amount = (float) ($data['amount'] ?? 500000);
        $amountFormatted = number_format($amount, 0, ',', '.') . ' VNĐ';
        $isExistingTrial = !empty($data['is_existing_trial']);
        $tag = $isExistingTrial ? 'NÂNG CẤP MUA LUÔN' : 'MUA MỚI TRỰC TIẾP';

        $adminRecipients = ['hnkt@hnkt.vn', 'ai@aicenty.com', 'striverle@gmail.com'];
        $adminSubject = "[Aicenty Butler - {$tag}] {$parentName} - {$parentPhone} - {$planName} ({$amountFormatted})";

        $adminHtml = "<div style='font-family: Arial, sans-serif; max-width: 620px; margin: 0 auto; line-height: 1.6; color: #1e293b;'>"
            . "<div style='background: #08080a; padding: 24px 20px 18px; text-align: center; border-radius: 8px 8px 0 0; border-bottom: 2px solid #22d3ee;'>"
            . "<a href='https://aicenty.com/butler' target='_blank' style='text-decoration: none; display: inline-block;'>"
            . "<img src='https://aicenty.com/assets/butler/aicenty-butler-logo.png' alt='Aicenty Butler' width='160' style='max-width: 160px; width: 160px; height: auto; display: block; margin: 0 auto 10px; border: 0; outline: none;' />"
            . "</a>"
            . "<h2 style='color: #22d3ee; margin: 0; font-size: 16px;'>AICENTY BUTLER - {$tag}</h2>"
            . "<p style='color: #94a3b8; margin: 4px 0 0; font-size: 12px;'>Hệ thống Quản gia Toàn năng Gia đình Việt</p>"
            . "</div>"
            . "<div style='background: #ffffff; padding: 26px 22px; border: 1px solid #e2e8f0; border-top: none; border-radius: 0 0 8px 8px;'>"
            . "<p style='font-size: 15px;'>Gia Chủ vừa chọn gói mua và đang thanh toán SePay VietQR:</p>"
            . "<table style='width: 100%; border-collapse: collapse; margin: 18px 0; font-size: 14px;'>"
            . "<tr style='border-bottom: 1px solid #f1f5f9;'><td style='padding: 10px 0; color: #64748b; width: 170px;'>Họ tên Gia Chủ:</td><td style='padding: 10px 0; font-weight: bold; color: #0f172a;'>" . e($parentName) . "</td></tr>"
            . "<tr style='border-bottom: 1px solid #f1f5f9;'><td style='padding: 10px 0; color: #64748b;'>Số điện thoại / Zalo:</td><td style='padding: 10px 0; font-weight: bold; color: #0284c7;'>" . e($parentPhone) . "</td></tr>"
            . "<tr style='border-bottom: 1px solid #f1f5f9;'><td style='padding: 10px 0; color: #64748b;'>Gói đăng ký:</td><td style='padding: 10px 0; font-weight: bold; color: #16a34a;'>" . e($planName) . "</td></tr>"
            . "<tr style='border-bottom: 1px solid #f1f5f9;'><td style='padding: 10px 0; color: #64748b;'>Số tiền cần thanh toán:</td><td style='padding: 10px 0; font-weight: bold; color: #b91c1c; font-size: 16px;'>" . $amountFormatted . "</td></tr>"
            . "<tr style='border-bottom: 1px solid #f1f5f9;'><td style='padding: 10px 0; color: #64748b;'>Cú pháp chuyển khoản:</td><td style='padding: 10px 0; font-family: monospace; font-weight: bold;'>SUB " . e($parentPhone) . "</td></tr>"
            . "<tr style='border-bottom: 1px solid #f1f5f9;'><td style='padding: 10px 0; color: #64748b;'>Trạng thái:</td><td style='padding: 10px 0; color: #ea580c; font-weight: bold;'>" . ($isExistingTrial ? 'Khách đã trải nghiệm và muốn nâng cấp lên gói chính thức' : 'Khách hàng mua mới trực tiếp') . "</td></tr>"
            . "</table>"
            . "<p style='font-size: 13px; color: #64748b;'>Thông tin tài khoản nhận tiền: MBBank 837006868 - NGUYEN THANH HUNG.</p>"
            . "</div>"
            . "</div>";

        try {
            Http::withToken($key)
                ->withHeaders(['User-Agent' => 'Resend-PHP/0.1'])
                ->timeout(10)
                ->asJson()
                ->post('https://api.resend.com/emails', [
                    'from' => "{$fromName} <{$from}>",
                    'to' => $adminRecipients,
                    'subject' => $adminSubject,
                    'html' => $adminHtml,
                ]);
        } catch (\Throwable $e) {
            Log::error('[ButlerTrialMailer] Lỗi gửi email admin: ' . $e->getMessage());
        }

        // 2. GỬI EMAIL HƯỚNG DẪN HOÀN TẤT THANH TOÁN CHO KHÁCH HÀNG
        if ($parentEmail !== '') {
            $customerSubject = "[Aicenty Butler] Hướng Dẫn Hoàn Tất Thanh Toán Đăng Ký Quản Gia - {$planName}";
            $syntax = "SUB {$parentPhone}";
            $qrUrl = "https://qr.sepay.vn/img?acc=837006868&bank=MB&amount={$amount}&des=" . urlencode($syntax);
            $planKey = $data['plan'] ?? '1m';
            $paymentUrl = "https://aicenty.com/butler/payment?" . http_build_query([
                'name'      => $parentName,
                'phone'     => $parentPhone,
                'email'     => $parentEmail,
                'plan'      => $planKey,
                'plan_name' => $planName,
                'amount'    => $amount,
            ]);

            $customerHtml = "<div style='font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; max-width: 580px; margin: 0 auto; line-height: 1.6; color: #1e293b; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden;'>"
                . "<div style='background: #08080a; padding: 24px 20px 20px; text-align: center; border-bottom: 2px solid #22d3ee;'>"
                . "<a href='https://aicenty.com/butler' target='_blank' style='text-decoration: none; display: inline-block;'>"
                . "<img src='https://aicenty.com/assets/butler/aicenty-butler-logo.png' alt='Aicenty Butler' width='160' style='max-width: 160px; width: 160px; height: auto; display: block; margin: 0 auto 10px; border: 0; outline: none;' />"
                . "</a>"
                . "<h2 style='color: #22d3ee; margin: 0; font-size: 16px; letter-spacing: 0.5px; text-transform: uppercase;'>HƯỚNG DẪN HOÀN TẤT THANH TOÁN</h2>"
                . "<p style='color: #94a3b8; margin: 4px 0 0; font-size: 12px;'>Gói Dịch Vụ: " . e($planName) . " (" . $amountFormatted . ")</p>"
                . "</div>"
                . "<div style='padding: 24px 22px;'>"
                . "<p style='font-size: 15px; margin: 0 0 14px;'>Kính chào Quý Gia Chủ <strong>" . e($parentName) . "</strong>,</p>"
                . "<p style='font-size: 14px; color: #334155; margin: 0 0 18px;'>"
                . "Để hoàn tất đăng ký sở hữu <strong>Quản Gia Thời Hiện Đại</strong>, Quý Gia chủ vui lòng thanh toán quét mã QR bên dưới:"
                . "</p>"
                . "<div style='background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 18px; text-align: center; margin-bottom: 18px;'>"
                . "<span style='background: #0284c7; color: #ffffff; padding: 3px 12px; border-radius: 9999px; font-size: 12px; font-weight: bold;'>QUÉT MÃ VIETQR SEPAY TỰ ĐỘNG</span>"
                . "<div style='display: inline-block; background: #ffffff; padding: 10px; border-radius: 10px; border: 1px solid #cbd5e1; margin: 12px auto;'>"
                . "<img src='{$qrUrl}' alt='VietQR SePay' width='210' style='display: block; width: 210px; height: auto; margin: 0 auto;' />"
                . "</div>"
                . "<div>"
                . "<a href='{$paymentUrl}' target='_blank' style='display: inline-block; background: #0284c7; color: #ffffff; text-decoration: none; padding: 11px 22px; font-weight: bold; font-size: 14px; border-radius: 9999px;'>MỞ TRANG THANH TOÁN ONLINE ↗</a>"
                . "</div>"
                . "</div>"
                . "<div style='background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 16px; margin-bottom: 18px; font-size: 13.5px;'>"
                . "<strong>Chuyển khoản thủ công:</strong> MB (Quân Đội) — STK: <strong>837006868</strong> (NGUYEN THANH HUNG)<br>"
                . "Số tiền: <strong style='color: #b91c1c;'>{$amountFormatted}</strong> | Cú pháp: <code style='background: #fef08a; padding: 2px 6px; border-radius: 4px; font-weight: bold;'>{$syntax}</code>"
                . "</div>"
                . "<div style='border-top: 1px solid #e2e8f0; padding-top: 14px; font-size: 12.5px; color: #64748b; line-height: 1.6; text-align: center;'>"
                . "Hỗ trợ thanh toán & kích hoạt: <a href='" . self::ZALO_OA_URL . "' target='_blank' style='color: #0284c7; font-weight: bold;'>Zalo Doanh Nghiệp Aicenty ↗</a> hoặc Hotline: <strong>0947 604 877</strong>"
                . "</div>"
                . "</div>"
                . "</div>";

            try {
                Http::withToken($key)
                    ->withHeaders(['User-Agent' => 'Resend-PHP/0.1'])
                    ->timeout(12)
                    ->asJson()
                    ->post('https://api.resend.com/emails', [
                        'from'     => "{$fromName} <{$from}>",
                        'to'       => [$parentEmail],
                        'reply_to' => $from,
                        'subject'  => $customerSubject,
                        'html'     => $customerHtml,
                    ]);
                Log::info("[ButlerTrialMailer] Đã gửi email hướng dẫn thanh toán cho khách hàng: {$parentEmail}");
            } catch (\Throwable $e) {
                Log::error("[ButlerTrialMailer] Lỗi khi gửi email hướng dẫn thanh toán: " . $e->getMessage());
            }
        }
    }

    /**
     * Gửi email xác nhận thanh toán và đường dẫn hỗ trợ tạm thời.
     */
    public function sendSubscriptionActivated(array $data): void
    {
        $key = (string) (config('services.resend.key') ?: env('RESEND_API_KEY'));
        $from = (string) (config('services.resend.from') ?: env('RESEND_FROM_ADDRESS', 'ai@aicenty.com'));
        $fromName = (string) (config('services.resend.from_name') ?: env('RESEND_FROM_NAME', 'Aicenty Butler'));

        if ($key === '') {
            Log::warning('[ButlerTrialMailer] RESEND_API_KEY không tồn tại.');
            return;
        }

        $name = trim((string) ($data['name'] ?? 'Gia Chủ'));
        $email = trim((string) ($data['email'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));
        $planName = trim((string) ($data['plan_name'] ?? 'Gói Quản Gia'));
        $expireDate = (string) ($data['expire_date'] ?? '');
        $amountFormatted = number_format((float) ($data['amount'] ?? 0), 0, ',', '.') . ' VNĐ';
        $tenantSlug = (string) ($data['tenant_slug'] ?? $data['tenant'] ?? '');
        $magicLink = self::buildMagicLink($phone, $tenantSlug);

        // 1. Email cho khách hàng.
        if (!empty($email)) {
            $customerSubject = "[Aicenty Butler] Kích Hoạt Thành Công {$planName} - Chúc Mừng Gia Đình!";
            $customerHtml = "<div style='font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; max-width: 580px; margin: 0 auto; line-height: 1.6; color: #1e293b; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden;'>"
                . "<div style='background: #08080a; padding: 26px 20px 20px; text-align: center; border-bottom: 2px solid #22d3ee;'>"
                . "<a href='https://aicenty.com/butler' target='_blank' style='text-decoration: none; display: inline-block;'>"
                . "<img src='https://aicenty.com/assets/butler/aicenty-butler-logo.png' alt='Aicenty Butler' width='160' style='max-width: 160px; width: 160px; height: auto; display: block; margin: 0 auto 10px; border: 0; outline: none;' />"
                . "</a>"
                . "<h2 style='color: #22d3ee; margin: 0; font-size: 16px; text-transform: uppercase;'>KÍCH HOẠT QUẢN GIA THÀNH CÔNG</h2>"
                . "<p style='color: #94a3b8; margin: 4px 0 0; font-size: 12px;'>Không Gian Riêng Của Gia Đình Đã Sẵn Sàng</p>"
                . "</div>"
                . "<div style='padding: 26px 24px;'>"
                . "<p style='font-size: 15px; margin: 0 0 12px;'>Dạ em kính chào Quý Gia Chủ <strong>" . e($name) . "</strong>,</p>"
                . "<p style='font-size: 14.5px; color: #334155; margin: 0 0 16px;'>"
                . "Hệ thống đã nhận thanh toán <strong>{$amountFormatted}</strong> và chính thức kích hoạt gói <strong>" . e($planName) . "</strong> cho gia đình bạn (Thời hạn đến: <strong>" . e($expireDate) . "</strong>)."
                . "</p>"
                . "<div style='text-align: center; margin: 24px 0 16px;'>"
                . "<a href='{$magicLink}' target='_blank' style='display: inline-block; background: #0284c7; color: #ffffff; text-decoration: none; padding: 14px 28px; font-weight: bold; font-size: 15px; border-radius: 9999px; box-shadow: 0 4px 14px rgba(2, 132, 199, 0.35); text-transform: uppercase; letter-spacing: 0.3px;'>TRUY CẬP CỔNG QUẢN GIA ↗</a>"
                . "<p style='font-size: 12.5px; color: #64748b; margin: 8px 0 0;'><em>Đăng nhập cổng Quản Gia gia đình bằng tài khoản đã đăng ký để xem báo cáo và cấu hình.</em></p>"
                . "</div>"
                . "<div style='background: #f8fafc; border: 1px solid #e2e8f0; border-left: 4px solid #0284c7; padding: 14px 16px; border-radius: 8px; margin: 20px 0; font-size: 13.5px; color: #334155;'>"
                . "<strong>Kênh Chăm Sóc & Hỗ Trợ 1-1:</strong><br>"
                . "Đội ngũ chuyên viên luôn sẵn sàng đồng hành cùng gia đình qua <a href='" . self::ZALO_OA_URL . "' target='_blank' style='color: #0284c7; font-weight: bold; text-decoration: none;'>Zalo Doanh Nghiệp (OA) Aicenty ↗</a>."
                . "</div>"
                . "<div style='border-top: 1px solid #e2e8f0; padding-top: 14px; font-size: 12.5px; color: #64748b; line-height: 1.6; text-align: center;'>"
                . "Aicenty Butler kính chúc gia đình luôn dồi dào sức khỏe và hạnh phúc!<br>"
                . "Hotline hỗ trợ: <strong>0947 604 877</strong> • Website: <a href='https://aicenty.com/butler' target='_blank' style='color: #0284c7; text-decoration: none;'>aicenty.com/butler</a>"
                . "</div>"
                . "</div>"
                . "</div>";

            try {
                Http::withToken($key)->asJson()->post('https://api.resend.com/emails', [
                    'from' => "{$fromName} <{$from}>",
                    'to' => [$email],
                    'subject' => $customerSubject,
                    'html' => $customerHtml,
                ]);
                Log::info("[ButlerTrialMailer] Đã gửi email kích hoạt dịch vụ thành công tới {$email}");
            } catch (\Throwable $e) {
                Log::error("[ButlerTrialMailer] Lỗi gửi email kích hoạt: " . $e->getMessage());
            }
        }

        // 2. Email cho Admin
        $adminRecipients = ['hnkt@hnkt.vn', 'ai@aicenty.com', 'striverle@gmail.com'];
        $adminSubject = "[Aicenty Butler] Khách Đã Thanh Toán Gói Quản Gia: {$name} ({$phone}) - {$amountFormatted}";
        $adminHtml = "<div style='font-family: Arial, sans-serif; padding: 20px;'>"
            . "<h3>Khách hàng vừa thanh toán thành công qua SePay:</h3>"
            . "<ul>"
            . "<li><strong>Họ tên:</strong> " . e($name) . "</li>"
            . "<li><strong>SĐT:</strong> " . e($phone) . "</li>"
            . "<li><strong>Email:</strong> " . e($email) . "</li>"
            . "<li><strong>Gói cước:</strong> " . e($planName) . "</li>"
            . "<li><strong>Số tiền:</strong> {$amountFormatted}</li>"
            . "<li><strong>Thời hạn đến:</strong> {$expireDate}</li>"
            . "</ul>"
            . "</div>";

        try {
            Http::withToken($key)->asJson()->post('https://api.resend.com/emails', [
                'from' => "{$fromName} <{$from}>",
                'to' => $adminRecipients,
                'subject' => $adminSubject,
                'html' => $adminHtml,
            ]);
        } catch (\Throwable $e) {
            Log::error("[ButlerTrialMailer] Lỗi gửi email admin: " . $e->getMessage());
        }
    }

    /**
     * Gửi email bàn giao sau onboarding.
     */
    public function sendTrialCredentials(array $data): void
    {
        $key = (string) (config('services.resend.key') ?: env('RESEND_API_KEY'));
        $from = (string) (config('services.resend.from') ?: env('RESEND_FROM_ADDRESS', 'ai@aicenty.com'));
        $fromName = (string) (config('services.resend.from_name') ?: env('RESEND_FROM_NAME', 'Aicenty Butler'));

        if ($key === '') {
            Log::warning('[ButlerTrialMailer] RESEND_API_KEY không tồn tại.');
            return;
        }

        $phone = trim((string) ($data['phone'] ?? ''));
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        $name = trim((string) ($data['name'] ?? ''));

        // Tra cứu chính xác họ tên mà khách đã điền trên Form đăng ký (quangia_pending_handover)
        try {
            $db = \Illuminate\Support\Facades\DB::connection('butler_pgsql');
            if ($cleanPhone !== '') {
                $hRow = $db->table('quangia_pending_handover')
                    ->where('phone', $cleanPhone)
                    ->orderByDesc('created_at')
                    ->first();
                if ($hRow && !empty($hRow->name) && !in_array($hRow->name, ['Gia Chủ', 'Quý Gia Chủ'])) {
                    $name = trim($hRow->name);
                }
            }
        } catch (\Throwable $e) {
            // fallback gracefully
        }

        if (empty($name)) {
            $name = 'Gia Chủ';
        }

        $email = trim((string) ($data['email'] ?? ''));
        $familyName = trim((string) ($data['family_name'] ?? ('Gia Đình ' . $name)));
        $butlerName = trim((string) ($data['butler_name'] ?? 'Bác Phúc'));
        $tenantSlug = (string) ($data['tenant_slug'] ?? $data['tenant'] ?? '');
        $magicLink = self::buildMagicLink($phone, $tenantSlug);

        if (empty($email)) {
            return;
        }

        $subject = "[Aicenty Butler] Quản Gia AI Của {$familyName} Đã Sẵn Sàng!";
        // Giao diện Email thanh lịch, tinh gọn, không màu mè, chuẩn mực
        $html = "<div style='font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; max-width: 560px; margin: 0 auto; line-height: 1.6; color: #1e293b; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;'>"
            . "<div style='background: #08080a; padding: 24px 20px 20px; text-align: center; border-bottom: 2px solid #22d3ee;'>\n"
            . "<a href='https://aicenty.com/butler' target='_blank' style='text-decoration: none; display: inline-block;'>\n"
            . "<img src='https://aicenty.com/assets/butler/aicenty-butler-logo.png' alt='Aicenty Butler' width='160' style='max-width: 160px; width: 160px; height: auto; display: block; margin: 0 auto; border: 0; outline: none;' />\n"
            . "</a>\n"
            . "</div>\n"
            . "<div style='padding: 22px 20px 16px; text-align: center; border-bottom: 1px solid #f1f5f9; background: #ffffff;'>\n"
            . "<h2 style='color: #0f172a; margin: 0; font-size: 16px; font-weight: 700; letter-spacing: 0.3px;'>QUẢN GIA AI ĐÃ SẴN SÀNG</h2>\n"
            . "<p style='color: #64748b; margin: 4px 0 0; font-size: 13px;'>Không gian riêng của {$familyName}</p>\n"
            . "</div>\n"
            . "<div style='padding: 24px 22px;'>"
            . "<p style='font-size: 15px; margin: 0 0 12px;'>Dạ em kính chào Quý Gia Chủ <strong>" . e($name) . "</strong>,</p>"
            . "<p style='font-size: 14px; color: #334155; margin: 0 0 20px;'>"
            . "Hệ thống đã hoàn tất khởi tạo không gian riêng cho <strong>" . e($familyName) . "</strong> với Quản gia đồng hành <strong>" . e($butlerName) . "</strong>."
            . "</p>"
            . "<div style='text-align: center; margin: 24px 0 14px;'>"
            . "<a href='https://zalo.me/" . self::ZALO_QUAN_GIA . "' target='_blank' style='display: inline-block; background: #0284c7; color: #ffffff; text-decoration: none; padding: 13px 28px; font-weight: 700; font-size: 14.5px; border-radius: 6px; box-shadow: 0 3px 10px rgba(2, 132, 199, 0.3); text-transform: uppercase; letter-spacing: 0.3px;'>BẤM VÀO ĐÂY KÍCH HOẠT QUẢN GIA NGAY ↗</a>"
            . "</div>"
            . "<div style='text-align: center; margin: 0 0 22px;'>"
            . "<a href='{$magicLink}' target='_blank' style='display: inline-block; background: #0f172a; color: #ffffff; text-decoration: none; padding: 11px 26px; font-weight: 600; font-size: 13.5px; border-radius: 6px;'>TRUY CẬP CỔNG QUẢN GIA ↗</a>"
            . "</div>"
            . "<div style='background: #f8fafc; border: 1px solid #e2e8f0; padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-size: 13px; color: #475569;'>"
            . "<strong>Kênh hỗ trợ 24/7:</strong> Quý Gia Chủ có thể kết nối qua <a href='" . self::ZALO_OA_URL . "' target='_blank' style='color: #0284c7; text-decoration: none; font-weight: 600;'>Zalo Doanh Nghiệp (OA) Aicenty ↗</a> hoặc liên hệ Hotline <strong>0947 604 877</strong>."
            . "</div>"
            . "<div style='border-top: 1px solid #f1f5f9; padding-top: 14px; font-size: 12px; color: #94a3b8; text-align: center;'>"
            . "Aicenty Butler • Hệ thống Quản gia Toàn năng Gia đình Việt<br>"
            . "Website: <a href='https://aicenty.com/butler' target='_blank' style='color: #64748b; text-decoration: none;'>aicenty.com/butler</a>"
            . "</div>"
            . "</div>"
            . "</div>";

        try {
            Http::withToken($key)->asJson()->post('https://api.resend.com/emails', [
                'from'    => "{$fromName} <{$from}>",
                'to'      => [$email],
                'subject' => $subject,
                'html'    => $html,
            ]);
            Log::info("[ButlerTrialMailer] Đã gửi email bàn giao tài khoản portal tới {$email}");
        } catch (\Throwable $e) {
            Log::error("[ButlerTrialMailer] Lỗi gửi email bàn giao: " . $e->getMessage());
        }
    }
}
