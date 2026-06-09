<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();                                    // bigint, auto-increment, PK
            $table->string('name');                          // VARCHAR(255)
            $table->boolean('is_system')->default(false);    // BOOLEAN, по умолчанию false
            $table->string('macrodata_host')->nullable();    // VARCHAR(255), может быть NULL
            $table->integer('macrodata_port')->nullable();   // INTEGER, может быть NULL
            $table->string('macrodata_database')->nullable();
            $table->string('macrodata_username')->nullable();
            $table->text('macrodata_password')->nullable();  // TEXT (шифруется в модели)
            $table->timestamps();                            // created_at + updated_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
