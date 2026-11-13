<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\HomepageAppointment;
use App\Services\HomepageAppointmentMailer;
use App\Services\OdooCrmService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HomepageContactController extends Controller
{
    public function store(Request $request, HomepageAppointmentMailer $mailer, OdooCrmService $odooService): JsonResponse
    {
        $data = $request->validate([
            'name'          => ['required', 'string', 'max:120'],
            'email'         => ['required', 'email:rfc', 'max:255'],
            'phone'         => ['required', 'string', 'max:40', 'regex:/^[0-9+().\s-]{8,40}$/'],
            'title'         => ['required', 'string', 'max:120'],
            'date'          => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'time'          => ['required', 'in:09:00 - 10:00,10:00 - 11:00,14:00 - 15:00,15:00 - 16:00,16:00 - 17:00'],
            'problem'       => ['required', 'string', 'max:5000'],
            'consent'       => ['required', 'accepted'],
            'affiliate_ref' => ['nullable', 'string', 'max:100'],
            'source_page'   => ['nullable', 'string', 'max:255'],
        ]);

        if (empty($data['affiliate_ref'])) {
            $cookieRef = $request->cookie('strive_affiliate_ref') ?? $request->input('ref');
            if ($cookieRef) {
                $data['affiliate_ref'] = trim((string) $cookieRef);
            }
        }

        $timezone = config('app.timezone', 'Asia/Ho_Chi_Minh');
        $start = trim(explode(' - ', $data['time'])[0]);
        $scheduledAt = Carbon::createFromFormat('Y-m-d H:i', $data['date'] . ' ' . $start, $timezone);

        if ($scheduledAt->isWeekend()) {
            return response()->json(['message' => 'Aicenty chỉ nhận lịch từ thứ 2 đến thứ 6. Vui lòng chọn ngày làm việc.'], 422);
        }

        if ($scheduledAt->isPast()) {
            return response()->json(['message' => 'Vui lòng chọn ngày và giờ trong tương lai.'], 422);
        }

        $appointment = HomepageAppointment::create([
            ...$data,
            'appointment_date' => $data['date'],
            'appointment_time' => $data['time'],
            'scheduled_at' => $scheduledAt,
            'consent' => true,
            'zoom_url' => config('services.homepage.zoom_url'),
            'zoom_passcode' => config('services.homepage.zoom_passcode'),
            'status' => 'scheduled',
        ]);

        // Đẩy thông tin khách hàng vào CRM Odoo tại erp.hnkt.vn và ghi nhận Affiliate Lead (nếu có)
        try {
            $odooService->createLead($data);
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            $mailer->send($appointment, 'AICENTY - Xác nhận AI Growth Call', 'Xác nhận lịch AI Growth Call của bạn');
            $appointment->forceFill(['confirmation_sent_at' => now()])->save();
        } catch (\Throwable $exception) {
            report($exception);
            return response()->json(['message' => 'Không thể gửi email xác nhận lúc này. Vui lòng thử lại sau ít phút.'], 502);
        }

        return response()->json([
            'message' => 'Chúc mừng bạn đã giữ chỗ thành công. Hãy kiểm tra email để nhận thông tin buổi gặp, chúng tôi đã gửi sẵn đường link zoom meeting qua email. Đến lịch hẹn sẽ có email gửi thông báo nhắc hẹn và gửi kèm link zoom để làm việc 1-1 cùng Aicenty',
        ]);
    }
}
