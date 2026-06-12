# Security Policy

## Reporting a vulnerability

Please **do not** open public GitHub issues for security vulnerabilities.
Email **henoc.djabia@softartisan.net** with details and a proof of concept where
possible. You will receive an acknowledgement within a few business days and a
coordinated disclosure timeline.

## Supported versions

| Version | Supported |
|---------|-----------|
| 2.x     | ✅        |
| 1.x     | ❌ (renamed; see the upgrade guide) |

---

## Cryptographic integrity — threat model (read this)

When `audit-events.integrity.enabled` is `true`, every audit row is HMAC-signed
and chained (`previous_hash`) per `(auditable_type, auditable_id)`. Be precise
about what this does and does **not** give you.

### What it protects against
- **Silent tampering of stored rows.** Editing `event`, `user_id`, `old/new_values`,
  `context`, or `created_at` of an existing row invalidates its signature.
- **Silent deletion / reordering / insertion** *within a model's history*. The
  per-model hash chain breaks, so `audit-events:verify` detects the gap.

### What it does NOT protect against
- **An attacker who has the signing key.** With the key (`AUDIT_SIGNING_KEY`, or
  `APP_KEY` if unset) **and** write access to the table, an attacker can forge a
  valid, re-signed history. This is **tamper-evidence, not tamper-prevention.**
- **Truncating the newest entries** of a chain if nothing external pins the head.
  The chain proves internal consistency; it does not prove completeness on its
  own. For strong guarantees, periodically export/anchor the latest signature
  (e.g. to append-only/WORM storage or a notary) so a rolled-back head is
  detectable.
- **Application-level bugs** that never write an audit in the first place.

### Recommendations
- Set a **dedicated** `AUDIT_SIGNING_KEY` (do not rely on `APP_KEY`), store it
  outside the application database, and restrict who can read it.
- Restrict direct write access to the `audit_events` table at the DB-grant level.
- Run `php artisan audit-events:verify` on a schedule and alert on a non-zero
  exit code.
- For compliance retention, prefer **archiving** over pruning (see the README),
  and ship archives to append-only cold storage.
- Keep secrets out of the trail using `global_hidden` / `$hidden_for_audit`.

> Enabling integrity *after* rows already exist leaves those rows unsigned;
> `verify` reports them as **unsigned** (not **tampered**), which is expected.
