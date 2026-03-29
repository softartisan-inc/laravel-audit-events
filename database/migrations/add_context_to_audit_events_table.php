<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive migration: adds the 'context' JSON column and makes auditable columns nullable.
 * Only needed if upgrading from a version that already has the audit_events table
 * but does not yet have the context column.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('audit-events.table_name', 'audit_events');
        $fields = config('audit-events.table_fields');

        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($fields) {
            if (! Schema::hasColumn($blueprint->getTable(), $fields['context'])) {
                $blueprint->json($fields['context'])->nullable()->after($fields['new_values']);
            }
        });
    }

    public function down(): void
    {
        $table = config('audit-events.table_name', 'audit_events');
        $fields = config('audit-events.table_fields');

        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($fields) {
            if (Schema::hasColumn($blueprint->getTable(), $fields['context'])) {
                $blueprint->dropColumn($fields['context']);
            }
        });
    }
};
