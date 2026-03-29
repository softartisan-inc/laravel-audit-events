<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * v2 breaking change: rename 'model_audits' → 'audit_events'.
 *
 * Run this migration if you are upgrading from v1.x.
 * New installations do not need this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('model_audits') && ! Schema::hasTable('audit_events')) {
            Schema::rename('model_audits', 'audit_events');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('audit_events') && ! Schema::hasTable('model_audits')) {
            Schema::rename('audit_events', 'model_audits');
        }
    }
};
