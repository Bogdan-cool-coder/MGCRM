<?php

declare(strict_types=1);

namespace App\Domain\Automation\Support;

use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;

/**
 * RecipientResolver — parse a recipient/responsible spec string into a concrete
 * target. Mirrors the old project's _resolve_recipient / _resolve_user_id,
 * narrowed to a Deal target.
 *
 * Spec forms:
 *   - "owner"     → the deal's owner_user_id
 *   - "user_id:N" → user N
 *   - "chat_id:N" → raw Telegram chat id N (tg_notify only)
 *   - null/""     → owner (for user specs) / none (for chat specs)
 */
final class RecipientResolver
{
    /**
     * Resolve a user spec ("owner" | "user_id:N") to a user id, or null.
     */
    public static function userId(?string $spec, Deal $target, ?User $owner): ?int
    {
        $spec = $spec === null ? '' : trim($spec);

        if ($spec === '' || $spec === 'owner') {
            return $owner?->id ?? $target->owner_user_id;
        }

        if (str_starts_with($spec, 'user_id:')) {
            $id = (int) substr($spec, strlen('user_id:'));

            return $id > 0 ? $id : null;
        }

        return null;
    }

    /**
     * Resolve a tg_notify recipient spec to a [kind, value] pair.
     *
     *   ['user_id', N]  — resolve N's telegram_user_id before sending
     *   ['chat_id', N]  — send straight to chat/group N
     *   ['none', null]  — no recipient
     *
     * @return array{0: 'user_id'|'chat_id'|'none', 1: int|null}
     */
    public static function telegram(?string $spec, Deal $target, ?User $owner): array
    {
        $spec = $spec === null ? '' : trim($spec);

        if ($spec === '' || $spec === 'owner') {
            $id = $owner?->id ?? $target->owner_user_id;

            return $id !== null ? ['user_id', $id] : ['none', null];
        }

        if (str_starts_with($spec, 'user_id:')) {
            $id = (int) substr($spec, strlen('user_id:'));

            return $id > 0 ? ['user_id', $id] : ['none', null];
        }

        if (str_starts_with($spec, 'chat_id:')) {
            $id = (int) substr($spec, strlen('chat_id:'));

            return $id !== 0 ? ['chat_id', $id] : ['none', null];
        }

        return ['none', null];
    }
}
