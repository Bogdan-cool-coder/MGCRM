<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Schema-only (S1.1 scope: model + table, no upload UI yet).
     * Polymorphic attach via (owner_entity_type, owner_entity_id).
     */
    public function up(): void
    {
        Schema::create('crm_folders', function (Blueprint $table): void {
            $table->id();
            $table->string('owner_entity_type', 16);   // contact | company
            $table->unsignedBigInteger('owner_entity_id');
            $table->string('name', 255);
            $table->boolean('is_system')->default(false); // system folders can't be deleted
            $table->timestamps();

            $table->index(['owner_entity_type', 'owner_entity_id']);
        });

        Schema::create('crm_files', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('folder_id')
                ->constrained('crm_folders')
                ->cascadeOnDelete();

            // Denormalized for fast "all files of entity" query (avoids folder JOIN)
            $table->string('owner_entity_type', 16);
            $table->unsignedBigInteger('owner_entity_id');

            $table->string('file_path', 512);
            $table->string('original_name', 255);
            $table->unsignedBigInteger('file_size');           // bytes
            $table->string('mime_type', 255)->nullable();

            $table->foreignId('uploaded_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['owner_entity_type', 'owner_entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_files');
        Schema::dropIfExists('crm_folders');
    }
};
