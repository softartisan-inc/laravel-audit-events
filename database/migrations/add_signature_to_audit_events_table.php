<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('audit-events.table_name', 'audit_events');

        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            if (! Schema::hasColumn($blueprint->getTable(), 'signature')) {
                $blueprint->string('signature', 64)->nullable()->after('context');
            }

            if (! Schema::hasColumn($blueprint->getTable(), 'previous_hash')) {
                $blueprint->string('previous_hash', 64)->nullable()->after('signature');
            }
        });
    }

    public function down(): void
    {
        $table = config('audit-events.table_name', 'audit_events');

        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            if (Schema::hasColumn($blueprint->getTable(), 'previous_hash')) {
                $blueprint->dropColumn('previous_hash');
            }

            if (Schema::hasColumn($blueprint->getTable(), 'signature')) {
                $blueprint->dropColumn('signature');
            }
        });
    }
};
