<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assignment_id')
                ->unique()
                ->constrained('course_assignments')
                ->cascadeOnDelete();
            $table->string('certificate_number')->unique();
            $table->timestamp('issued_at');
            $table->string('pdf_path');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
