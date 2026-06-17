<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licensor_entities', function (Blueprint $table): void {
            $table->id();

            $table->string('country_code', 2)->unique();
            $table->boolean('is_default')->default(true);

            // Legal entity details
            $table->string('legal_form', 64);
            $table->string('full_legal_form', 255);
            $table->string('gender_ending_oe', 16)->default('ое');
            $table->string('name', 255);

            // Director
            $table->string('director_position', 128);
            $table->string('director_short', 128);
            $table->string('director_genitive', 255);
            $table->string('acts_basis', 64)->default('Устава');

            // Tax identification
            $table->string('tax_id_label', 16);
            $table->string('tax_id', 64);

            // Address
            $table->text('address');

            // Primary bank account (main account on the entity itself)
            $table->string('bank', 255);
            $table->string('bank_code_label', 32);
            $table->string('bank_code', 64);
            $table->string('account', 64);

            // Contacts
            $table->string('phone', 64)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('training_login', 255)->nullable();

            $table->timestamps();

            $table->index('country_code', 'ix_licensor_entities_country_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licensor_entities');
    }
};
