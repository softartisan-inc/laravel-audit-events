<?php

namespace SoftArtisan\LaravelAuditEvents\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use SoftArtisan\LaravelAuditEvents\Models\ModelAudit;
use SoftArtisan\LaravelAuditEvents\Services\AuditSignatureService;

class AuditEventsVerifyCommand extends Command
{
    public $signature = 'audit-events:verify
        {--model= : Limit verification to a specific auditable_type class}
        {--id= : Limit to a specific auditable_id (requires --model)}
        {--from= : Verify records created after this date (Y-m-d)}
        {--until= : Verify records created before this date (Y-m-d)}
        {--fail-fast : Stop at first failure}';

    public $description = 'Verify the cryptographic integrity of audit event records';

    public function handle(AuditSignatureService $signer): int
    {
        if (! config('audit-events.integrity.enabled', false)) {
            $this->error('Integrity verification is disabled. Enable audit-events.integrity.enabled in config.');

            return self::FAILURE;
        }

        $key = config('audit-events.integrity.key') ?? config('app.key');
        $algorithm = config('audit-events.integrity.algorithm', 'sha256');
        $fields = config('audit-events.table_fields');
        $morphName = $fields['morph_prefix'] ?? 'auditable';

        $query = ModelAudit::query()->oldest($fields['id']);

        if ($model = $this->option('model')) {
            $query->where("{$morphName}_type", $model);

            if ($id = $this->option('id')) {
                $query->where("{$morphName}_id", $id);
            }
        }

        if ($from = $this->option('from')) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($until = $this->option('until')) {
            $query->whereDate('created_at', '<=', $until);
        }

        $verified = 0;
        $failed = 0;
        $unsigned = 0;
        $failures = [];
        $stop = false;

        $query->chunk(500, function (Collection $chunk) use (
            $signer, $key, $algorithm, $fields, $morphName,
            &$verified, &$failed, &$unsigned, &$failures, &$stop
        ): bool {
            foreach ($chunk as $record) {
                if ($stop) {
                    return false;
                }

                $storedSignature = $record->getAttribute('signature');

                if ($storedSignature === null) {
                    $unsigned++;

                    continue;
                }

                $payload = [
                    'auditable_type' => $record->getAttribute("{$morphName}_type"),
                    'auditable_id' => $record->getAttribute("{$morphName}_id"),
                    'event' => $record->getAttribute($fields['event']),
                    'user_id' => $record->getAttribute($fields['user_id']),
                    'old_values' => (array) ($record->getAttribute($fields['old_values']) ?? []),
                    'new_values' => (array) ($record->getAttribute($fields['new_values']) ?? []),
                    'context' => $record->getAttribute($fields['context']),
                    'created_at' => $record->getAttribute('created_at')?->toIso8601String(),
                    'previous_hash' => $record->getAttribute('previous_hash'),
                ];

                if ($signer->verifySignature($storedSignature, $payload, $key, $algorithm)) {
                    $verified++;
                } else {
                    $failed++;
                    $failures[] = [
                        'id' => $record->getKey(),
                        'auditable_type' => $payload['auditable_type'] ?? '',
                        'auditable_id' => $payload['auditable_id'] ?? '',
                        'event' => $payload['event'] ?? '',
                        'created_at' => (string) ($record->getAttribute('created_at') ?? ''),
                        'reason' => 'Signature mismatch — record may have been tampered with',
                    ];

                    if ($this->option('fail-fast')) {
                        $stop = true;

                        return false;
                    }
                }
            }

            return true;
        });

        $total = $verified + $failed + $unsigned;

        $this->line('');
        $this->line('  <fg=cyan;options=bold>Audit Integrity Verification</>');
        $this->line('  '.str_repeat('─', 52));
        $this->line("  Records checked : <fg=yellow;options=bold>{$total}</>");
        $this->line("  Verified        : <fg=green;options=bold>{$verified}</>");
        $this->line("  Unsigned        : <fg=yellow>{$unsigned}</> (pre-date integrity feature)");
        $this->line("  Tampered        : <fg=".($failed > 0 ? 'red' : 'green').";options=bold>{$failed}</>");

        if ($failed > 0) {
            $this->line('');
            $this->error("  {$failed} record(s) failed integrity check!");
            $this->line('');

            $this->table(
                ['Audit ID', 'Model', 'Model ID', 'Event', 'Created At', 'Reason'],
                array_map(fn (array $f) => [
                    $f['id'],
                    class_basename((string) $f['auditable_type']),
                    $f['auditable_id'],
                    $f['event'],
                    $f['created_at'],
                    $f['reason'],
                ], $failures)
            );

            return self::FAILURE;
        }

        $this->line('');
        $this->info('  All signed records passed integrity verification.');
        $this->line('');

        return self::SUCCESS;
    }
}
