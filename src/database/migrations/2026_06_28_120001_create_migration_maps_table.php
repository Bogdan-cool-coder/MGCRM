<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bulk auto-generated translation maps for the AMO -> MGCRM migration
 * (Domain/Migration, dropped at M12).
 *
 * Holds high-volume mappings such as custom-field definitions and their option
 * values: amo_id is the AMO field/option id, amo_parent_id scopes options under
 * their parent field (NULL for top-level entries). target_code / target_id /
 * target_meta describe what the AMO entry resolves to on our side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migration_maps', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('map_type', 40);
            $table->string('amo_id', 64);
            // Scopes options under their parent field; NULL for top-level entries.
            $table->string('amo_parent_id', 64)->nullable();
            $table->string('target_code', 120)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->jsonb('target_meta')->nullable();
            $table->timestamps();

            $table->unique(['map_type', 'amo_id', 'amo_parent_id'], 'uq_migration_maps_type_amo_parent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_maps');
    }
};
