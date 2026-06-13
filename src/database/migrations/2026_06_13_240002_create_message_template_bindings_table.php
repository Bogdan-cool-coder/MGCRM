<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S2.7: message_template_bindings — полиморфные привязки шаблонов рассылок
 * к каналам / воронкам / стадиям / типам активностей / слотам автоматизаций.
 *
 * Нет updated_at — биндинг immutable (удаляется и пересоздаётся).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_template_bindings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_template_id')
                ->constrained('message_templates')
                ->cascadeOnDelete();

            // Каждое поле — nullable (пусто = wildcard по этому измерению).
            $table->string('channel_kind', 16)->nullable();
            $table->foreignId('pipeline_id')->nullable()->constrained('pipelines')->nullOnDelete();
            $table->foreignId('pipeline_stage_id')->nullable()->constrained('pipeline_stages')->nullOnDelete();
            $table->string('activity_type', 16)->nullable();
            $table->string('automation_slot', 64)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('message_template_id');
            $table->index('channel_kind');
            $table->index('pipeline_stage_id');
            $table->index('automation_slot');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_template_bindings');
    }
};
