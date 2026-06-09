<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * dashboard_widget — pivot linking dashboards to widgets BY REFERENCE
 * (decision #2: shared, not a copy). Carries the grid-layout-plus layout
 * (x/y/w/h), sort order, and per-placement visibility (server-side, decision
 * #9).
 *
 * widget_id uses restrictOnDelete: a widget that is placed on any dashboard
 * cannot be deleted out from under it. The pivot row must be removed first.
 * dashboard_id cascades: deleting a dashboard removes all its placements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_widget', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dashboard_id')->constrained()->cascadeOnDelete();
            $table->foreignId('widget_id')->constrained()->restrictOnDelete();
            $table->integer('x')->default(0);
            $table->integer('y')->default(0);
            $table->integer('w')->default(1);
            $table->integer('h')->default(1);
            $table->integer('sort')->default(0);
            $table->boolean('visible')->default(true);
            $table->timestamps();

            $table->unique(['dashboard_id', 'widget_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_widget');
    }
};
