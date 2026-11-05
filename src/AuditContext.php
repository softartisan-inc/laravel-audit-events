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
     * Ambient context: survives reset() and is merged into every audit row's
     * context (and signature). Owned by the host application (e.g. a middleware).
     */
    protected static array $ambient = [];

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

    /**
     * Set the ambient context merged into every audited row until clearAmbient().
     */
    public static function setAmbient(array $context): void
    {
        static::$ambient = $context;
    }

    /**
     * Return the ambient context.
     */
    public static function getAmbient(): array
    {
        return static::$ambient;
    }

    /**
     * Clear the ambient context. reset() deliberately does NOT do this.
     */
    public static function clearAmbient(): void
    {
        static::$ambient = [];
    }

    /**
     * Merge a caller-supplied context with the ambient one.
     *
     * The reserved `impersonation` key can never come from the caller (it is
     * stripped), and ambient keys win over caller keys. Must be applied before
     * the signature is computed.
     */
    public static function applyAmbient(array $context): array
    {
        return array_merge(array_diff_key($context, ['impersonation' => true]), static::$ambient);
    }
}
