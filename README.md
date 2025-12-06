# Laravel Model Audits

[![Latest Version on Packagist](https://img.shields.io/packagist/v/softartisan/laravel-model-audits.svg?style=flat-square)](https://packagist.org/packages/softartisan/laravel-model-audits)
[![Total Downloads](https://img.shields.io/packagist/dt/softartisan/laravel-model-audits.svg?style=flat-square)](https://packagist.org/packages/softartisan/laravel-model-audits)
[![License](https://img.shields.io/packagist/l/softartisan/laravel-model-audits.svg?style=flat-square)](LICENSE.md)

Laravel Model Audits is a lightweight and robust package to automatically audit and track model changes. It records old and new values, the authenticated user, IP address, URL, user agent, and the event type (created, updated, deleted, restored) via a simple trait.

It automatically records:
- Who made the change (user ID)
- What happened (created, updated, deleted, restored)
- When it happened
- Where it came from (URL, IP address, user agent)
- Details: the exact old_values and new_values for modified attributes

## Installation

Install via Composer:

```bash
composer require softartisan/laravel-model-audits
```

Publish the config file (optional, recommended):

```bash
php artisan vendor:publish --tag="laravel-model-audits-config"
```

Publish the migration and run it:

```bash
php artisan vendor:publish --tag="laravel-model-audits-migrations"
php artisan migrate
```

## Quick start: use the trait

Add the IsAuditable trait to any Eloquent model you want to audit:

```php
use Illuminate\Database\Eloquent\Model;
use SoftArtisan\LaravelModelAudits\Concerns\IsAuditable;

class Post extends Model
{
    use IsAuditable;

    protected $fillable = ['title', 'content', 'secret_token'];
}
```

That's it. Creating, updating, or deleting this model will create audit rows.

Query audits for a model instance:

```php
$post = Post::find(1);
$audits = $post->audits()->latest('audit_id')->get();
```

## Mask sensitive fields

The package never logs attributes listed in the global_hidden config. You can also hide per‑model fields by overriding getHiddenForAudit() or using the hidden_for_audit array property.

```php
class Post extends Model
{
    use IsAuditable;

    protected array $hidden_for_audit = ['secret_token'];
}
```

## Behavior with SoftDeletes and forceDelete

- Soft delete (delete()) always records a "deleted" audit with old_values
- forceDelete():
  - remove_on_delete=true (default) deletes all audits for the model
  - remove_on_delete=false keeps existing audits and records one extra "deleted" audit

## Configuration highlights

Edit config/model-audits.php to adjust behavior:

- audit_on_create, audit_on_update: toggle which events are recorded
- remove_on_delete: how to handle audits when a record is permanently deleted
- table_fields: customize column names used by the audits table
- user.guards or user.resolver: control which user is linked to each audit row
- pruning: enable automatic cleanup of old audit rows

## Helpful APIs

On your auditable models:

- audits(): MorphMany relation to ModelAudit
- getDiff(): returns [attribute => ['old' => x, 'new' => y]] for a given audit
- restore(): applies the old_values of a given audit back to the model (ignores removed columns)

Example of restoring using a specific audit entry:

```php
$audit = $post->audits()->latest('audit_id')->first();
$audit->restore();
```

## Pruning old audits

Enable pruning in the config and schedule Laravel's prune command:

```php
// config/model-audits.php
'pruning' => [
    'enabled' => true,
    'keep_for_days' => 90,
],
```

Then schedule pruning (app/Console/Kernel.php):

```php
protected function schedule(Schedule $schedule): void
{
    $schedule->command('model:prune')->daily();
}
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Security

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
