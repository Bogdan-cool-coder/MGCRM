<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * forms — public web forms with a unique slug. channel_id binds the form to a
 * channel for auto-creating a Deal on submit (nullOnDelete — orphaning a form
 * stops it creating deals, which is why ChannelService::delete refuses without
 * ?force when forms are attached).
 *
 * fields is a JSON list of `[{name,label,type,required,options?}]` definitions;
 * a public form-builder UI lands on the integrations sprint — in S1.9 fields are
 * edited as JSON through the admin CRUD.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 255);
            $table->string('public_slug', 64)->unique();
            $table->json('fields')->default('[]');

            $table->foreignId('channel_id')
                ->nullable()
                ->constrained('channels')
                ->nullOnDelete();
            $table->index('channel_id', 'ix_forms_channel');

            $table->text('thank_you_text')->nullable();
            $table->boolean('is_active')->default(true)->index();

            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forms');
    }
};
