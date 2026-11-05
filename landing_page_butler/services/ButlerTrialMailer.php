<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ButlerTrialMailer
{
    /**
     * Gửi email thông báo cho Admin và email xác nhận cho phụ huynh đăng ký 7 ngày.
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

        $parentName = trim((string) ($data['name'] ?? 'Phụ Huynh'));
        $parentEmail = trim((string) ($data['email'] ?? ''));
        $parentPhone = trim((string) ($data['phone'] ?? ''));
        $childInfo = trim((string) ($data['child_info'] ?? 'Chưa rõ'));
        $primaryConcern = trim((string) ($data['primary_concern'] ?? 'Kèm con học tập & Tâm lý'));
        $note = trim((string) ($data['note'] ?? ''));
        $affiliateRef = trim((string) ($data['affiliate_ref'] ?? ''));

        // 1. GỬI EMAIL CHO ADMIN (hnkt@hnkt.vn, ai@aicenty.com, striverle@gmail.com)
        $adminRecipients = ['hnkt@hnkt.vn', 'ai@aicenty.com', 'striverle@gmail.com'];
        $adminSubject = "[Aicenty Butler] Khách Đăng Ký Trải Nghiệm 7 Ngày: {$parentName} - {$parentPhone}";

        $adminHtml = "<div style='font-family: Arial, sans-serif; max-width: 620px; margin: 0 auto; line-height: 1.6; color: #1e293b;'>"
            . "<div style='background: #08080a; padding: 24px; text-align: center; border-radius: 8px 8px 0 0; border-bottom: 2px solid #22d3ee;'>"
            . "<h2 style='color: #22d3ee; margin: 0; font-size: 20px; letter-spacing: 1px;'>AICENTY BUTLER - LEAD MỚI 7 NGÀY</h2>"
            . "<p style='color: #94a3b8; margin: 6px 0 0; font-size: 13px;'>Hệ thống Quản gia Toàn năng Gia đình Việt</p>"
            . "</div>"
            . "<div style='background: #ffffff; padding: 26px 22px; border: 1px solid #e2e8f0; border-top: none; border-radius: 0 0 8px 8px;'>"
            . "<p style='font-size: 15px;'>Có một phụ huynh vừa hoàn tất đăng ký <strong>Trải nghiệm 7 ngày Aicenty Butler</strong>:</p>"
            . "<table style='width: 100%; border-collapse: collapse; margin: 18px 0; font-size: 14px;'>"
            . "<tr style='border-bottom: 1px solid #f1f5f9;'><td style='padding: 10px 0; color: #64748b; width: 160px;'>Họ tên phụ huynh:</td><td style='padding: 10px 0; font-weight: bold; color: #0f172a;'>" . e($parentName) . "</td></tr>"
            . "<tr style='border-bottom: 1px solid #f1f5f9;'><td style='padding: 10px 0; color: #64748b;'>Số điện thoại / Zalo:</td><td style='padding: 10px 0; font-weight: bold; color: #0284c7;'>" . e($parentPhone) . "</td></tr>"
            . "<tr style='border-bottom: 1px solid #f1f5f9;'><td style='padding: 10px 0; color: #64748b;'>Email:</td><td style='padding: 10px 0; font-weight: bold;'>" . e($parentEmail) . "</td></tr>"
            . "<tr style='border-bottom: 1px solid #f1f5f9;'><td style='padding: 10px 0; color: #64748b;'>Lớp / Tuổi của con:</td><td style='padding: 10px 0; font-weight: bold; color: #15803d;'>" . e($childInfo) . "</td></tr>"
            . "<tr style='border-bottom: 1px solid #f1f5f9;'><td style='padding: 10px 0; color: #64748b;'>Mối quan tâm nhất:</td><td style='padding: 10px 0;'>" . e($primaryConcern) . "</td></tr>"
            . ($note !== '' ? "<tr style='border-bottom: 1px solid #f1f5f9;'><td style='padding: 10px 0; color: #64748b;'>Ghi chú:</td><td style='padding: 10px 0; color: #b45309;'>" . nl2br(e($note)) . "</td></tr>" : '')
            . ($affiliateRef !== '' ? "<tr style='border-bottom: 1px solid #f1f5f9;'><td style='padding: 10px 0; color: #64748b;'>Affiliate Ref:</td><td style='padding: 10px 0;'>" . e($affiliateRef) . "</td></tr>" : '')
            . "<tr><td style='padding: 10px 0; color: #64748b;'>Thời gian đăng ký:</td><td style='padding: 10px 0;'>" . now()->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i:s') . "</td></tr>"
            . "</table>"
            . "<div style='background: #f8fafc; border-left: 4px solid #3b82f6; padding: 12px; margin-top: 16px; font-size: 13px; color: #475569;'>"
            . "Lead đã được tự động đồng bộ vào <strong>ERP Odoo erp.hnkt.vn</strong>. Đội ngũ Butler Guru vui lòng theo dõi tương tác Zalo của khách hàng."
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

        // 2. GỬI EMAIL XÁC NHẬN CHO KHÁCH HÀNG (PHỤ HUYNH)
        if ($parentEmail !== '') {
            $customerSubject = "Kính gửi Gia đình " . $parentName . ": Hướng dẫn kích hoạt 7 ngày Quản gia Aicenty Butler";

            $customerHtml = "<div style='font-family: Arial, sans-serif; max-width: 640px; margin: 0 auto; line-height: 1.6; color: #1e293b; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden;'>"
                . "<div style='background: #08080a; padding: 32px 24px; text-align: center; border-bottom: 2px solid #22d3ee;'>"
                . "<h1 style='color: #22d3ee; margin: 0; font-size: 24px; letter-spacing: 2px;'>AICENTY BUTLER</h1>"
                . "<p style='color: #94a3b8; margin: 8px 0 0; font-size: 14px;'>Quản Gia Số Toàn Năng Đồng Hành Cùng Mọi Gia Đình Việt</p>"
                . "</div>"
                . "<div style='padding: 32px 28px;'>"
                . "<p style='font-size: 16px;'>Kính chào Quý phụ huynh <strong>" . e($parentName) . "</strong>,</p>"
                . "<p style='font-size: 15px; color: #334155;'>"
                . "Aicenty Butler xin gửi lời chào trân trọng và chúc mừng gia đình mình đã chính thức bước chân vào <strong>hành trình 7 ngày trải nghiệm Quản gia Số toàn năng</strong>. "
                . "Chúng tôi hiểu rằng nuôi dạy con cái và lo toan gia đình thời hiện đại là một hành trình đầy áp lực nhưng cũng ngập tràn yêu thương. Aicenty Butler ở đây để san sẻ gánh nặng đó cùng bạn mỗi ngày."
                . "</p>"
                . "<div style='background: #f0fdf4; border: 1px solid #bbf7d0; border-left: 4px solid #16a34a; padding: 18px; margin: 24px 0; border-radius: 8px;'>"
                . "<h3 style='margin: 0 0 10px; font-size: 16px; color: #15803d;'>Đặc quyền 7 ngày Trải nghiệm Hoàn Toàn Miễn Phí của gia đình:</h3>"
                . "<ul style='padding-left: 20px; margin: 0; color: #166534; font-size: 14px; line-height: 1.7;'>"
                . "<li><strong>15 Gia sư GDPT 2018:</strong> Kèm từng khối lớp (Toán, Lý, Hóa, Văn, Anh, Sinh, Sử, Địa) theo phương pháp gợi mở Socratic – con tự giác tư duy, bố mẹ không còn phải cáu giận mỗi tối.</li>"
                . "<li><strong>Bác Phúc - Cố vấn Tâm lý & Nuôi dạy con:</strong> Lắng nghe, thấu hiểu, bảo vệ an toàn tâm lý học đường, tích hợp Hotline 111.</li>"
                . "<li><strong>Báo cáo Kín qua Zalo Phụ Huynh:</strong> Cập nhật tiến độ học tập và cảm xúc hàng tuần của con.</li>"
                . "<li><strong>Quản gia Tài chính & Dinh dưỡng:</strong> Lập ngân sách gia đình và thực đơn dinh dưỡng chuẩn thể trạng Việt.</li>"
                . "<li><strong>Chuyên gia Butler Guru hỗ trợ 1-1:</strong> Đồng hành hướng dẫn gia đình xuyên suốt 7 ngày.</li>"
                . "</ul>"
                . "</div>"
                . "<h3 style='font-size: 16px; color: #0f172a; margin-top: 28px;'>3 BƯỚC ĐỂ BẮT ĐẦU NGAY BÂY GIỜ:</h3>"
                . "<ol style='padding-left: 20px; margin: 12px 0 24px; color: #334155; font-size: 15px; line-height: 1.8;'>"
                . "<li><strong>Bước 1:</strong> Kết nối trực tiếp với <strong>Butler Guru qua Zalo</strong> bằng cách bấm vào nút bên dưới (hoặc kết bạn Zalo số <strong>0981 224 739</strong> - <strong>0918 852 101</strong>) để nhận mã kích hoạt và hướng dẫn đưa Quản gia vào Zalo gia đình.</li>"
                . "<li><strong>Bước 2:</strong> Cùng con đặt câu hỏi học tập đầu tiên để con cảm nhận niềm vui khi tự mình giải được bài toán khó.</li>"
                . "<li><strong>Bước 3:</strong> Tham gia <strong>Group Zalo Cộng Đồng Cha Mẹ Aicenty</strong> để cùng giao lưu kinh nghiệm nuôi dạy con thông thái.</li>"
                . "</ol>"
                . "<div style='text-align: center; margin: 30px 0;'>"
                . "<a href='https://zalo.me/0981224739' style='display: inline-block; background: #0284c7; color: #ffffff; text-decoration: none; padding: 14px 28px; font-weight: bold; font-size: 16px; border-radius: 9999px; box-shadow: 0 4px 12px rgba(2, 132, 199, 0.35);'>NHẮN TIN ZALO VỚI BUTLER GURU ĐỂ KÍCH HOẠT NGAY ↗</a>"
                . "</div>"
                . "<div style='background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; font-size: 13px; color: #64748b; line-height: 1.6;'>"
                . "<strong>Thông tin hỗ trợ trực tiếp từ Ban Quản Gia Aicenty:</strong><br>"
                . "• Hotline / Zalo Chuyên viên: <strong>0981 224 739</strong> - <strong>0918 852 101</strong><br>"
                . "• Email hỗ trợ: <strong>ai@aicenty.com</strong><br>"
                . "• Website giải pháp: <a href='https://aicenty.com/butler' style='color: #0284c7; text-decoration: none;'>aicenty.com/butler</a><br>"
                . "• Cộng đồng Zalo Cha Mẹ: <a href='https://zalo.me/0981224739' style='color: #0284c7; text-decoration: none;'>Bấm để nhận link mời nhóm kín</a>"
                . "</div>"
                . "<hr style='border: none; border-top: 1px solid #e2e8f0; margin: 28px 0;'>"
                . "<p style='font-size: 12px; color: #94a3b8; text-align: center; margin: 0;'>"
                . "CÔNG TY CỔ PHẦN THƯƠNG MẠI HNKT - DỰ ÁN AICENTY BUTLER<br>"
                . "229/3/24B Lê Quang Định, Phường Gia Định, TPHCM | Mã số thuế: 0318799440"
                . "</p>"
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
                Log::info("[ButlerTrialMailer] Đã gửi email xác nhận 7 ngày thành công cho phụ huynh: {$parentEmail}");
            } catch (\Throwable $e) {
                Log::error("[ButlerTrialMailer] Lỗi khi gửi email xác nhận cho phụ huynh: " . $e->getMessage());
            }
        }
    }
}
