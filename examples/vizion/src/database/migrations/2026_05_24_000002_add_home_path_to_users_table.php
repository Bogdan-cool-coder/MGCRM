<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds users.home_path — the relative frontend router path the user has
     * starred as their "home" page. After login the SPA redirects here.
     *
     * Stores a raw router path string (e.g. '/reports', '/reports/42',
     * '/dashboards/3') — not an entity reference. nullable + default '/reports'
     * so existing rows get a sane value; application code treats null as
     * '/reports' as well (belt and braces).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('home_path', 255)->nullable()->default('/reports')->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('home_path');
        });
    }
};
