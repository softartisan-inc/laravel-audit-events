<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use SoftArtisan\LaravelAuditEvents\AuditContext;
use SoftArtisan\LaravelAuditEvents\Models\ModelAudit;
use SoftArtisan\LaravelAuditEvents\Services\AuditSignatureService;
use SoftArtisan\LaravelAuditEvents\Tests\Fixtures\Article;

beforeEach(function () {
    config()->set('audit-events.integrity.enabled', true);
    config()->set('audit-events.integrity.key', null);
    config()->set('audit-events.integrity.algorithm', 'sha256');
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
});

afterEach(function () {
    AuditContext::clearAmbient();
    AuditContext::reset();
    Carbon::setTestNow();
});

it('ambient_context_is_merged_and_signed', function () {
    AuditContext::setAmbient(['impersonation' => ['by' => 7]]);

    $audit = ModelAudit::record('user.logged_in', ['source' => 'web']);
    $audit->refresh();

    expect($audit->context)->toBe(['source' => 'web', 'impersonation' => ['by' => 7]])
        ->and($audit->verifySignature())->toBeTrue()
        ->and(Artisan::call('audit-events:verify'))->toBe(0);

    $article = Article::create(['title' => 'Signed ambient']);
    $fields = config('audit-events.table_fields');
    $modelAudit = $article->audits()->latest($fields['id'])->first();

    expect($modelAudit->{$fields['context']})->toBe(['impersonation' => ['by' => 7]])
        ->and($modelAudit->verifySignature())->toBeTrue();
});

it('ambient_keys_win_over_caller_keys', function () {
    AuditContext::setAmbient(['tenant' => 'ambient', 'impersonation' => ['by' => 1]]);

    $audit = ModelAudit::record('x.done', ['tenant' => 'caller', 'impersonation' => ['by' => 99], 'k' => 'v']);
    $audit->refresh();

    expect($audit->context)->toBe(['tenant' => 'ambient', 'k' => 'v', 'impersonation' => ['by' => 1]])
        ->and($audit->verifySignature())->toBeTrue();
});

it('reset_keeps_ambient_context', function () {
    AuditContext::setAmbient(['a' => 1]);
    AuditContext::actingAs(5, ['x' => 'y']);

    AuditContext::reset();

    expect(AuditContext::getAmbient())->toBe(['a' => 1])
        ->and(AuditContext::getCauserId())->toBeNull();

    AuditContext::clearAmbient();

    expect(AuditContext::getAmbient())->toBe([]);
});

it('caller_cannot_forge_impersonation_key', function () {
    $audit = ModelAudit::record('x.done', ['impersonation' => ['by' => 99], 'k' => 'v']);
    $audit->refresh();

    expect($audit->context)->toBe(['k' => 'v'])
        ->and($audit->verifySignature())->toBeTrue();
});

it('payload_unchanged_without_ambient', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-01T00:00:00+00:00'));

    $audit = ModelAudit::record('user.logged_in', ['source' => 'web'], 3);
    $audit->refresh();

    // Payload exactly as built by v2.1.0.
    $v210 = [
        'auditable_type' => null,
        'auditable_id' => null,
        'event' => 'user.logged_in',
        'user_id' => 3,
        'old_values' => [],
        'new_values' => [],
        'context' => ['source' => 'web'],
        'created_at' => now()->toIso8601String(),
        'previous_hash' => null,
    ];
    $expected = (new AuditSignatureService)->computeSignature($v210, config('app.key'), 'sha256');

    expect($audit->context)->toBe(['source' => 'web'])
        ->and($audit->signature)->toBe($expected);
});
