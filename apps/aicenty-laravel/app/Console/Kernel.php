<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Spatie\Health\Commands\RunHealthChecksCommand;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('app:crontab-check')->everyMinute();
        $schedule->command('homepage:send-appointment-reminders')
            ->everyMinute()
            ->withoutOverlapping(10);

        $customSchedulerPath = app_path('Console/CustomScheduler.php');

        if (file_exists($customSchedulerPath)) {
            require_once $customSchedulerPath;
            CustomScheduler::scheduleTasks($schedule);
        }

        $schedule->command('app:check-coingate-command')->everyFiveMinutes();

        $schedule->command('app:check-razorpay-command')->everyFiveMinutes();

        $schedule->command('subscription:check-end')->everyFiveMinutes();

        $schedule->command('app:check-yookassa-command')->daily();

        $schedule->command('app:clear-user-open-a-i')->daily();

        $schedule->command('app:clear-user-open-a-i-chat')->daily();

        $schedule->command('app:clear-job-table')->daily();

        $schedule->command('app:clear-user-activity')->daily();

        $schedule->command('app:clear-ai-realtime-image')->daily();

        // Chỉ đồng bộ thành viên đã có binding chính xác; không chạy heuristic owner onboarding legacy.
        $schedule->command('butler:sync-onboarding --verified-members-only')
            ->everyMinute()
            ->withoutOverlapping(5);

        $schedule->command('butler:reconcile-lifecycle-anomalies')
            ->hourly()
            ->withoutOverlapping(15);

        $schedule->command('butler:monitor-trace-usage')
            ->hourly()
            ->withoutOverlapping(15);

        // Quản Gia AI: Tách bạch kênh & khung giờ (Asia/Ho_Chi_Minh)
        // 1. Zalo Quản Gia: Chỉ chào Sáng (07:00) & Tối (21:30)
        $schedule->command('butler:send-drip-nurturing --type=morning')
            ->dailyAt('07:00')
            ->timezone('Asia/Ho_Chi_Minh')
            ->withoutOverlapping()
            ->appendOutputTo('/var/log/aicenty_butler_morning.log');

        $schedule->command('butler:send-drip-nurturing --type=evening')
            ->dailyAt('21:30')
            ->timezone('Asia/Ho_Chi_Minh')
            ->withoutOverlapping()
            ->appendOutputTo('/var/log/aicenty_butler_evening.log');

        // 2. Zalo OA: Không chào sáng/tối - Chỉ chăm sóc quy trình tầm 14h chiều VN
        $schedule->command('butler:send-drip-nurturing --type=trial')
            ->dailyAt('14:00')
            ->timezone('Asia/Ho_Chi_Minh')
            ->withoutOverlapping()
            ->appendOutputTo('/var/log/aicenty_butler_trial.log');

        $schedule->command('butler:send-drip-nurturing --type=study_alert')
            ->dailyAt('14:15')
            ->timezone('Asia/Ho_Chi_Minh')
            ->withoutOverlapping()
            ->appendOutputTo('/var/log/aicenty_butler_study_alert.log');

        $schedule->command('butler:send-drip-nurturing --type=report_offer')
            ->dailyAt('14:30')
            ->timezone('Asia/Ho_Chi_Minh')
            ->withoutOverlapping()
            ->appendOutputTo('/var/log/aicenty_butler_report_offer.log');

    }

    // $schedule->command(RunHealthChecksCommand::class)->everyFiveMinutes();
    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
