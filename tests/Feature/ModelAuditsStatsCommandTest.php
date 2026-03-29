<?php

use SoftArtisan\LaravelAuditEvents\Tests\Fixtures\Article;

it('runs audit-events:stats without errors when table is empty', function () {
    $this->artisan('audit-events:stats')->assertExitCode(0);
});

it('runs audit-events:stats and shows totals when audits exist', function () {
    Article::create(['title' => 'First']);
    Article::create(['title' => 'Second']);

    $this->artisan('audit-events:stats')
        ->assertExitCode(0);
});
