<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * notifications — in-app notification feed (task #9). Source of truth for the
 * navigation notification flyout. IN-APP channel only (email/TG out of scope).
 *
 * Recipient-scoped: every row belongs to exactly one user (the receiver). The
 * (user_id, read_at) composite index serves the two hot queries — unread count
 * and the "needs attention" bucket — without a full table scan per user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();

            // Receiver. Cascade-delete: a notification has no meaning once its
            // recipient is gone.
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // category — coarse bucket for the digest counters + UI icon/colour.
            // String (NotificationCategory enum) so adding a category never needs
            // a migration. Indexed for the per-category digest grouping.
            $table->string('category', 32)->index();

            $table->string('title', 255);
            $table->text('body')->nullable();

            // Actionable = "needs attention". Drives the actionable bucket in the
            // grouped feed. Default false (plain informational item).
            $table->boolean('is_actionable')->default(false);

            // Front-end route / resource to open on click (e.g. /deals/42,
            // /documents/7). Nullable — informational items may have no target.
            $table->string('deep_link', 512)->nullable();

            // Arbitrary structured payload (entity ids, actor, etc.) the flyout
            // can use without extra lookups.
            $table->json('data')->nullable();

            // NULL = unread. Set to the read timestamp on mark-read.
            $table->timestamp('read_at')->nullable();

            // created_at only — notifications are immutable once created (a read
            // mark flips read_at, it does not "update" the notification body).
            $table->timestamp('created_at')->nullable();

            // Hot path: unread count + actionable bucket, both filtered by
            // (user_id, read_at IS NULL). Ordered feed uses (user_id, id desc).
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
