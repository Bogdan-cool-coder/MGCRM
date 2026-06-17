<?php

declare(strict_types=1);

namespace App\Domain\Notification\Telegram;

use App\Domain\Iam\Enums\Role;
use App\Domain\Notification\Enums\LinkRedeemResult;

/**
 * TelegramMessages (S2.9) — central RU copy for all bot replies.
 *
 * Kept as plain constants/methods (not __() lookups) so the exact wording is
 * deterministic in tests regardless of the active locale. None of these texts
 * contain secrets, tokens, emails or internal IDs (§И security).
 */
final class TelegramMessages
{
    // ---- Linking ----

    public static function linkSuccess(string $fullName): string
    {
        return "✅ Готово! Telegram привязан к учётной записи <b>{$fullName}</b>. "
            .'Теперь вы будете получать уведомления о согласованиях.';
    }

    public const LINK_INVALID = '❌ Ссылка недействительна.';

    public const LINK_USED = '❌ Эта ссылка уже была использована.';

    public const LINK_EXPIRED = '❌ Ссылка истекла (срок 10 минут). Сгенерируйте новую в профиле.';

    public const LINK_TAKEN = '❌ Этот Telegram уже привязан к другой учётной записи.';

    public static function forRedeem(LinkRedeemResult $result, string $fullName): string
    {
        return match ($result) {
            LinkRedeemResult::Linked => self::linkSuccess($fullName),
            LinkRedeemResult::Invalid => self::LINK_INVALID,
            LinkRedeemResult::AlreadyUsed => self::LINK_USED,
            LinkRedeemResult::Expired => self::LINK_EXPIRED,
            LinkRedeemResult::LinkedToOther => self::LINK_TAKEN,
        };
    }

    // ---- /start greeting ----

    public static function startLinked(string $fullName, string $roleLabel): string
    {
        return "Здравствуйте, <b>{$fullName}</b>. Вы привязаны к MACRO Global CRM. Роль: {$roleLabel}.";
    }

    public const START_UNLINKED = 'Здравствуйте. Чтобы получать уведомления, '
        .'откройте профиль в CRM → «Привязать Telegram».';

    /** RU label for a role (greeting only; does not belong to the Iam enum). */
    public static function roleLabel(?Role $role): string
    {
        return match ($role) {
            Role::Admin => 'Администратор',
            Role::Director => 'Директор',
            Role::Lawyer => 'Юрист',
            Role::Manager => 'Менеджер',
            Role::Accountant => 'Бухгалтер',
            Role::Cfo => 'Финансовый директор',
            null => '—',
        };
    }

    // ---- Decision prompts / confirmations ----

    public const DECIDE_APPROVED = '✅ Согласовано';

    public const DECIDE_REJECT_PROMPT = '✍️ Ответьте сообщением — укажите причину отклонения. '
        .'Она попадёт автору в карточку.';

    public const DECIDE_REWORK_PROMPT = '✍️ Ответьте сообщением — укажите причину доработки. '
        .'Она попадёт автору в карточку.';

    public const DECIDE_REASON_REQUIRED = 'Причина не может быть пустой. Повторите.';

    public const DECIDE_REASON_SAVED = '💬 Причина сохранена и видна автору в карточке.';

    // ---- Error alerts (callback answers) ----

    public const ERROR_NOT_LINKED = '⛔ Ваш Telegram не привязан к учётной записи.';

    public const ERROR_DOC_NOT_FOUND = '❌ Договор не найден.';

    public const ERROR_NOT_ASSIGNED = 'Вы не назначены согласователем этого этапа.';

    public const ERROR_ALREADY_DECIDED = 'Вы уже приняли решение.';

    public const ERROR_ALREADY_PROCESSED = 'Договор уже обработан.';
}
