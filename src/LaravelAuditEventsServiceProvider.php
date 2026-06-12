<?php

namespace SoftArtisan\LaravelAuditEvents;

use Illuminate\Console\Scheduling\Schedule;
use SoftArtisan\LaravelAuditEvents\Commands\AuditEventsArchiveCommand;
use SoftArtisan\LaravelAuditEvents\Commands\AuditEventsStatsCommand;
use SoftArtisan\LaravelAuditEvents\Commands\AuditEventsVerifyCommand;
use SoftArtisan\LaravelAuditEvents\Models\ModelAudit;
use SoftArtisan\LaravelAuditEvents\Services\AuditSignatureService;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelAuditEventsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-audit-events')
            ->hasConfigFile(['audit-events'])
            ->hasMigrations([
                'create_audit_events_table',
                'rename_model_audits_to_audit_events_table',
                'add_context_to_audit_events_table',
                'add_signature_to_audit_events_table',
                'create_audit_events_archive_table',
            ])
            ->hasCommands([
                AuditEventsStatsCommand::class,
                AuditEventsVerifyCommand::class,
                AuditEventsArchiveCommand::class,
            ]);
    }

    public function packageBooted(): void
    {
        // Register AuditContext as a singleton for DI usage
        $this->app->singleton(AuditContext::class, fn () => new AuditContext);

        // Register AuditSignatureService as a singleton
        $this->app->singleton(AuditSignatureService::class, fn () => new AuditSignatureService);

        // Auto-schedule pruning when enabled
        if (config('audit-events.pruning.enabled', false)) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $modelClass = config('audit-events.model_class', ModelAudit::class);
                $schedule->command('model:prune', ['--model' => [$modelClass]])->daily();
            });
        }
    }
}
