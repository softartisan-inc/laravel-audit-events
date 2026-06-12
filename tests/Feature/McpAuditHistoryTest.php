<?php

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use SoftArtisan\LaravelAuditEvents\Mcp\Tools\AuditHistoryTool;
use SoftArtisan\LaravelAuditEvents\Mcp\Tools\SearchAuditEventsTool;
use SoftArtisan\LaravelAuditEvents\Mcp\Tools\VerifyAuditIntegrityTool;
use SoftArtisan\LaravelAuditEvents\Models\ModelAudit;
use SoftArtisan\LaravelAuditEvents\Tests\Fixtures\Article;

/**
 * Exercises the MCP `get_model_audit_history` tool by invoking its handler with a
 * real Request: a valid call returns the model's audit trail; an unknown model
 * class returns a proper MCP error (and never throws).
 */
it('returns a model audit history via the MCP tool', function () {
    $article = Article::create(['title' => 'A']);
    $article->update(['title' => 'B']);

    $request = new Request([
        'model_class' => Article::class,
        'model_id' => (string) $article->id,
        'limit' => 10,
    ]);

    $response = (new AuditHistoryTool)->handle($request);

    // Returns cleanly (the pre-fix code threw on ResponseFactory::asAssistant()).
    expect($response)->toBeInstanceOf(Response::class)
        ->and($response->isError())->toBeFalse();
});

it('reports an MCP error for an unknown model class (without throwing)', function () {
    $request = new Request([
        'model_class' => 'App\\Nope\\DoesNotExist',
        'model_id' => '1',
    ]);

    $response = (new AuditHistoryTool)->handle($request);

    expect($response->isError())->toBeTrue();
});

it('searches the whole trail by event name (incl. free-standing events)', function () {
    Article::create(['title' => 'A']);
    ModelAudit::record('user.logged_in', ['ip' => '127.0.0.1'], 7);

    $response = (new SearchAuditEventsTool)->handle(new Request(['event' => 'user.logged_in']));

    expect($response->isError())->toBeFalse();
});

it('searches the trail by a context key/value', function () {
    ModelAudit::record('asset.status_changed', ['mission_id' => 42], 1);
    ModelAudit::record('asset.status_changed', ['mission_id' => 99], 1);

    $response = (new SearchAuditEventsTool)->handle(new Request([
        'event' => 'asset.status_changed',
        'context_key' => 'mission_id',
        'context_value' => '42',
    ]));

    expect($response->isError())->toBeFalse();
});

it('reports a clear error when integrity verification is disabled', function () {
    config(['audit-events.integrity.enabled' => false]);

    $response = (new VerifyAuditIntegrityTool)->handle(new Request([]));

    expect($response->isError())->toBeTrue();
});
