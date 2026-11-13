<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\HomepageAppointment;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class HomepageAppointmentMailer
{
    public function send(HomepageAppointment $appointment, string $subject, string $intro): void
    {
        $key = (string) config('services.resend.key');
        $from = (string) config('services.resend.from_address', 'ai@aicenty.com');
        $fromName = (string) config('services.resend.from_name', 'Aicenty');
        $admins = config('services.resend.admin_recipients', []);
        if ($key === '' || $appointment->email === '' || count($admins) === 0) {
            throw new RuntimeException('Resend appointment mail configuration is incomplete.');
        }

        $date = $appointment->scheduled_at->setTimezone(config('app.timezone', 'Asia/Ho_Chi_Minh'))->format('d/m/Y H:i');
        $zoomUrl = e($appointment->zoom_url);
        $html = '<h2>' . e($intro) . '</h2>'
            . '<p><strong>Khách hàng:</strong> ' . e($appointment->name) . '</p>'
            . '<p><strong>Email:</strong> ' . e($appointment->email) . '<br><strong>Số điện thoại:</strong> ' . e($appointment->phone) . '<br><strong>Chức danh:</strong> ' . e($appointment->title) . '</p>'
            . '<p><strong>Thời gian:</strong> ' . e($date) . ' (Asia/Ho_Chi_Minh)</p>'
            . '<p><strong>Zoom:</strong> <a href="' . $zoomUrl . '">' . $zoomUrl . '</a><br><strong>Passcode:</strong> ' . e($appointment->zoom_passcode) . '</p>'
            . '<p><strong>Vấn đề doanh nghiệp:</strong><br>' . nl2br(e($appointment->problem)) . '</p>';
        $text = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html));

        $response = Http::timeout(20)->withToken($key)->post('https://api.resend.com/emails', [
            'from' => $fromName . ' <' . $from . '>',
            'to' => [$appointment->email],
            'bcc' => $admins,
            'reply_to' => $from,
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
        ]);
        if ($response->failed()) {
            throw new RuntimeException('Resend rejected the appointment email: HTTP ' . $response->status());
        }
    }
}
