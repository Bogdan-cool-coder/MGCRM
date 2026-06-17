<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S2.7: message_templates — каталог текстовых шаблонов рассылок.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('subject')->nullable();
            $table->text('body');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_templates');
    }
};
