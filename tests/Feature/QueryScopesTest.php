<?php

use SoftArtisan\LaravelAuditEvents\Models\ModelAudit;
use SoftArtisan\LaravelAuditEvents\Tests\Fixtures\Article;

it('filters audits by event name via whereEvent scope', function () {
    ModelAudit::record('asset.status_changed', ['mission_id' => 1]);
    ModelAudit::record('asset.status_changed', ['mission_id' => 2]);
    ModelAudit::record('user.logged_in', ['ip' => '127.0.0.1']);

    expect(ModelAudit::whereEvent('asset.status_changed')->count())->toBe(2)
        ->and(ModelAudit::whereEvent('user.logged_in')->count())->toBe(1)
        ->and(ModelAudit::whereEvent('nope')->count())->toBe(0);
});
