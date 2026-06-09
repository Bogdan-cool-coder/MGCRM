<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dashboards — a personal composition of widgets (which widgets, where, what
 * size, visible or not). Visibility mirrors reports: system (company-wide) +
 * personal + published (decision O1). Layout itself lives in the
 * dashboard_widget pivot, not here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // null user_id => system dashboard (no owner).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('name');
            $table->boolean('is_system')->default(false);
            $table->boolean('is_published')->default(false);
            $table->timestamps();

            $table->index(['is_system', 'company_id']);
            $table->index(['user_id', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboards');
    }
};
