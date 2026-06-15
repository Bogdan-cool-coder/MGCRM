<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipelines', function (Blueprint $table): void {
            // Cosmetic node-canvas layout (Phase 2). Nullable, NO default:
            // null = "never laid out" vs {} = "laid out empty" — the front needs
            // to tell them apart. SQLite has no native jsonb; json is portable for
            // tests and maps to jsonb-compatible storage on PG (mirrors `settings`).
            $table->json('graph_layout')->nullable()->after('settings');
        });
    }

    public function down(): void
    {
        Schema::table('pipelines', function (Blueprint $table): void {
            $table->dropColumn('graph_layout');
        });
    }
};
