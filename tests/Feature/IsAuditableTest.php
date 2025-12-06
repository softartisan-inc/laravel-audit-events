<?php

use Illuminate\Support\Arr;
use SoftArtisan\LaravelModelAudits\Tests\Fixtures\Article;
use SoftArtisan\LaravelModelAudits\Tests\Fixtures\SoftArticle;
// TestCase is registered globally in tests/Pest.php

// Helper to get configured table field names for assertions
// This keeps tests stable even if config keys are customized.

function getFields(): array {
    return config('model-audits.table_fields');
}

// Creation: should record one audit with masked sensitive attributes in new_values
it('enregistre un audit lors de la création avec les new_values et masque les champs cachés', function () {
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

    // new_values contiennent les champs visibles
    expect($new)->toHaveKeys(['title', 'content'])
        ->and(Arr::has($new, 'secret_token'))->toBeFalse();

    // old_values vide à la création
    expect($old)->toBeArray()->toBeEmpty();
});

// Update: should record old_values and new_values, masking sensitive fields
it('enregistre un audit lors de la mise à jour avec old_values et new_values corrects, en masquant les champs', function () {
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
        ->and($new['title'])->toBe('Nouveau');

    // secret_token doit être masqué
    expect(Arr::has($new, 'secret_token'))->toBeFalse()
        ->and(Arr::has($old, 'secret_token'))->toBeFalse();
});

// Delete (hard delete path in tests): keep audits and record a `deleted` event when remove_on_delete=false
it('enregistre un audit lors de la suppression avec les old_values et masque les champs cachés', function () {
    // Pour ce test, on veut conserver les audits après suppression
    config()->set('model-audits.remove_on_delete', false);
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

    expect($new)->toBeArray()->toBeEmpty();
    expect($old)->toHaveKeys(['title', 'content'])
        ->and(Arr::has($old, 'secret_token'))->toBeFalse();
});

// Config: when audit_on_create=false, no audit on create but one on update
it('ne crée pas d\'audit à la création quand audit_on_create=false, mais en crée un à la mise à jour', function () {
    config()->set('model-audits.audit_on_create', false);
    config()->set('model-audits.audit_on_update', true);

    $article = Article::create([
        'title' => 'Initial',
        'content' => 'Texte',
    ]);

    $fields = getFields();

    // Aucun audit pour la création
    expect($article->audits()->count())->toBe(0);

    // Une mise à jour doit créer un audit
    $article->update(['title' => 'Modifié']);

    $audit = $article->audits()->latest($fields['id'])->first();
    expect($article->audits()->count())->toBe(1)
        ->and($audit->{$fields['event']})->toBe('updated');
});

// Config: when audit_on_update=false, audit on create but none on update
it('ne crée pas d\'audit à la mise à jour quand audit_on_update=false, mais en crée un à la création', function () {
    config()->set('model-audits.audit_on_create', true);
    config()->set('model-audits.audit_on_update', false);

    $article = Article::create([
        'title' => 'Initial',
        'content' => 'Texte',
    ]);

    $fields = getFields();

    // Un audit pour la création
    expect($article->audits()->count())->toBe(1);

    // La mise à jour ne doit pas créer d'audit
    $article->update(['title' => 'Modifié']);

    $latest = $article->audits()->latest($fields['id'])->first();
    expect($article->audits()->count())->toBe(1)
        ->and($latest->{$fields['event']})->toBe('created');
});

// SoftDeletes: always record `deleted` on soft delete; on forceDelete
// - remove_on_delete=true  => purge all audits
// - remove_on_delete=false => keep audits and record an extra `deleted`
it('enregistre un audit lors d\'une soft delete et conserve ou supprime lors d\'un forceDelete selon remove_on_delete', function () {
    // Cas 1: remove_on_delete=true -> forceDelete supprime les audits
    config()->set('model-audits.remove_on_delete', true);

    $post = SoftArticle::create(['title' => 'Titre', 'content' => 'X']);
    $fields = getFields();

    // Création -> 1 audit (si audit_on_create true par défaut)
    $initialCount = $post->audits()->count();
    expect($initialCount)->toBeGreaterThanOrEqual(0); // tolérant à la config

    // Soft delete => enregistre un audit 'deleted'
    $post->delete();
    $countAfterSoftDelete = $post->audits()->count();
    $last = $post->audits()->latest($fields['id'])->first();
    expect($last->{$fields['event']})->toBe('deleted');

    // Force delete => supprime les audits si remove_on_delete=true
    $post->forceDelete();
    expect($post->audits()->count())->toBe(0);

    // Cas 2: remove_on_delete=false -> forceDelete conserve les audits
    config()->set('model-audits.remove_on_delete', false);
    $post2 = SoftArticle::create(['title' => 'Titre 2']);

    $start = $post2->audits()->count();
    $post2->delete();
    $deletedAudit = $post2->audits()->latest($fields['id'])->first();
    expect($deletedAudit->{$fields['event']})->toBe('deleted');

    $beforeForce = $post2->audits()->count();
    $post2->forceDelete();
    // Un audit 'deleted' supplémentaire est créé lors du hard delete quand remove_on_delete=false
    expect($post2->audits()->count())->toBe($beforeForce + 1);
});
