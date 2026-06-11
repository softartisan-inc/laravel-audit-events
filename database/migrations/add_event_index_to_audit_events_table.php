<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive migration: add an index on the `event` column.
 * Guarded so it is safe whether or not the index already exists
 * (e.g. fresh installs where create_audit_events_table already added it).
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('audit-events.table_name', 'audit_events');
        $eventColumn = config('audit-events.table_fields.event', 'event');

        if (! Schema::hasTable($table)) {
            return;
        }

        if (Schema::hasIndex($table, [$eventColumn])) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($eventColumn) {
            $blueprint->index($eventColumn);
        });
    }

    public function down(): void
    {
        $table = config('audit-events.table_name', 'audit_events');
        $eventColumn = config('audit-events.table_fields.event', 'event');

        if (! Schema::hasTable($table) || ! Schema::hasIndex($table, [$eventColumn])) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($eventColumn) {
            $blueprint->dropIndex([$eventColumn]);
        });
    }
};
