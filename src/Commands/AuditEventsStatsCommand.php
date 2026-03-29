<?php

namespace SoftArtisan\LaravelAuditEvents\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SoftArtisan\LaravelAuditEvents\Models\ModelAudit;

class AuditEventsStatsCommand extends Command
{
    public $signature = 'audit-events:stats';

    public $description = 'Display audit events statistics (totals, event breakdown, top models, table size, date range)';

    public function handle(): int
    {
        $table = config('audit-events.table_name', 'audit_events');
        $fields = config('audit-events.table_fields');
        $event = $fields['event'];
        $type = $fields['morph_prefix'].'_type';

        // ── Total ──────────────────────────────────────────────────────────
        $total = ModelAudit::query()->count();

        $this->info('');
        $this->line('  <fg=cyan;options=bold>Audit Events — Statistics</>');
        $this->line('  '.str_repeat('─', 52));
        $this->line("  Total audit events : <fg=yellow;options=bold>{$total}</>");

        if ($total === 0) {
            $this->line('  No audit records found.');
            $this->info('');

            return self::SUCCESS;
        }

        // ── By event ───────────────────────────────────────────────────────
        $byEvent = DB::table($table)
            ->selectRaw("{$event} as event_name, COUNT(*) as total_count")
            ->groupBy($event)
            ->orderByDesc('total_count')
            ->get()
            ->toArray();

        $this->line('');
        $this->line('  <options=bold>Events breakdown</>');
        $eventRows = array_map(function (object $row) {
            $data = (array) $row;

            return [$data['event_name'] ?? '(free)', (int) ($data['total_count'] ?? 0)];
        }, $byEvent);
        $this->table(['Event', 'Count'], $eventRows);

        // ── Top 5 models ───────────────────────────────────────────────────
        $topModels = DB::table($table)
            ->selectRaw("{$type} as model_type, COUNT(*) as total_count")
            ->whereNotNull($type)
            ->groupBy($type)
            ->orderByDesc('total_count')
            ->limit(5)
            ->get()
            ->toArray();

        if (count($topModels) > 0) {
            $this->line('  <options=bold>Top 5 most audited models</>');
            $modelRows = array_map(function (object $row) {
                $data = (array) $row;

                return [class_basename((string) ($data['model_type'] ?? '')), (int) ($data['total_count'] ?? 0)];
            }, $topModels);
            $this->table(['Model', 'Audit Count'], $modelRows);
        }

        // ── Date range ─────────────────────────────────────────────────────
        $oldest = ModelAudit::query()->oldest()->value('created_at');
        $newest = ModelAudit::query()->latest()->value('created_at');

        $this->line('  <options=bold>Date range</>');
        $this->line("  Oldest audit : <fg=green>{$oldest}</>");
        $this->line("  Newest audit : <fg=green>{$newest}</>");

        // ── Table size ─────────────────────────────────────────────────────
        try {
            $driver = DB::getDriverName();

            $sizeBytes = match ($driver) {
                'mysql', 'mariadb' => DB::selectOne(
                    'SELECT ROUND((data_length + index_length), 0) AS size
                     FROM information_schema.tables
                     WHERE table_schema = DATABASE() AND table_name = ?',
                    [$table]
                )?->size,
                'pgsql' => DB::selectOne(
                    'SELECT pg_total_relation_size(?) AS size',
                    [$table]
                )?->size,
                default => null,
            };

            if ($sizeBytes !== null) {
                $human = match (true) {
                    $sizeBytes >= 1_073_741_824 => round($sizeBytes / 1_073_741_824, 2).' GB',
                    $sizeBytes >= 1_048_576 => round($sizeBytes / 1_048_576, 2).' MB',
                    $sizeBytes >= 1_024 => round($sizeBytes / 1_024, 2).' KB',
                    default => $sizeBytes.' B',
                };
                $this->line("  Table size   : <fg=green>{$human}</>");
            }
        } catch (\Throwable) {
            // Table size query not supported on this driver
        }

        // ── Archive stats ──────────────────────────────────────────────────
        if (config('audit-events.archive.enabled', false)) {
            $archiveTable = config('audit-events.archive.table_name', 'audit_events_archive');

            if (Schema::hasTable($archiveTable)) {
                $archiveTotal = DB::table($archiveTable)->count();
                $archiveOldest = DB::table($archiveTable)->min('archived_at');
                $archiveNewest = DB::table($archiveTable)->max('archived_at');

                $this->line('');
                $this->line('  <options=bold>Archive</>');
                $this->line("  Archived records : <fg=yellow;options=bold>{$archiveTotal}</>");
                $this->line("  Oldest archived  : <fg=green>{$archiveOldest}</>");
                $this->line("  Newest archived  : <fg=green>{$archiveNewest}</>");
            }
        }

        $this->info('');

        return self::SUCCESS;
    }
}
