<?php

use Illuminate\Support\Facades\DB;
use SoftArtisan\LaravelAuditEvents\Tests\Fixtures\Article;

beforeEach(function () {
    config()->set('audit-events.integrity.enabled', true);
    config()->set('audit-events.integrity.key', null);
    config()->set('audit-events.integrity.algorithm', 'sha256');
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
});

it('exits with code 0 when all records pass verification', function () {
    Article::create(['title' => 'First']);
    Article::create(['title' => 'Second']);

    $this->artisan('audit-events:verify')->assertExitCode(0);
});

it('exits with code 1 when tampered records are detected', function () {
    $article = Article::create(['title' => 'Tamper me']);
    $fields = config('audit-events.table_fields');
    $table = config('audit-events.table_name', 'audit_events');

    DB::table($table)
        ->where($fields['id'], $article->audits()->first()->getKey())
        ->update(['signature' => str_repeat('f', 64)]);

    $this->artisan('audit-events:verify')->assertExitCode(1);
});

it('exits with failure when integrity feature is disabled', function () {
    config()->set('audit-events.integrity.enabled', false);

    $this->artisan('audit-events:verify')->assertExitCode(1);
});

it('unsigned records are counted separately and do not fail the command', function () {
    // Create a record without integrity enabled
    config()->set('audit-events.integrity.enabled', false);
    Article::create(['title' => 'Unsigned']);

    // Re-enable and create a signed record
    config()->set('audit-events.integrity.enabled', true);
    Article::create(['title' => 'Signed']);

    $this->artisan('audit-events:verify')
        ->assertExitCode(0)
        ->expectsOutputToContain('Unsigned');
});

it('handles empty table gracefully', function () {
    $this->artisan('audit-events:verify')->assertExitCode(0);
});

it('--model option limits verification to a specific auditable_type', function () {
    Article::create(['title' => 'Article']);

    $this->artisan('audit-events:verify', ['--model' => Article::class])
        ->assertExitCode(0);
});
