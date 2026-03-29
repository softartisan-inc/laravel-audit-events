<?php

namespace SoftArtisan\LaravelAuditEvents\Archive\Drivers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use SoftArtisan\LaravelAuditEvents\Archive\Contracts\ArchiveDriver;

class DatabaseArchiveDriver implements ArchiveDriver
{
    public function __construct(
        private readonly string $archiveTable = 'audit_events_archive'
    ) {}

    /**
     * @param  Collection<int, Model>  $records
     */
    public function archive(Collection $records): int
    {
        if ($records->isEmpty()) {
            return 0;
        }

        $now = now()->toDateTimeString();
        $fields = config('audit-events.table_fields');
        $morphName = $fields['morph_prefix'] ?? 'auditable';

        $rows = $records->map(function (Model $record) use ($fields, $morphName, $now): array {
            return [
                $fields['id'] => $record->getKey(),
                "{$morphName}_type" => $record->getAttribute("{$morphName}_type"),
                "{$morphName}_id" => $record->getAttribute("{$morphName}_id"),
                $fields['event'] => $record->getAttribute($fields['event']),
                $fields['user_id'] => $record->getAttribute($fields['user_id']),
                $fields['url'] => $record->getAttribute($fields['url']),
                $fields['ip_address'] => $record->getAttribute($fields['ip_address']),
                $fields['user_agent'] => $record->getAttribute($fields['user_agent']),
                $fields['old_values'] => json_encode($record->getAttribute($fields['old_values'])),
                $fields['new_values'] => json_encode($record->getAttribute($fields['new_values'])),
                $fields['context'] => json_encode($record->getAttribute($fields['context'])),
                'signature' => $record->getAttribute('signature'),
                'previous_hash' => $record->getAttribute('previous_hash'),
                'created_at' => $record->getAttribute('created_at')?->toDateTimeString(),
                'updated_at' => $record->getAttribute('updated_at')?->toDateTimeString(),
                'archived_at' => $now,
            ];
        })->all();

        DB::table($this->archiveTable)->insert($rows);

        return count($rows);
    }
}
