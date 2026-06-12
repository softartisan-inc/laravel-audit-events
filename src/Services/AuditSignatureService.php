<?php

namespace SoftArtisan\LaravelAuditEvents\Services;

use Illuminate\Support\Facades\DB;

/**
 * Pure computation layer for cryptographic integrity of audit records.
 *
 * Each audit row receives an HMAC-SHA256 signature over a canonical payload
 * (deterministic JSON, fixed key order). A hash chain is maintained per
 * (auditable_type, auditable_id) tuple: each new record's signed payload
 * includes the previous record's signature as `previous_hash`, allowing
 * detection of row insertion, deletion, or reordering within a model's history.
 *
 * Free events (no auditable model) are chained by user_id.
 */
class AuditSignatureService
{
    /**
     * Compute the HMAC signature for a single audit record's canonical payload.
     *
     * The canonical payload is JSON-encoded with a fixed key order to prevent
     * reordering attacks. Array fields are JSON-encoded consistently regardless
     * of how the data was originally stored.
     *
     * @param  array{
     *   auditable_type: string|null,
     *   auditable_id: string|int|null,
     *   event: string|null,
     *   user_id: int|string|null,
     *   old_values: array<string, mixed>,
     *   new_values: array<string, mixed>,
     *   context: array<string, mixed>|null,
     *   created_at: string,
     *   previous_hash: string|null,
     * }  $payload
     */
    public function computeSignature(array $payload, string $key, string $algorithm = 'sha256'): string
    {
        $key = $this->resolveKey($key);
        $canonical = $this->buildCanonicalPayload($payload);

        return hash_hmac($algorithm, $canonical, $key);
    }

    /**
     * Verify that a stored signature matches a recomputed one.
     */
    public function verifySignature(string $storedSignature, array $payload, string $key, string $algorithm = 'sha256'): bool
    {
        $expected = $this->computeSignature($payload, $key, $algorithm);

        return hash_equals($expected, $storedSignature);
    }

    /**
     * Retrieve the previous record's signature to use as previous_hash.
     *
     * Scoped by (auditable_type, auditable_id). For free-standing events
     * (no auditable), scoped by user_id. Returns null when no prior record exists.
     */
    public function getPreviousHash(
        ?string $auditableType,
        string|int|null $auditableId,
        string $tableName
    ): ?string {
        $fields = config('audit-events.table_fields');
        $morphName = $fields['morph_prefix'] ?? 'auditable';
        $morphTypeCol = "{$morphName}_type";
        $morphIdCol = "{$morphName}_id";

        $query = DB::table($tableName)
            ->whereNotNull('signature')
            ->orderByDesc($fields['id']);

        if ($auditableType !== null && $auditableId !== null) {
            $query->where($morphTypeCol, $auditableType)
                ->where($morphIdCol, $auditableId);
        } else {
            $query->whereNull($morphTypeCol)
                ->whereNull($morphIdCol);
        }

        /** @var object{signature: string}|null $row */
        $row = $query->first(['signature']);

        return $row?->signature;
    }

    /**
     * Build the canonical payload string for HMAC computation.
     *
     * Fields are serialized in a fixed order. Array fields are JSON-encoded
     * with consistent flags to ensure identical output across PHP versions.
     */
    private function buildCanonicalPayload(array $payload): string
    {
        $ordered = [
            'auditable_type' => $payload['auditable_type'] ?? null,
            'auditable_id' => isset($payload['auditable_id']) ? (string) $payload['auditable_id'] : null,
            'event' => $payload['event'] ?? null,
            'user_id' => isset($payload['user_id']) ? (string) $payload['user_id'] : null,
            'old_values' => json_encode($payload['old_values'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'new_values' => json_encode($payload['new_values'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'context' => json_encode($payload['context'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => $payload['created_at'] ?? null,
            'previous_hash' => $payload['previous_hash'] ?? null,
        ];

        return json_encode($ordered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    /**
     * Resolve the signing key, decoding base64-prefixed Laravel APP_KEY if needed.
     */
    private function resolveKey(string $key): string
    {
        if (str_starts_with($key, 'base64:')) {
            return base64_decode(substr($key, 7));
        }

        return $key;
    }
}
