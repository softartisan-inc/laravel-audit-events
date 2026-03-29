<?php

use SoftArtisan\LaravelAuditEvents\Tests\Fixtures\Role;

it('records a relation.synced audit via recordRelationAudit', function () {
    $role = Role::create(['name' => 'Editor']);
    $before = ['read'];
    $after = ['read', 'write', 'delete'];

    $role->recordRelationAudit('permissions', $before, $after, ['reason' => 'role upgrade']);

    $fields = config('audit-events.table_fields');
    $audit = $role->audits()->latest($fields['id'])->first();

    expect($audit->{$fields['event']})->toBe('relation.synced')
        ->and($audit->{$fields['old_values']})->toBe(['permissions' => $before])
        ->and($audit->{$fields['new_values']})->toBe(['permissions' => $after])
        ->and($audit->{$fields['context']})->toBe(['reason' => 'role upgrade']);
});
