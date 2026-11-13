<?php

declare(strict_types=1);

namespace App\Services;

final class ButlerMorningBriefingService
{
    private const MAX_VISIBLE_EVENTS = 5;

    /**
     * @param array<int, array{time: string, title: string}> $events
     */
    public function buildMessage(
        string $honorific,
        string $butlerName,
        array $events,
        string $variationSeed,
        bool $agendaAvailable = true
    ): string {
        $honorific = trim($honorific) !== '' ? trim($honorific) : 'Gia chủ';
        $butlerName = trim($butlerName) !== '' ? trim($butlerName) : 'Quản Gia Aicenty';
        $variant = $this->variantIndex($variationSeed, 3);

        $greetings = [
            "Dạ thưa {$honorific}, {$butlerName} chúc {$honorific} ngày mới an lành và vạn sự như ý! 🎩✨",
            "Dạ thưa {$honorific}, {$butlerName} kính chúc {$honorific} một ngày mới bình an, nhiều năng lượng và công việc hanh thông ạ! 🎩✨",
            "Dạ thưa {$honorific}, ngày mới bắt đầu rồi ạ. {$butlerName} chúc {$honorific} mọi việc thuận lợi và nhiều niềm vui! 🎩✨",
        ];

        $closings = [
            "{$honorific} xem hôm nay còn việc nào cần giải quyết thì nhắn {$butlerName} lưu lại và nhắc đúng giờ, tránh bỏ sót nhé ạ.",
            "Nếu hôm nay còn công việc nào chưa có trong lịch, {$honorific} cứ giao cho {$butlerName} lưu lại và nhắc đúng giờ nhé ạ.",
            "{$butlerName} luôn sẵn sàng ghi thêm lịch và nhắc đúng giờ. {$honorific} cứ nhắn công việc cần bổ sung nhé ạ.",
        ];

        $parts = [$greetings[$variant]];

        if (!$agendaAvailable) {
            $parts[] = "{$butlerName} đang đồng bộ lịch hôm nay và vẫn sẽ nhắc từng công việc theo đúng giờ đã lưu.";
            $parts[] = $closings[$variant];

            return implode("\n\n", $parts);
        }

        $normalizedEvents = [];
        foreach ($events as $event) {
            $time = trim((string) ($event['time'] ?? ''));
            $title = trim((string) ($event['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $normalizedEvents[] = [
                'time' => preg_match('/^\d{2}:\d{2}$/', $time) ? $time : '--:--',
                'title' => $title,
            ];
        }

        if ($normalizedEvents === []) {
            $emptyMessages = [
                "Hôm nay {$butlerName} chưa thấy công việc nào trong lịch của {$honorific}.",
                "Lịch hôm nay của {$honorific} hiện chưa có công việc nào được lưu ạ.",
                "Sáng nay {$butlerName} kiểm tra và chưa thấy lịch công việc nào của {$honorific}.",
            ];
            $parts[] = $emptyMessages[$variant];
            $parts[] = $closings[$variant];

            return implode("\n\n", $parts);
        }

        $visibleEvents = array_slice($normalizedEvents, 0, self::MAX_VISIBLE_EVENTS);
        $eventLines = array_map(
            static fn (array $event): string => "- {$event['time']} — {$event['title']}",
            $visibleEvents
        );
        $remaining = count($normalizedEvents) - count($visibleEvents);
        if ($remaining > 0) {
            $eventLines[] = "- Và còn {$remaining} công việc khác trong lịch.";
        }

        $parts[] = "Hôm nay lịch công việc của {$honorific} có:\n" . implode("\n", $eventLines);
        $parts[] = $closings[$variant];

        return implode("\n\n", $parts);
    }

    public function titleFromJobName(string $jobName): string
    {
        $title = preg_replace('/[-_]+/u', ' ', trim($jobName)) ?? '';
        $title = preg_replace('/^(?:nhac|remind|lich)\s+/iu', '', $title) ?? $title;
        $title = preg_replace('/\b(?:0|\+?84)\d{8,10}\b/u', '', $title) ?? $title;
        $title = preg_replace('/\b\d{1,2}(?:h\d{0,2}|:\d{2})\b/iu', '', $title) ?? $title;
        $title = preg_replace('/\s+/u', ' ', $title) ?? $title;
        $title = trim($title, " \t\n\r\0\x0B-");

        if ($title === '') {
            return 'Công việc đã lên lịch';
        }

        $title = mb_substr($title, 0, 120);

        return mb_strtoupper(mb_substr($title, 0, 1)) . mb_substr($title, 1);
    }

    private function variantIndex(string $seed, int $count): int
    {
        $unsigned = (int) sprintf('%u', crc32($seed));

        return $count > 0 ? $unsigned % $count : 0;
    }
}
