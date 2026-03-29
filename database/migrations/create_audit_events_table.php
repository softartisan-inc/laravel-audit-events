<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('audit-events.table_name', 'audit_events'), function (Blueprint $table) {
            $fields = config('audit-events.table_fields');
            $table->id($fields['id']);

            $morphName = config('audit-events.table_fields.morph_prefix', 'auditable');
            $morphType = config('audit-events.table_fields.morph_type', 'string');

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
            $table->timestamps();

            $table->index(["{$morphName}_type", "{$morphName}_id"]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('audit-events.table_name', 'audit_events'));
    }
};
