<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 3: distinguish a one-day /skipday from a multi-day /vacation on
 * pulse_skip_days (spec §3). `kind` defaults to 'skip' so the existing Slice 0
 * rows keep their meaning; `vacation_until` carries the vacation end date that
 * /progress renders ("🌴 отпуск до DD.MM"). NULL vacation_until = a plain skip.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pulse_skip_days', function (Blueprint $table): void {
            $table->string('kind', 16)->default('skip')->after('on_date');
            $table->date('vacation_until')->nullable()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('pulse_skip_days', function (Blueprint $table): void {
            $table->dropColumn(['kind', 'vacation_until']);
        });
    }
};
