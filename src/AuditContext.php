<?php

namespace SoftArtisan\LaravelAuditEvents;

/**
 * AuditContext — inject a causer and extra payload for queue jobs.
 *
 * In HTTP requests the causer is resolved from the authenticated session.
 * In queue jobs there is no session, so Auth::id() returns null. Call
 * AuditContext::actingAs() at the start of your job's handle() method
 * and AuditContext::reset() at the end to avoid context bleed between jobs.
 *
 * Example:
 *   public function handle(): void
 *   {
 *       AuditContext::actingAs($this->userId, ['source' => 'import-job']);
 *       // ... job logic
 *       AuditContext::reset();
 *   }
 */
class AuditContext
{
    protected static int|string|null $causerId = null;

    protected static array $extra = [];

    /**
     * Set the acting user and optional extra payload for subsequent audits.
     */
    public static function actingAs(int|string $userId, array $extra = []): void
    {
        static::$causerId = $userId;
        static::$extra = $extra;
    }

    /**
     * Clear the injected context (call at the end of your job).
     */
    public static function reset(): void
    {
        static::$causerId = null;
        static::$extra = [];
    }

    /**
     * Return the injected causer ID, or null if not set.
     */
    public static function getCauserId(): int|string|null
    {
        return static::$causerId;
    }

    /**
     * Return the injected extra payload.
     */
    public static function getExtra(): array
    {
        return static::$extra;
    }
}
