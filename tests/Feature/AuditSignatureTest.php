<?php

use Illuminate\Support\Facades\DB;
use SoftArtisan\LaravelAuditEvents\Models\ModelAudit;
use SoftArtisan\LaravelAuditEvents\Services\AuditSignatureService;
use SoftArtisan\LaravelAuditEvents\Tests\Fixtures\Article;

beforeEach(function () {
    config()->set('audit-events.integrity.enabled', true);
    config()->set('audit-events.integrity.key', null); // use app.key
    config()->set('audit-events.integrity.algorithm', 'sha256');
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
});

it('adds a signature when integrity is enabled', function () {
    $article = Article::create(['title' => 'Signed']);
    $fields = config('audit-events.table_fields');

    $audit = $article->audits()->latest($fields['id'])->first();

    expect($audit->getAttribute('signature'))->toBeString()->toHaveLength(64);
});

it('does not add a signature when integrity is disabled', function () {
    config()->set('audit-events.integrity.enabled', false);

    $article = Article::create(['title' => 'Unsigned']);
    $fields = config('audit-events.table_fields');

    $audit = $article->audits()->latest($fields['id'])->first();

    expect($audit->getAttribute('signature'))->toBeNull();
});

it('isSigned returns true for a signed record', function () {
    $article = Article::create(['title' => 'Hello']);
    $fields = config('audit-events.table_fields');
    $audit = $article->audits()->latest($fields['id'])->first();

    expect($audit->isSigned())->toBeTrue();
});

it('isSigned returns false for an unsigned record', function () {
    config()->set('audit-events.integrity.enabled', false);
    $article = Article::create(['title' => 'Hello']);
    $fields = config('audit-events.table_fields');
    $audit = $article->audits()->latest($fields['id'])->first();

    expect($audit->isSigned())->toBeFalse();
});

it('verifySignature returns true for a valid record', function () {
    $article = Article::create(['title' => 'Verify me']);
    $fields = config('audit-events.table_fields');
    $audit = $article->audits()->latest($fields['id'])->first();

    expect($audit->verifySignature())->toBeTrue();
});

it('verifySignature returns false when signature is tampered', function () {
    $article = Article::create(['title' => 'Tamper test']);
    $fields = config('audit-events.table_fields');
    $audit = $article->audits()->latest($fields['id'])->first();

    // Directly tamper the signature in DB
    DB::table(config('audit-events.table_name', 'audit_events'))
        ->where($fields['id'], $audit->getKey())
        ->update(['signature' => str_repeat('0', 64)]);

    $audit->refresh();

    expect($audit->verifySignature())->toBeFalse();
});

it('verifySignature throws when integrity is disabled', function () {
    config()->set('audit-events.integrity.enabled', false);
    $audit = new ModelAudit;

    expect(fn () => $audit->verifySignature())->toThrow(RuntimeException::class);
});

it('first record in chain has null previous_hash', function () {
    $article = Article::create(['title' => 'First']);
    $fields = config('audit-events.table_fields');

    $audit = $article->audits()->oldest($fields['id'])->first();

    expect($audit->getAttribute('previous_hash'))->toBeNull();
});

it('second record previous_hash matches signature of first record', function () {
    $article = Article::create(['title' => 'First']);
    $article->update(['title' => 'Second']);
    $fields = config('audit-events.table_fields');

    $audits = $article->audits()->oldest($fields['id'])->get();

    expect($audits[1]->getAttribute('previous_hash'))
        ->toBe($audits[0]->getAttribute('signature'));
});

it('chain is scoped per model — two models maintain independent chains', function () {
    $a1 = Article::create(['title' => 'Model A']);
    $a2 = Article::create(['title' => 'Model B']);

    $fields = config('audit-events.table_fields');

    $audit1 = $a1->audits()->first();
    $audit2 = $a2->audits()->first();

    // Both first records should have null previous_hash (independent chains)
    expect($audit1->getAttribute('previous_hash'))->toBeNull()
        ->and($audit2->getAttribute('previous_hash'))->toBeNull();
});

it('ModelAudit::record also signs free-standing events', function () {
    $audit = ModelAudit::record('user.logged_in', ['source' => 'web']);

    expect($audit->isSigned())->toBeTrue()
        ->and($audit->verifySignature())->toBeTrue();
});

it('uses a custom signing key from config', function () {
    $customKey = str_repeat('z', 32);
    config()->set('audit-events.integrity.key', $customKey);

    $article = Article::create(['title' => 'Custom key test']);
    $fields = config('audit-events.table_fields');
    $audit = $article->audits()->first();

    expect($audit->verifySignature())->toBeTrue();

    // Changing the key should break verification
    config()->set('audit-events.integrity.key', str_repeat('x', 32));
    $audit->refresh();

    expect($audit->verifySignature())->toBeFalse();
});

it('AuditSignatureService produces a deterministic signature for the same payload', function () {
    $signer = new AuditSignatureService;
    $key = str_repeat('k', 32);
    $payload = [
        'auditable_type' => 'App\\Models\\User',
        'auditable_id' => '42',
        'event' => 'updated',
        'user_id' => '1',
        'old_values' => ['name' => 'Alice'],
        'new_values' => ['name' => 'Bob'],
        'context' => null,
        'created_at' => '2025-01-01T00:00:00+00:00',
        'previous_hash' => null,
    ];

    $sig1 = $signer->computeSignature($payload, $key);
    $sig2 = $signer->computeSignature($payload, $key);

    expect($sig1)->toBe($sig2)->toHaveLength(64);
});

it('AuditSignatureService returns a different signature when any field changes', function () {
    $signer = new AuditSignatureService;
    $key = str_repeat('k', 32);
    $base = [
        'auditable_type' => 'App\\Models\\User',
        'auditable_id' => '42',
        'event' => 'updated',
        'user_id' => '1',
        'old_values' => ['name' => 'Alice'],
        'new_values' => ['name' => 'Bob'],
        'context' => null,
        'created_at' => '2025-01-01T00:00:00+00:00',
        'previous_hash' => null,
    ];

    $original = $signer->computeSignature($base, $key);

    $tampered = $base;
    $tampered['new_values'] = ['name' => 'Mallory'];

    expect($signer->computeSignature($tampered, $key))->not->toBe($original);
});
