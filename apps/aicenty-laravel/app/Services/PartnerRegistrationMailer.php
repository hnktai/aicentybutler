<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PartnerRegistrationMailer
{
    public function send(array $data): void
    {
        $key = (string) config('services.resend.key');
        $from = (string) config('services.resend.from_address', 'ai@aicenty.com');
        $fromName = (string) config('services.resend.from_name', 'Aicenty');

        if ($key === '') {
            Log::warning('Resend API key missing for partner registration mailer');
            return;
        }

        $partnerEmail = trim((string) ($data['email'] ?? ''));
        $partnerName = trim((string) ($data['name'] ?? 'Đối tác'));
        $partnerPhone = trim((string) ($data['phone'] ?? ''));
        $programKey = (string) ($data['program'] ?? 'affiliate');
        $programName = ($programKey === 'consultant') 
            ? 'Consultant Partner (Tư vấn, White-label & Đồng hành)' 
            : 'Affiliate Partner (Kết nối nhu cầu)';
        $role = trim((string) ($data['role'] ?? 'Chưa cung cấp'));
        $note = trim((string) ($data['note'] ?? 'Không có'));
        $now = now()->setTimezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i:s');

        $adminRecipients = ['striverle@gmail.com', 'hnkt@hnkt.vn'];

        // 1. Send Admin Notification Email
        $adminSubject = "[AICENTY PARTNER] Đăng ký đối tác mới: {$partnerName} ({$programName})";
        $adminHtml = "<div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #1e293b; line-height: 1.6;'>"
            . "<h2 style='color: #0284c7; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px;'>Thông tin đăng ký Đối tác mới trên Aicenty</h2>"
            . "<p><strong>Họ và tên đối tác:</strong> " . e($partnerName) . "</p>"
            . "<p><strong>Số điện thoại (Zalo):</strong> " . e($partnerPhone) . "</p>"
            . "<p><strong>Email đối tác:</strong> <a href='mailto:" . e($partnerEmail) . "'>" . e($partnerEmail) . "</a></p>"
            . "<p><strong>Chương trình đăng ký:</strong> <span style='background:#e0f2fe; color:#0369a1; padding:3px 8px; border-radius:4px; font-weight:bold;'>" . e($programName) . "</span></p>"
            . "<p><strong>Vai trò / Nghề nghiệp:</strong> " . e($role) . "</p>"
            . "<p><strong>Tập khách hàng / Kế hoạch hợp tác:</strong><br><div style='background:#f8fafc; padding:12px; border-radius:6px; border:1px solid #e2e8f0;'>" . nl2br(e($note)) . "</div></p>"
            . "<p style='font-size: 13px; color: #64748b;'>Thời gian đăng ký: " . e($now) . " (Asia/Ho_Chi_Minh)</p>"
            . "<hr style='border:none; border-top:1px solid #e2e8f0; margin: 20px 0;'>"
            . "<p style='font-size: 12px; color: #94a3b8;'>Email thông báo tự động từ hệ thống Aicenty Partner (aicenty.com/partner)</p>"
            . "</div>";

        try {
            Http::timeout(15)->withToken($key)->post('https://api.resend.com/emails', [
                'from' => $fromName . ' <' . $from . '>',
                'to' => $adminRecipients,
                'reply_to' => $partnerEmail,
                'subject' => $adminSubject,
                'html' => $adminHtml,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to send partner registration admin email: ' . $e->getMessage());
        }

        // 2. Send Partner Confirmation Email
        if ($partnerEmail !== '') {
            $partnerSubject = "AICENTY - Xác nhận đăng ký Chương trình Đối tác Kinh doanh";
            
            $policyHighlights = ($programKey === 'consultant')
                ? "<ul style='padding-left: 20px; margin: 8px 0;'>"
                    . "<li><strong>Phần mềm Aicenty AI:</strong> Hoa hồng <strong>40% trực tiếp</strong> và <strong>10% gián tiếp</strong> hàng tháng trọn đời.</li>"
                    . "<li><strong>Dịch vụ Aicenty OPC & Enterprise:</strong> Hoa hồng lên đến <strong>40%</strong> theo bậc doanh số (20% - 30% - 40%).</li>"
                    . "<li><strong>Đặc quyền White-label 100%:</strong> Đội ngũ Aicenty làm việc với khách hàng của bạn dưới thương hiệu của chính bạn, khách hàng không biết Aicenty là ai.</li>"
                    . "<li><strong>Thanh toán định kỳ:</strong> Ngày 15 hàng tháng qua tài khoản ngân hàng.</li>"
                                        . "</ul>"
                : "<ul style='padding-left: 20px; margin: 8px 0;'>"
                    . "<li><strong>Phần mềm Aicenty AI:</strong> Hoa hồng <strong>30% trực tiếp</strong> và <strong>5% gián tiếp</strong> hàng tháng trọn đời.</li>"
                    . "<li><strong>Dịch vụ Aicenty OPC & Enterprise:</strong> Hoa hồng lên đến <strong>15%</strong> theo bậc doanh số (8% - 10% - 15%).</li>"
                    . "<li><strong>Hỗ trợ toàn diện:</strong> Bạn chỉ cần kết nối khách hàng, chuyên gia Aicenty demo và chốt hợp đồng.</li>"
                    . "<li><strong>Thanh toán định kỳ:</strong> Ngày 15 hàng tháng qua tài khoản ngân hàng.</li>"
                                        . "</ul>";

            $partnerHtml = "<div style='font-family: Arial, sans-serif; max-width: 620px; margin: 0 auto; line-height: 1.6; color: #1e293b;'>"
                . "<div style='background: #08080a; padding: 26px; text-align: center; border-radius: 8px 8px 0 0;'>"
                . "<h1 style='color: #22d3ee; margin: 0; font-size: 24px; letter-spacing: 1.5px;'>AICENTY</h1>"
                . "<p style='color: #94a3b8; margin: 6px 0 0; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px;'>Chương Trình Đối Tác Kinh Doanh 2026</p>"
                . "</div>"
                . "<div style='background: #ffffff; padding: 30px 24px; border: 1px solid #e2e8f0; border-top: none; border-radius: 0 0 8px 8px;'>"
                . "<p>Kính gửi <strong>" . e($partnerName) . "</strong>,</p>"
                . "<p>Aicenty chân thành cảm ơn bạn đã quan tâm và hoàn tất đăng ký tham gia <strong>Chương trình Đối tác Kinh doanh Aicenty</strong> với vai trò <strong>" . e($programName) . "</strong>.</p>"
                . "<div style='background: #f0fdf4; border: 1px solid #bbf7d0; border-left: 4px solid #22c55e; padding: 16px; margin: 20px 0; border-radius: 6px;'>"
                . "<h3 style='margin: 0 0 8px; font-size: 15px; color: #15803d;'>Tóm tắt chính sách & quyền lợi đối tác của bạn:</h3>"
                . $policyHighlights
                . "</div>"
                . "<h3 style='font-size: 15px; color: #0f172a; margin-top: 24px;'>Các bước phối hợp tiếp theo:</h3>"
                . "<ol style='padding-left: 20px; margin: 8px 0;'>"
                . "<li>Đội ngũ phát triển đối tác của Aicenty sẽ liên hệ trực tiếp qua Zalo/Điện thoại (<strong>" . e($partnerPhone) . "</strong>) trong vòng 24 giờ làm việc.</li>"
                . "<li>Xác nhận thông tin, cấp mã đối tác và link tracking riêng trên hệ thống CRM Aicenty.</li>"
                . "<li>Bàn giao trọn bộ <strong>Partner Kit</strong> (Slide giải pháp chuẩn doanh nghiệp, video demo 16 công cụ AI, kịch bản mở phễu và case study thực tế).</li>"
                . "</ol>"
                . "<div style='background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px; margin-top: 24px; font-size: 13px; color: #475569;'>"
                . "Nếu bạn cần trao đổi khẩn cấp hoặc muốn được hỗ trợ ngay, vui lòng liên hệ trực tiếp:<br>"
                . "• Hotline / Zalo: <strong>0981 224 739</strong> - <strong>0918 852 101</strong><br>"
                . "• Email đối tác: <strong>ai@aicenty.com</strong><br>"
                . "• Website: <a href='https://aicenty.com/partner' style='color: #0284c7; text-decoration: none;'>aicenty.com/partner</a>"
                . "</div>"
                . "<hr style='border: none; border-top: 1px solid #e2e8f0; margin: 24px 0;'>"
                . "<p style='font-size: 12px; color: #94a3b8; text-align: center; margin: 0;'>"
                . "CÔNG TY CỔ PHẦN THƯƠNG MẠI HNKT - DỰ ÁN AICENTY<br>"
                . "Địa chỉ: 229/3/24B Lê Quang Định, Phường Gia Định, TPHCM | Mã số thuế: 0318799440"
                . "</p>"
                . "</div>"
                . "</div>";

            try {
                Http::timeout(15)->withToken($key)->post('https://api.resend.com/emails', [
                    'from' => $fromName . ' <' . $from . '>',
                    'to' => [$partnerEmail],
                    'reply_to' => $from,
                    'subject' => $partnerSubject,
                    'html' => $partnerHtml,
                ]);
            } catch (\Throwable $e) {
                Log::error('Failed to send partner registration confirmation email: ' . $e->getMessage());
            }
        }
    }
}
