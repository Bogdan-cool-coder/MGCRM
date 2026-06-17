<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('deal_id')
                ->constrained('deals')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('field', 100);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['deal_id', 'created_at'], 'ix_deal_audits_deal');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_audits');
    }
};
