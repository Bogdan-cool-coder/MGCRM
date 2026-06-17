<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            // 1000 = 10.00%  — stored as integer, never float
            $table->integer('rate_pct_times_100')->default(0);
            $table->string('base_currency', 3)->default('RUB');
            // personal_deals | any_deal
            $table->string('scope', 30)->default('personal_deals');
            $table->boolean('applies_to_first_payment_only')->default(true);
            $table->boolean('requires_signed_contract')->default(true);
            // immediate | monthly | quarterly
            $table->string('payment_trigger', 20)->default('immediate');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_rules');
    }
};
