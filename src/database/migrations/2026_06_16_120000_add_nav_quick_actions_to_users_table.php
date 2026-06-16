<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add nav_quick_actions to users.
 *
 * Stores the user's personalised quick-action navigation: an ordered list of
 * up to 5 string action keys (e.g. ["create_deal", "create_contact"]). Nullable
 * — existing rows keep the default (the SPA falls back to an empty list, which
 * the UserResource normalises to []).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('nav_quick_actions')->nullable()->after('salary_currency');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('nav_quick_actions');
        });
    }
};
