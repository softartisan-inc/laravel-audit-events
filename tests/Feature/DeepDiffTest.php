<?php

use SoftArtisan\LaravelAuditEvents\Tests\Fixtures\Article;

it('returns a flat diff for scalar fields', function () {
    $article = Article::create(['title' => 'Old']);
    $article->update(['title' => 'New']);

    $fields = config('audit-events.table_fields');
    $audit = $article->audits()->latest($fields['id'])->first();
    $diff = $audit->getDiff();

    expect($diff)->toHaveKey('title')
        ->and($diff['title']['old'])->toBe('Old')
        ->and($diff['title']['new'])->toBe('New')
        ->and(array_key_exists('diff', $diff['title']))->toBeFalse();
});

it('returns a deep diff for JSON/array fields', function () {
    config()->set('audit-events.json_diff.enabled', true);

    $article = Article::create([
        'title' => 'T',
        'extra_fields' => ['a' => 1, 'b' => 2],
    ]);

    $article->update(['extra_fields' => ['a' => 1, 'b' => 99]]);

    $fields = config('audit-events.table_fields');
    $audit = $article->audits()->latest($fields['id'])->first();
    $diff = $audit->getDiff();

    expect($diff)->toHaveKey('extra_fields')
        ->and($diff['extra_fields'])->toHaveKey('diff')
        ->and($diff['extra_fields']['diff'])->toHaveKey('b')
        ->and($diff['extra_fields']['diff']['b']['old'])->toBe(2)
        ->and($diff['extra_fields']['diff']['b']['new'])->toBe(99);
});

it('skips deep diff when json_diff is disabled', function () {
    config()->set('audit-events.json_diff.enabled', false);

    $article = Article::create([
        'title' => 'T',
        'extra_fields' => ['a' => 1, 'b' => 2],
    ]);

    $article->update(['extra_fields' => ['a' => 1, 'b' => 99]]);

    $fields = config('audit-events.table_fields');
    $audit = $article->audits()->latest($fields['id'])->first();
    $diff = $audit->getDiff();

    expect($diff)->toHaveKey('extra_fields')
        ->and(array_key_exists('diff', $diff['extra_fields']))->toBeFalse();
});
