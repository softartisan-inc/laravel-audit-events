<?php

use SoftArtisan\LaravelAuditEvents\Models\ModelAudit;
use SoftArtisan\LaravelAuditEvents\Tests\Fixtures\Article;

it('filters audits by event name via whereEvent scope', function () {
    ModelAudit::record('asset.status_changed', ['mission_id' => 1]);
    ModelAudit::record('asset.status_changed', ['mission_id' => 2]);
    ModelAudit::record('user.logged_in', ['ip' => '127.0.0.1']);

    expect(ModelAudit::whereEvent('asset.status_changed')->count())->toBe(2)
        ->and(ModelAudit::whereEvent('user.logged_in')->count())->toBe(1)
        ->and(ModelAudit::whereEvent('nope')->count())->toBe(0);
});

it('filters audits by a context key via whereContext scope', function () {
    ModelAudit::record('asset.status_changed', ['mission_id' => 42, 'new_status' => 'MISSING']);
    ModelAudit::record('asset.status_changed', ['mission_id' => 42, 'new_status' => 'ACTIVE']);
    ModelAudit::record('asset.status_changed', ['mission_id' => 99, 'new_status' => 'ACTIVE']);

    expect(ModelAudit::whereContext('mission_id', 42)->count())->toBe(2)
        ->and(ModelAudit::whereContext('mission_id', 99)->count())->toBe(1)
        ->and(ModelAudit::whereContext('mission_id', 42)->whereContext('new_status', 'ACTIVE')->count())->toBe(1);
});

it('filters audits anchored to a given model via forAuditable scope', function () {
    $a = Article::create(['title' => 'A']); // crée un audit "created" ancré à $a
    $b = Article::create(['title' => 'B']); // crée un audit "created" ancré à $b
    $a->update(['title' => 'A2']);           // crée un audit "updated" ancré à $a

    expect(ModelAudit::forAuditable($a)->count())->toBe(2)
        ->and(ModelAudit::forAuditable($b)->count())->toBe(1)
        ->and(ModelAudit::forAuditable($a)->whereEvent('updated')->count())->toBe(1);
});

it('has a database index on the event column', function () {
    $table = config('audit-events.table_name', 'audit_events');

    expect(\Illuminate\Support\Facades\Schema::hasIndex($table, [config('audit-events.table_fields.event', 'event')]))
        ->toBeTrue();
});
