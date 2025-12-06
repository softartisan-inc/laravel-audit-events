<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('model_audits', function (Blueprint $table) {
            $fields = config('model-audits.table_fields');
            $table->id($fields['id']);

            $morphName = config('model-audits.table_fields.morph_prefix');
            $morphType = config('model-audits.table_fields.morph_type', 'integer');


            // Création manuelle pour plus de contrôle
            $table->string("{$morphName}_type");
            match ($morphType) {
                'uuid' => $table->uuid("{$morphName}_id"),
                'ulid' => $table->ulid("{$morphName}_id"),
                'string' => $table->string("{$morphName}_id", 64),
                default => $table->unsignedBigInteger("{$morphName}_id"),
            };
            $table->string($fields['event'])->nullable();
            $table->unsignedBigInteger($fields['user_id'])->nullable();
            $table->text($fields['url'])->nullable();
            $table->ipAddress($fields['ip_address'])->nullable();
            $table->text($fields['user_agent'])->nullable();
            $table->json($fields['old_values'])->nullable();
            $table->json($fields['new_values'])->nullable();
            $table->timestamps();

            $table->index(["{$morphName}_type", "{$morphName}_id"]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
