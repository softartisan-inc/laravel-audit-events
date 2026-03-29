<?php

namespace SoftArtisan\LaravelAuditEvents\Mcp\Servers;

use Laravel\Mcp\Server;
use SoftArtisan\LaravelAuditEvents\Mcp\Prompts\AuditAnalysisPrompt;
use SoftArtisan\LaravelAuditEvents\Mcp\Tools\AuditHistoryTool;

class AuditEventsServer extends Server
{
    /** @var array<int, class-string> */
    protected array $tools = [
        AuditHistoryTool::class,
    ];

    /** @var array<int, class-string> */
    protected array $prompts = [
        AuditAnalysisPrompt::class,
    ];
}
