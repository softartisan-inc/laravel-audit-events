<?php

use SoftArtisan\LaravelAuditEvents\AuditContext;
use SoftArtisan\LaravelAuditEvents\Models\ModelAudit;

afterEach(function () {
    AuditContext::reset();
});

it('records a free event without an auditable model', function () {
    $fields = config('audit-events.table_fields');
    $morph = config('audit-events.table_fields.morph_prefix', 'auditable');

    $audit = ModelAudit::record('user.logged_in', ['ip' => '127.0.0.1'], 7);

    expect($audit->{$fields['event']})->toBe('user.logged_in')
        ->and($audit->{$fields['user_id']})->toBe(7)
        ->and($audit->{$fields['context']})->toBe(['ip' => '127.0.0.1'])
        ->and($audit->{"{$morph}_type"})->toBeNull()
        ->and($audit->{"{$morph}_id"})->toBeNull();
});

it('resolves user_id from AuditContext when no causerId passed', function () {
    AuditContext::actingAs(55);

    $fields = config('audit-events.table_fields');
    $audit = ModelAudit::record('csv.exported');

    expect($audit->{$fields['user_id']})->toBe(55);
});

it('persists old_values and new_values as empty arrays', function () {
    $fields = config('audit-events.table_fields');
    $audit = ModelAudit::record('pdf.generated', ['resource' => 'invoice'], 1);

    expect($audit->{$fields['old_values']})->toBe([])
        ->and($audit->{$fields['new_values']})->toBe([]);
});
