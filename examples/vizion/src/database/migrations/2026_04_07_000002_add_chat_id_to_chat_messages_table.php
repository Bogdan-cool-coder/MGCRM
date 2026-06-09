<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->foreignId('chat_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->json('metadata')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropForeign(['chat_id']);
            $table->dropColumn(['chat_id', 'metadata']);
        });
    }
};
