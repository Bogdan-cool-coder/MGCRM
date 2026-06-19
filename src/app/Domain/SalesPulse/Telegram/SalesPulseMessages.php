<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Telegram;

use Carbon\CarbonImmutable;

/**
 * SalesPulseMessages — central RU copy for the SalesPulse bot (spec §7 / §8).
 *
 * Plain constants/methods (not __()) so the wording is deterministic in tests.
 * No secrets / tokens / internal IDs leak into any reply (§И security carried over
 * from the contract bot).
 */
final class SalesPulseMessages
{
    // ---- Admin gating / resolution ----

    public const ADMIN_ONLY = '⛔ Команда доступна только админу.';

    public const MANAGER_NOT_FOUND = '❓ Менеджер не найден в команде.';

    public const NOT_A_MANAGER = '❓ Вы не закреплены менеджером в этой команде. Уточните slug.';

    public const WEEKEND = '⚠️ Сегодня выходной. Плана нет.';

    // ---- /start /help /whoami ----

    public const START = 'SalesPulse на связи. Я слежу за планом/фактом отдела продаж. /help — список команд.';

    public const HELP = "Команды SalesPulse:\n"
        ."/startday [менеджер] [дата] — зафиксировать план дня\n"
        ."/finishday [менеджер] [дата] — зафиксировать факт дня\n"
        ."/progress [дата] — текущая активность команды\n"
        ."/dayresults [дата] — разбор дня (админ)\n"
        ."/weeklyreport [понедельник] — недельный отчёт (админ)\n"
        ."/conversions [N|дата…] — воронка конверсий (админ)\n"
        ."/skipday /unskipday — пропуск дня (админ)\n"
        ."/vacation /unvacation — отпуск менеджера (админ)\n"
        ."/announce_now — ручной анонс (админ)\n"
        .'/whoami — кто я для бота';

    public static function whoami(string $teamName, bool $isAdmin, ?string $managerName): string
    {
        $role = $isAdmin ? 'админ' : ($managerName !== null ? 'менеджер' : 'наблюдатель');
        $who = $managerName !== null ? " ({$managerName})" : '';

        return "Команда: <b>{$teamName}</b>. Ваша роль: {$role}{$who}.";
    }

    // ---- Private-chat TEST MODE (config-gated) ----

    /**
     * The DM greeting / whoami body when the tester is recognised as the admin of the
     * synthetic "ТЕСТ" team: confirm the role, list the available test managers and
     * suggest the core commands. $managerSlugs are the roster tg-slugs that resolved
     * to a seeded account (manager1/2/3); an empty list means the test seeder has not
     * run yet.
     *
     * @param  list<string>  $managerSlugs
     */
    public static function testModeIntro(string $teamName, array $managerSlugs): string
    {
        $roster = $managerSlugs !== []
            ? implode(', ', $managerSlugs)
            : '— (нет засеянных тест-аккаунтов; запустите SalesPulseDemoSeeder)';

        $example = $managerSlugs[0] ?? 'manager1';

        return "🧪 Тест-режим. Вы — <b>админ</b> тест-команды «{$teamName}» (полный доступ).\n"
            ."Тест-менеджеры: {$roster}.\n\n"
            ."Примеры:\n"
            ."/startday {$example} — зафиксировать план дня менеджера\n"
            ."/finishday {$example} — зафиксировать факт дня\n"
            ."/progress — текущая активность команды\n"
            ."/dayresults — разбор дня\n"
            ."/weeklyreport — недельный отчёт\n"
            ."/conversions — воронка конверсий\n"
            .'/announce_now — ручной анонс';
    }

    // ---- /startday flow (spec §7) ----

    public static function pullingPlan(string $name, CarbonImmutable $date): string
    {
        return "⌛ Тяну план {$name} на {$date->toDateString()}...";
    }

    public const PLAN_FIXED = '✅ План зафиксирован.';

    public static function planWeekend(CarbonImmutable $date): string
    {
        return "⚠️ Сегодня выходной ({$date->format('d.m.Y')}). Плана нет.";
    }

    // ---- /finishday flow (spec §7) ----

    public static function pullingFact(string $name, CarbonImmutable $date): string
    {
        return "⌛ Тяну факт {$name} за {$date->toDateString()}...";
    }

    public const FACT_FIXED = '✅ Факт зафиксирован.';

    // ---- Skip / vacation confirmations ----

    public static function skipped(CarbonImmutable $date, ?string $managerName): string
    {
        $who = $managerName !== null ? $managerName : 'вся команда';

        return "⏸ Пропуск зафиксирован на {$date->format('d.m.Y')} — {$who}.";
    }

    public static function skipAlready(CarbonImmutable $date): string
    {
        return "ℹ️ Уже пропущен на {$date->format('d.m.Y')}.";
    }

    public static function unskipped(CarbonImmutable $date): string
    {
        return "▶️ Пропуск снят на {$date->format('d.m.Y')}.";
    }

    public const UNSKIP_NONE = 'ℹ️ Пропуска на эту дату не было.';

    public static function vacationSet(string $managerName, CarbonImmutable $until, int $days): string
    {
        return "🌴 Отпуск для {$managerName} до {$until->format('d.m.Y')} ({$days} раб. дн.).";
    }

    public const VACATION_TOO_SHORT = '⚠️ Отпуск — это 2+ подряд рабочих дня. Уточните период.';

    public static function vacationCleared(string $managerName, int $removed): string
    {
        return "▶️ Отпуск снят для {$managerName} ({$removed} дн.).";
    }

    public const VACATION_NONE = 'ℹ️ Активного отпуска не найдено.';

    // ---- /announce_now (manual announcer trigger, admin) ----

    public const ANNOUNCE_NONE = 'ℹ️ Свежих событий для анонса нет.';

    /**
     * Acknowledgement after a manual announcer run reporting how many events were
     * posted (spec §4 — the announcements themselves go to the team chat).
     */
    public static function announceDone(int $posted): string
    {
        return "✅ Анонсер отработал: событий — {$posted}.";
    }
}
