<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive columns for the Files API (M2 CRM files sub-step).
 *
 * crm_folders.sort_order — integer for ordering system + user folders.
 * crm_files.disk         — storage disk name (e.g. 'crm_files') so the disk
 *                          can be swapped (local → S3) without touching records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_folders', function (Blueprint $table): void {
            $table->unsignedSmallInteger('sort_order')->default(0)->after('is_system');
        });

        Schema::table('crm_files', function (Blueprint $table): void {
            // Storage disk name; default 'crm_files' (config/filesystems.php).
            $table->string('disk', 64)->default('crm_files')->after('folder_id');
        });
    }

    public function down(): void
    {
        Schema::table('crm_files', function (Blueprint $table): void {
            $table->dropColumn('disk');
        });

        Schema::table('crm_folders', function (Blueprint $table): void {
            $table->dropColumn('sort_order');
        });
    }
};
