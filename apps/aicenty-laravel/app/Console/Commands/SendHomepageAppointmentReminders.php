<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\HomepageAppointment;
use App\Services\HomepageAppointmentMailer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SendHomepageAppointmentReminders extends Command
{
    protected $signature = 'homepage:send-appointment-reminders';
    protected $description = 'Send scheduled AI Growth Call reminders';

    public function handle(HomepageAppointmentMailer $mailer): int
    {
        $now = Carbon::now(config('app.timezone', 'Asia/Ho_Chi_Minh'));
        HomepageAppointment::query()
            ->where('status', 'scheduled')
            ->where('scheduled_at', '>', $now)
            ->where('scheduled_at', '<=', $now->copy()->addDays(2))
            ->orderBy('scheduled_at')
            ->each(function (HomepageAppointment $appointment) use ($mailer, $now): void {
                $until = $now->diffInMinutes($appointment->scheduled_at, false);
                $field = null;
                $label = null;
                if ($appointment->reminder_2d_sent_at === null && $until <= 2880 && $until > 1440) {
                    $field = 'reminder_2d_sent_at';
                    $label = 'Nhắc lịch AI Growth Call còn 2 ngày';
                } elseif ($appointment->reminder_1d_sent_at === null && $until <= 1440 && $until > 120) {
                    $field = 'reminder_1d_sent_at';
                    $label = 'Nhắc lịch AI Growth Call còn 1 ngày';
                } elseif ($appointment->reminder_2h_sent_at === null && $until <= 120) {
                    $field = 'reminder_2h_sent_at';
                    $label = 'Nhắc lịch AI Growth Call còn 2 tiếng';
                }
                if ($field === null) return;
                try {
                    $mailer->send($appointment, $label, $label);
                    $appointment->forceFill([$field => now()])->save();
                } catch (\Throwable $exception) {
                    report($exception);
                }
            });
        return self::SUCCESS;
    }
}
