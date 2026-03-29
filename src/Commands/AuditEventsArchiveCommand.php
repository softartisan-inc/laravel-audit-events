<?php

namespace SoftArtisan\LaravelAuditEvents\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use SoftArtisan\LaravelAuditEvents\Archive\Contracts\ArchiveDriver;
use SoftArtisan\LaravelAuditEvents\Archive\Drivers\DatabaseArchiveDriver;
use SoftArtisan\LaravelAuditEvents\Archive\Drivers\JsonFileArchiveDriver;
use SoftArtisan\LaravelAuditEvents\Models\ModelAudit;

class AuditEventsArchiveCommand extends Command
{
    public $signature = 'audit-events:archive
        {--days= : Archive records older than N days (overrides config)}
        {--driver= : Archive driver: database or json_file (overrides config)}
        {--dry-run : Show what would be archived without making changes}
        {--chunk=500 : Number of records per batch}
        {--model= : Limit to a specific auditable_type}';

    public $description = 'Move old audit records to cold storage (archive)';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('audit-events.archive.archive_after_days', 90));
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = (int) ($this->option('chunk') ?? 500);

        $query = $this->buildQuery($days);
        $total = $query->count();

        if ($dryRun) {
            $this->line('');
            $this->line("  <fg=yellow;options=bold>[dry-run]</> Would archive <fg=yellow;options=bold>{$total}</> records older than {$days} days.");
            $this->line('');

            return self::SUCCESS;
        }

        if ($total === 0) {
            $this->line('');
            $this->line("  No records older than {$days} days found. Nothing to archive.");
            $this->line('');

            return self::SUCCESS;
        }

        $driver = $this->resolveDriver();
        $archived = 0;

        $this->buildQuery($days)->chunk($chunkSize, function (Collection $chunk) use ($driver, &$archived): void {
            $archived += $this->archiveChunk($chunk, $driver);
        });

        $this->line('');
        $this->info("  Archived {$archived} audit record(s) successfully.");
        $this->line('');

        return self::SUCCESS;
    }

    private function resolveDriver(): ArchiveDriver
    {
        $driverName = $this->option('driver') ?? config('audit-events.archive.driver', 'database');

        return match ($driverName) {
            'json_file' => new JsonFileArchiveDriver(
                basePath: config('audit-events.archive.path', storage_path('audit-archives')),
            ),
            default => new DatabaseArchiveDriver(
                archiveTable: config('audit-events.archive.table_name', 'audit_events_archive'),
            ),
        };
    }

    /**
     * @param  Collection<int, Model>  $chunk
     */
    private function archiveChunk(Collection $chunk, ArchiveDriver $driver): int
    {
        if ($driver instanceof DatabaseArchiveDriver) {
            return DB::transaction(function () use ($chunk, $driver): int {
                $count = $driver->archive($chunk);
                $ids = $chunk->map(fn (Model $r) => $r->getKey())->all();
                ModelAudit::query()->whereIn((new ModelAudit)->getKeyName(), $ids)->delete();

                return $count;
            });
        }

        // json_file: archive first, then delete (prefer data safety over atomicity)
        $count = $driver->archive($chunk);
        $ids = $chunk->map(fn (Model $r) => $r->getKey())->all();
        ModelAudit::query()->whereIn((new ModelAudit)->getKeyName(), $ids)->delete();

        return $count;
    }

    private function buildQuery(int $days): Builder
    {
        $query = ModelAudit::query()
            ->where('created_at', '<', now()->subDays($days))
            ->oldest('created_at');

        if ($model = $this->option('model')) {
            $fields = config('audit-events.table_fields');
            $morphName = $fields['morph_prefix'] ?? 'auditable';
            $query->where("{$morphName}_type", $model);
        }

        return $query;
    }
}
