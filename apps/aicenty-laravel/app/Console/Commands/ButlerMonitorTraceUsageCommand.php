<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ButlerMonitorTraceUsageCommand extends Command
{
    protected $signature = 'butler:monitor-trace-usage
                            {--hours=2 : Lookback window in hours}
                            {--dry-run : Report high-input traces without writing alerts}';

    protected $description = 'Create idempotent activity alerts for unusually expensive Butler traces';

    public function handle(): int
    {
        $hours = max(1, min(168, (int) $this->option('hours')));
        $threshold = max(100000, (int) env('BUTLER_TRACE_INPUT_ALERT_TOKENS', 100000));
        $db = DB::connection('butler_pgsql');

        $traces = $db->table('traces')
            ->select([
                'id',
                'tenant_id',
                'channel',
                'status',
                'total_input_tokens',
                'total_output_tokens',
                'llm_call_count',
                'tool_call_count',
                'duration_ms',
                'created_at',
            ])
            ->where('created_at', '>=', now()->subHours($hours))
            ->whereIn('status', ['completed', 'error'])
            ->where('total_input_tokens', '>=', $threshold)
            ->orderBy('created_at')
            ->get();

        $this->line(sprintf(
            'high_input_traces=%d threshold=%d lookback_hours=%d dry_run=%s',
            $traces->count(),
            $threshold,
            $hours,
            $this->option('dry-run') ? 'true' : 'false'
        ));

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $created = 0;
        $db->transaction(function () use ($db, $traces, $threshold, &$created): void {
            foreach ($traces as $trace) {
                $exists = $db->table('activity_logs')
                    ->where('action', 'butler.usage.trace_input_alert')
                    ->where('entity_type', 'trace')
                    ->where('entity_id', (string) $trace->id)
                    ->exists();
                if ($exists) {
                    continue;
                }
                $db->table('activity_logs')->insert([
                    'id' => (string) Str::uuid(),
                    'actor_type' => 'system',
                    'actor_id' => 'butler-trace-usage-monitor',
                    'action' => 'butler.usage.trace_input_alert',
                    'entity_type' => 'trace',
                    'entity_id' => (string) $trace->id,
                    'details' => json_encode([
                        'threshold' => $threshold,
                        'channel' => $trace->channel,
                        'status' => $trace->status,
                        'input_tokens' => (int) $trace->total_input_tokens,
                        'output_tokens' => (int) $trace->total_output_tokens,
                        'llm_calls' => (int) $trace->llm_call_count,
                        'tool_calls' => (int) $trace->tool_call_count,
                        'duration_ms' => $trace->duration_ms === null ? null : (int) $trace->duration_ms,
                        'created_at' => $trace->created_at,
                    ], JSON_UNESCAPED_SLASHES),
                    'created_at' => now(),
                    'tenant_id' => $trace->tenant_id,
                ]);
                $created++;
            }
        }, 3);

        $this->info(sprintf('alerts_created=%d', $created));

        return self::SUCCESS;
    }
}
