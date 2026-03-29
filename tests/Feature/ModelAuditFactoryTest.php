<?php

use SoftArtisan\LaravelAuditEvents\Models\ModelAudit;

it('creates a ModelAudit via factory', function () {
    $audit = ModelAudit::factory()->create();

    expect($audit)->toBeInstanceOf(ModelAudit::class)
        ->and($audit->exists)->toBeTrue();
});

it('creates a created-state audit via factory', function () {
    $fields = config('audit-events.table_fields');
    $audit = ModelAudit::factory()->created()->create();

    expect($audit->{$fields['event']})->toBe('created')
        ->and($audit->{$fields['old_values']})->toBe([]);
});

it('creates a free event audit via factory', function () {
    $fields = config('audit-events.table_fields');
    $morph = config('audit-events.table_fields.morph_prefix', 'auditable');
    $audit = ModelAudit::factory()->free('export.csv')->create();

    expect($audit->{$fields['event']})->toBe('export.csv')
        ->and($audit->{"{$morph}_type"})->toBeNull()
        ->and($audit->{"{$morph}_id"})->toBeNull();
});
