<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $archiveTable = config('audit-events.archive.table_name', 'audit_events_archive');
        $fields = config('audit-events.table_fields');
        $morphName = $fields['morph_prefix'] ?? 'auditable';
        $morphType = $fields['morph_type'] ?? 'string';

        Schema::create($archiveTable, function (Blueprint $table) use ($fields, $morphName, $morphType) {
            $table->id($fields['id']);

            $table->string("{$morphName}_type")->nullable();
            match ($morphType) {
                'uuid' => $table->uuid("{$morphName}_id")->nullable(),
                'ulid' => $table->ulid("{$morphName}_id")->nullable(),
                'string' => $table->string("{$morphName}_id", 64)->nullable(),
                default => $table->unsignedBigInteger("{$morphName}_id")->nullable(),
            };

            $table->string($fields['event'])->nullable();
            $table->unsignedBigInteger($fields['user_id'])->nullable();
            $table->text($fields['url'])->nullable();
            $table->ipAddress($fields['ip_address'])->nullable();
            $table->text($fields['user_agent'])->nullable();
            $table->json($fields['old_values'])->nullable();
            $table->json($fields['new_values'])->nullable();
            $table->json($fields['context'])->nullable();
            $table->string('signature', 64)->nullable();
            $table->string('previous_hash', 64)->nullable();
            $table->timestamps();
            $table->timestamp('archived_at')->useCurrent();

            $table->index(["{$morphName}_type", "{$morphName}_id"]);
            $table->index('archived_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('audit-events.archive.table_name', 'audit_events_archive'));
    }
};
