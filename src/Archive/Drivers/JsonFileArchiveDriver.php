<?php

namespace SoftArtisan\LaravelAuditEvents\Archive\Drivers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use SoftArtisan\LaravelAuditEvents\Archive\Contracts\ArchiveDriver;

class JsonFileArchiveDriver implements ArchiveDriver
{
    public function __construct(
        private readonly string $basePath,
        private readonly string $filenamePrefix = 'audit_events_archive'
    ) {}

    /**
     * @param  Collection<int, Model>  $records
     */
    public function archive(Collection $records): int
    {
        if ($records->isEmpty()) {
            return 0;
        }

        $filePath = $this->resolveFilePath();
        $this->ensureDirectoryExists();

        $now = now()->toIso8601String();
        $lines = $records->map(function (Model $record) use ($now): string {
            $data = $record->toArray();
            $data['archived_at'] = $now;

            return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        })->implode("\n")."\n";

        file_put_contents($filePath, $lines, FILE_APPEND | LOCK_EX);

        return $records->count();
    }

    private function resolveFilePath(): string
    {
        return $this->basePath.DIRECTORY_SEPARATOR.$this->filenamePrefix.'_'.now()->format('Y_m_d').'.jsonl';
    }

    private function ensureDirectoryExists(): void
    {
        if (! is_dir($this->basePath)) {
            mkdir($this->basePath, 0755, true);
        }
    }
}
