<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ButlerMorningBriefingService;
use PHPUnit\Framework\TestCase;

final class ButlerMorningBriefingServiceTest extends TestCase
{
    public function test_it_uses_the_customer_salutation_and_real_agenda_items(): void
    {
        $service = new ButlerMorningBriefingService();

        $message = $service->buildMessage(
            'Chị Lan',
            'Bà Tư',
            [
                ['time' => '09:00', 'title' => 'Họp phòng kinh doanh'],
                ['time' => '16:30', 'title' => 'Đón con'],
            ],
            'customer-a:20261113'
        );

        self::assertStringContainsString('Chị Lan', $message);
        self::assertStringContainsString('Bà Tư', $message);
        self::assertStringContainsString('09:00 — Họp phòng kinh doanh', $message);
        self::assertStringContainsString('16:30 — Đón con', $message);
        self::assertStringNotContainsString('Anh ', $message);
    }

    public function test_it_invites_the_customer_to_add_work_when_agenda_is_empty(): void
    {
        $service = new ButlerMorningBriefingService();

        $message = $service->buildMessage('Ba Minh', 'Ming ca ca', [], 'customer-b:20261113');

        self::assertStringContainsString('chưa', mb_strtolower($message));
        self::assertStringContainsString('lịch', mb_strtolower($message));
        self::assertStringContainsString('nhắc', mb_strtolower($message));
    }

    public function test_it_does_not_claim_the_agenda_is_empty_when_the_source_is_unavailable(): void
    {
        $service = new ButlerMorningBriefingService();

        $message = $service->buildMessage(
            'Cô Mai',
            'Quản Gia Aicenty',
            [],
            'customer-c:20261113',
            false
        );

        self::assertStringContainsString('đang đồng bộ lịch', $message);
        self::assertStringNotContainsString('chưa thấy công việc nào', $message);
    }

    public function test_variation_is_stable_for_the_same_customer_and_date(): void
    {
        $service = new ButlerMorningBriefingService();

        $first = $service->buildMessage('Gia chủ', 'Quản Gia', [], 'stable-seed');
        $second = $service->buildMessage('Gia chủ', 'Quản Gia', [], 'stable-seed');

        self::assertSame($first, $second);
    }

    public function test_it_limits_visible_events(): void
    {
        $service = new ButlerMorningBriefingService();
        $events = [];
        for ($index = 1; $index <= 7; $index++) {
            $events[] = ['time' => sprintf('%02d:00', $index + 7), 'title' => "Công việc {$index}"];
        }

        $message = $service->buildMessage('Anh Nam', 'Quản Gia', $events, 'limit-seed');

        self::assertStringContainsString('Và còn 2 công việc khác', $message);
        self::assertStringNotContainsString('Công việc 7', $message);
    }

    public function test_job_name_is_normalized_without_phone_or_duplicate_time(): void
    {
        $service = new ButlerMorningBriefingService();

        $title = $service->titleFromJobName('nhac-goi-doi-tac-0901234567-14h30');

        self::assertSame('Goi doi tac', $title);
    }
}
