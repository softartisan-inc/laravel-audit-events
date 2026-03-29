<?php

use Illuminate\Support\Facades\DB;
use SoftArtisan\LaravelAuditEvents\Models\ModelAudit;
use SoftArtisan\LaravelAuditEvents\Tests\Fixtures\Article;

beforeEach(function () {
    config()->set('audit-events.archive.enabled', true);
    config()->set('audit-events.archive.archive_after_days', 90);
    config()->set('audit-events.archive.driver', 'database');
    config()->set('audit-events.archive.table_name', 'audit_events_archive');
});

it('moves records older than threshold to the archive table', function () {
    $article = Article::create(['title' => 'Old record']);
    $fields = config('audit-events.table_fields');

    // Backdate the audit to 100 days ago
    DB::table(config('audit-events.table_name', 'audit_events'))
        ->where($fields['id'], $article->audits()->first()->getKey())
        ->update(['created_at' => now()->subDays(100)]);

    expect(ModelAudit::count())->toBe(1);

    $this->artisan('audit-events:archive', ['--days' => 90])->assertExitCode(0);

    expect(ModelAudit::count())->toBe(0)
        ->and(DB::table('audit_events_archive')->count())->toBe(1);
});

it('does not move records newer than the threshold', function () {
    Article::create(['title' => 'Recent']);

    $this->artisan('audit-events:archive', ['--days' => 90])->assertExitCode(0);

    expect(ModelAudit::count())->toBe(1)
        ->and(DB::table('audit_events_archive')->count())->toBe(0);
});

it('preserves signature and previous_hash in archive', function () {
    config()->set('audit-events.integrity.enabled', true);
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));

    $article = Article::create(['title' => 'Signed archive']);
    $fields = config('audit-events.table_fields');
    $auditId = $article->audits()->first()->getKey();

    DB::table(config('audit-events.table_name', 'audit_events'))
        ->where($fields['id'], $auditId)
        ->update(['created_at' => now()->subDays(100)]);

    $this->artisan('audit-events:archive', ['--days' => 90])->assertExitCode(0);

    $archived = DB::table('audit_events_archive')->first();

    expect($archived->signature)->toBeString()->not->toBeNull();
});

it('--dry-run shows count without moving records', function () {
    $article = Article::create(['title' => 'Will not be archived']);
    $fields = config('audit-events.table_fields');

    DB::table(config('audit-events.table_name', 'audit_events'))
        ->where($fields['id'], $article->audits()->first()->getKey())
        ->update(['created_at' => now()->subDays(100)]);

    $this->artisan('audit-events:archive', ['--dry-run' => true])->assertExitCode(0);

    expect(ModelAudit::count())->toBe(1)
        ->and(DB::table('audit_events_archive')->count())->toBe(0);
});

it('handles empty table gracefully', function () {
    $this->artisan('audit-events:archive')->assertExitCode(0);
});

it('archived_at column is populated', function () {
    $article = Article::create(['title' => 'Timestamp test']);
    $fields = config('audit-events.table_fields');

    DB::table(config('audit-events.table_name', 'audit_events'))
        ->where($fields['id'], $article->audits()->first()->getKey())
        ->update(['created_at' => now()->subDays(100)]);

    $this->artisan('audit-events:archive')->assertExitCode(0);

    $archived = DB::table('audit_events_archive')->first();

    expect($archived->archived_at)->not->toBeNull();
});

it('json_file driver writes JSONL records to the configured path', function () {
    $tmpPath = sys_get_temp_dir().'/audit_test_archive_'.uniqid();
    config()->set('audit-events.archive.driver', 'json_file');
    config()->set('audit-events.archive.path', $tmpPath);

    $article = Article::create(['title' => 'JSON archive']);
    $fields = config('audit-events.table_fields');

    DB::table(config('audit-events.table_name', 'audit_events'))
        ->where($fields['id'], $article->audits()->first()->getKey())
        ->update(['created_at' => now()->subDays(100)]);

    $this->artisan('audit-events:archive')->assertExitCode(0);

    $files = glob($tmpPath.'/*.jsonl');
    expect($files)->not->toBeEmpty();

    $lines = array_filter(explode("\n", file_get_contents($files[0])));
    expect($lines)->toHaveCount(1);

    $data = json_decode($lines[0], true);
    expect($data)->toHaveKey('archived_at');

    // Cleanup
    array_map('unlink', $files);
    rmdir($tmpPath);
});

it('archive stats appear in audit-events:stats when archive.enabled is true', function () {
    $fields = config('audit-events.table_fields');
    $liveTable = config('audit-events.table_name', 'audit_events');

    // One old record to be archived
    $old = Article::create(['title' => 'Old']);
    DB::table($liveTable)
        ->where($fields['id'], $old->audits()->first()->getKey())
        ->update(['created_at' => now()->subDays(100)]);

    // One recent record to keep the live table non-empty so stats doesn't return early
    Article::create(['title' => 'Recent']);

    $this->artisan('audit-events:archive')->assertExitCode(0);

    $this->artisan('audit-events:stats')
        ->assertExitCode(0)
        ->expectsOutputToContain('Archive');
});
