<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();

            // Identity / auth
            $table->string('email')->unique();
            $table->string('password');
            $table->string('full_name');

            // RBAC role mirror (authoritative grants live in spatie tables; this
            // column is the convenience copy used for fast reads and defaults).
            $table->string('role')->index();

            // Profile
            $table->string('telegram_user_id')->nullable()->index();
            $table->string('avatar_path')->nullable();
            $table->string('locale', 5)->default('ru');

            // Org placement. department_id FK is attached after the departments
            // table exists (see add_department_fk migration); manager_id is a
            // self-reference resolved here.
            $table->unsignedBigInteger('department_id')->nullable()->index();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('is_active')->default(true)->index();

            // TOTP 2FA — secret + backup codes encrypted at rest on APP_KEY.
            $table->boolean('totp_enabled')->default(false);
            $table->text('totp_secret')->nullable();
            $table->timestamp('totp_enabled_at')->nullable();
            $table->text('backup_codes')->nullable();

            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
