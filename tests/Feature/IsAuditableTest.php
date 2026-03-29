<?php

use Illuminate\Support\Arr;
use SoftArtisan\LaravelAuditEvents\Tests\Fixtures\Article;
use SoftArtisan\LaravelAuditEvents\Tests\Fixtures\SoftArticle;

function getFields(): array
{
    return config('audit-events.table_fields');
}

it('records an audit on creation with new_values and masks hidden fields', function () {
    $article = Article::create([
        'title' => 'Mon titre',
        'content' => 'Contenu initial',
        'secret_token' => 'super-secret',
    ]);

    $fields = getFields();

    expect($article->audits()->count())->toBe(1);

    $audit = $article->audits()->latest($fields['id'])->first();

    expect($audit->{$fields['event']})->toBe('created');

    $new = $audit->{$fields['new_values']};
    $old = $audit->{$fields['old_values']};

    expect($new)->toHaveKeys(['title', 'content'])
        ->and(Arr::has($new, 'secret_token'))->toBeFalse()
        ->and($old)->toBeArray()->toBeEmpty();
});

it('records an audit on update with correct old_values and new_values, masking hidden fields', function () {
    $article = Article::create([
        'title' => 'Ancien',
        'content' => 'Texte',
        'secret_token' => 'token-1',
    ]);

    $article->update([
        'title' => 'Nouveau',
        'secret_token' => 'token-2',
    ]);

    $fields = getFields();

    expect($article->audits()->count())->toBe(2);

    $audit = $article->audits()->latest($fields['id'])->first();
    expect($audit->{$fields['event']})->toBe('updated');

    $new = $audit->{$fields['new_values']};
    $old = $audit->{$fields['old_values']};

    expect($old['title'])->toBe('Ancien')
        ->and($new['title'])->toBe('Nouveau')
        ->and(Arr::has($new, 'secret_token'))->toBeFalse()
        ->and(Arr::has($old, 'secret_token'))->toBeFalse();
});

it('records a deleted audit keeping old_values masked when remove_on_delete=false', function () {
    config()->set('audit-events.remove_on_delete', false);

    $article = Article::create([
        'title' => 'Titre',
        'content' => 'Contenu',
        'secret_token' => 'mask-me',
    ]);

    $article->delete();

    $fields = getFields();

    expect($article->audits()->count())->toBe(2);

    $audit = $article->audits()->latest($fields['id'])->first();
    expect($audit->{$fields['event']})->toBe('deleted');

    $new = $audit->{$fields['new_values']};
    $old = $audit->{$fields['old_values']};

    expect($new)->toBeArray()->toBeEmpty()
        ->and($old)->toHaveKeys(['title', 'content'])
        ->and(Arr::has($old, 'secret_token'))->toBeFalse();
});

it('does not audit on creation when audit_on_create=false', function () {
    config()->set('audit-events.audit_on_create', false);
    config()->set('audit-events.audit_on_update', true);

    $article = Article::create(['title' => 'Initial', 'content' => 'Texte']);

    expect($article->audits()->count())->toBe(0);

    $article->update(['title' => 'Modifié']);

    $fields = getFields();
    $audit = $article->audits()->latest($fields['id'])->first();

    expect($article->audits()->count())->toBe(1)
        ->and($audit->{$fields['event']})->toBe('updated');
});

it('does not audit on update when audit_on_update=false', function () {
    config()->set('audit-events.audit_on_create', true);
    config()->set('audit-events.audit_on_update', false);

    $article = Article::create(['title' => 'Initial', 'content' => 'Texte']);

    expect($article->audits()->count())->toBe(1);

    $article->update(['title' => 'Modifié']);

    $fields = getFields();
    $latest = $article->audits()->latest($fields['id'])->first();

    expect($article->audits()->count())->toBe(1)
        ->and($latest->{$fields['event']})->toBe('created');
});

it('handles soft delete and force delete correctly', function () {
    config()->set('audit-events.remove_on_delete', true);

    $post = SoftArticle::create(['title' => 'Titre', 'content' => 'X']);
    $fields = getFields();

    $post->delete();
    $last = $post->audits()->latest($fields['id'])->first();
    expect($last->{$fields['event']})->toBe('deleted');

    $post->forceDelete();
    expect($post->audits()->count())->toBe(0);

    config()->set('audit-events.remove_on_delete', false);
    $post2 = SoftArticle::create(['title' => 'Titre 2']);

    $post2->delete();
    $deletedAudit = $post2->audits()->latest($fields['id'])->first();
    expect($deletedAudit->{$fields['event']})->toBe('deleted');

    $beforeForce = $post2->audits()->count();
    $post2->forceDelete();
    expect($post2->audits()->count())->toBe($beforeForce + 1);
});

it('saves history as a free event not subject to whitelist', function () {
    $article = Article::create(['title' => 'Test']);
    $fields = getFields();

    $article->saveHistory('custom.action', ['before' => 'x'], ['after' => 'y'], ['job_id' => 42]);

    $audit = $article->audits()->latest($fields['id'])->first();

    expect($audit->{$fields['event']})->toBe('custom.action')
        ->and($audit->{$fields['context']})->toBe(['job_id' => 42]);
});
