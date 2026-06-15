<?php

declare(strict_types=1);

namespace App\Domain\Automation\Support;

use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;

/**
 * MessageFormatter — placeholder substitution for tg_notify / create_task /
 * email templates. Mirrors the old project's _format_message: a plain
 * str_replace pass (NO Blade/Twig) so a config string can never inject template
 * logic or run arbitrary code.
 *
 * Supported tokens: {target_id} {target_title} {owner_name}.
 */
final class MessageFormatter
{
    public static function format(?string $template, Deal $target, ?User $owner): string
    {
        if ($template === null || $template === '') {
            return '';
        }

        return strtr($template, [
            '{target_id}' => (string) $target->id,
            '{target_title}' => (string) ($target->title ?? ''),
            '{owner_name}' => $owner?->full_name ?? '—',
        ]);
    }
}
