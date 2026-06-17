<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_routes', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 255);
            $table->string('document_kind', 32);          // DocumentKind enum value
            $table->foreignId('template_id')
                ->nullable()
                ->constrained('templates')
                ->nullOnDelete();
            $table->boolean('is_default')->default(false);
            $table->json('stages');                        // [{order, name, user_ids[], min_required}]
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('updated_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['document_kind', 'is_active']);
            $table->index(['template_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_routes');
    }
};
