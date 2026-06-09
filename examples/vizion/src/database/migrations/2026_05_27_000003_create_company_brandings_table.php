<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * company_brandings — one-to-one with company. Holds the brand assets applied to
 * HTML commercial proposals: logo, palette, fonts, header/footer text, requisites.
 *
 * M1 introduces only the table + model. The branding controller / logo upload
 * and the actual application of branding inside HtmlDocumentService land in M2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_brandings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            // Path to the logo on disk public (M2 upload).
            $table->string('logo_path')->nullable();
            // {primary, secondary, accent, text, bg}
            $table->jsonb('colors')->nullable();
            // {heading, body}
            $table->jsonb('fonts')->nullable();
            // translatable {ru, en} header / footer text
            $table->jsonb('header')->nullable();
            $table->jsonb('footer')->nullable();
            // company requisites (INN, address, bank details, ...)
            $table->jsonb('requisites')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_brandings');
    }
};
