<?php

use SoftArtisan\LaravelAuditEvents\AuditContext;
use SoftArtisan\LaravelAuditEvents\Tests\Fixtures\Article;

afterEach(function () {
    AuditContext::reset();
});

it('resolves the causer id injected via AuditContext instead of Auth', function () {
    AuditContext::actingAs(999, ['source' => 'import-job']);

    $article = Article::create(['title' => 'Job-created']);
    $fields = config('audit-events.table_fields');
    $audit = $article->audits()->latest($fields['id'])->first();

    expect(AuditContext::getCauserId())->toBe(999)
        ->and($audit->{$fields['user_id']})->toBe(999);
});

it('resets the context after reset() call', function () {
    AuditContext::actingAs(42, ['foo' => 'bar']);

    expect(AuditContext::getCauserId())->toBe(42)
        ->and(AuditContext::getExtra())->toBe(['foo' => 'bar']);

    AuditContext::reset();

    expect(AuditContext::getCauserId())->toBeNull()
        ->and(AuditContext::getExtra())->toBe([]);
});

it('returns null causer id when not set', function () {
    expect(AuditContext::getCauserId())->toBeNull();
});
