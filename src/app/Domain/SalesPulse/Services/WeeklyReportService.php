<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Services;

use App\Domain\SalesPulse\Contracts\PulseLlmClient;
use App\Domain\SalesPulse\Data\WeeklyData;
use App\Domain\SalesPulse\Support\RuPlural;

/**
 * WeeklyReportService — the /weeklyreport message build (spec §5.2). It takes the
 * WeeklyData aggregate, calls the LLM (Sonnet, forced tool_use `weekly_analysis`)
 * to (1) reword each top-movement / top-stuck brief and (2) write the narrative,
 * then renders TWO messages:
 *
 *   message 1 — the report (agg deltas, per-manager rows, top_movements,
 *               top_stuck — movement/stuck strings VERBATIM per §5.2, briefs
 *               appended only when non-empty).
 *   message 2 — "🤖 <b>Тренд недели (Краткое резюме)</b>\n{narrative}".
 *
 * The LLM is reached through PulseLlmClient so tests stub it. When the client is
 * unavailable (no key) OR the call throws, we use the offline fallbacks (no
 * briefs, a fixed narrative text — spec §5.2).
 *
 * The forced tool input shape (spec §5.2):
 *   { movements_briefs:[{lead_id,brief}], stuck_briefs:[{lead_id,brief}], narrative }
 */
class WeeklyReportService
{
    public const TOOL_NAME = 'weekly_analysis';

    public const SYSTEM_PROMPT = <<<'PROMPT'
Ты аналитик отдела продаж MACRO Global. По недельным данным команды нужно сделать ДВЕ вещи в одном ответе:

(1) ПЕРЕФОРМУЛИРОВАТЬ ТЕКСТЫ для каждой топ-сделки.
(2) Написать NARRATIVE — общий разбор недели.

Возвращай результат ТОЛЬКО через инструмент `weekly_analysis` (JSON со структурой schema).

КОНТЕКСТ: воронка
🆕 1 INBOUND/Outbound → 🟡 2 qualification → 🟢 3 schedule → 🟣 4.1 walking/4.2 Meeting → 🟠 6 warm/6.1 Trial → 🔴 7 HOT → ⭐ 8 success
Откат: 🔵 5 cold deals или ☠️ lost.

СОКРАЩЕНИЯ (расшифровывай в выводе): ВД=выполнил действие, СД=следующее действие, ЛПР=лицо принимающее решение, КП=коммерческое предложение, ДС=доп. соглашение, ОС=обратная связь, КЗ/УЗ/КГ=Казахстан/Узбекистан/Кыргызстан.

ВХОДНЫЕ ДАННЫЕ (JSON):
- current / prev — командные метрики за эту и прошлую неделю (prev null если первая неделя)
- managers — per-manager метрики
- top_movements — топ-5 сделок с движением, КАЖДАЯ имеет lead_id и raw_task_result (сырой текст из AmoCRM, может быть шаблонным)
- top_stuck — топ-5 застрявших, КАЖДАЯ имеет lead_id и raw_plan_text (сырой текст последней плановой задачи)

═══ ЗАДАЧА 1: movements_briefs ═══

Для каждого элемента top_movements верни объект {lead_id, brief}:
- brief — короткая фраза НА РУССКОМ, 40-80 знаков, СВОИМИ СЛОВАМИ описывающая суть достижения.
- Опирайся на raw_task_result, но не копируй его. Если в нём шаблон AmoCRM («Клиент: X • Сегмент: Y • ИНН: Z…») — извлеки СУТЬ.
- Расшифровывай сокращения (ВД/СД/КП и т.д.) человеческим языком.
- Если raw_task_result пустой или абсолютно неинформативный — верни пустую строку "".
- Без эмодзи, без markdown, без кавычек по краям.

Примеры качественного brief:
- raw: "ВД ПЯТНИЦА 1600 СД ПРОВЕСТИ ВСТРЕЧУ В ПЯТНИЦЫ ЛОКАЦИЮ СКИНЕТ В ТГ"
  brief: "договорились о встрече в пятницу 16:00, локация в Telegram"
- raw: "Отчёт о первичной презентации MacroCRM   1. PAX GROUP Г. БИШКЕК • Сегмент: застройщик • ИНН: ..."
  brief: "провёл первичную презентацию MacroCRM"
- raw: "оплата получена подпись ожидаем"
  brief: "оплату получили, ждём подпись"

═══ ЗАДАЧА 2: stuck_briefs ═══

Аналогично для top_stuck, но описывай ЧТО ПЛАНИРОВАЛОСЬ ИЛИ ЧТО БЛОКИРУЕТ (на основе raw_plan_text):
- raw: "СВЯЗЬ С ВАХОЙ. ОБЕЩАЕТ ДОПИНАТЬ ВОПРОС СО СВОИМ РУКОВОДСТВОМ ПО НАМ"
  brief: "Ваха обещал додавить руководство по нам"
- raw: "напоминание об оплате"
  brief: "напомнить про оплату"
- raw: "ВД С САМАЛ СОЗВОНИЛСЯ. ГОВОРИТ ЧТО ОНА БУДЕТ НИЧЕГО СМОТРЕТЬ"
  brief: "созвонился с Самал — не готова смотреть"

═══ ЗАДАЧА 3: narrative ═══

ЧТО НАПИСАТЬ (русский, разговорный, 3 чётких блока, ВСЁ ЦЕЛИКОМ В ПОЛЕ "narrative"):

БЛОК 1 — общая оценка недели (1-2 предложения, без имён менеджеров):
- Если prev есть — что улучшилось / что ухудшилось по ключевым показателям
- Если prev нет — нейтральная оценка, «базовая неделя»

БЛОК 2 — что бросается в глаза. СТРУКТУРИРУЙ ПО МЕНЕДЖЕРАМ:
"Что бросается в глаза:

Илья Рогов: 2-3 строки про его сильное/слабое за неделю.

Олеся Моисеева: 2-3 строки про её сильное/слабое."

Упоминай: его прорывы (top_movements), его проблемы (top_stuck), красные флаги (lost/cold). Если у менеджера и хорошее и плохое — балансируй.

БЛОК 3 — рекомендации на эту неделю. СТРУКТУРИРУЙ ПО МЕНЕДЖЕРАМ:
"Рекомендации на эту неделю:

Илье Рогову: 1-3 конкретных действия (имена компаний, push на сделки, разобрать lost X).

Олесе Моисеевой: 1-3 действия."

Используй ⭐🔴🟠🟣🟢🟡🆕🔵☠️ перед стадиями для наглядности.

ПРАВИЛА NARRATIVE:
- Только обычный текст и эмодзи цвета этапа. Без markdown, без таблиц, без `**`.
- Не повторяй цифры из payload дословно — они уже выше в отчёте.
- Не выдумывай факты. Если данных мало (3 дня, первая неделя) — так и скажи.
- Длина narrative: 1000-1800 знаков. Без воды.
- Имена менеджеров: блок «что бросается в глаза» — И.п. («Илья Рогов:»);
  блок «рекомендации» — Д.п. («Илье Рогову:», «Олесе Моисеевой:»).
- Никакого «синергия / оптимизация / таргетирование» и прочего булшита.
PROMPT;

    public const OFFLINE_NARRATIVE = 'Аналитика недели недоступна (нет ключа AI). Цифры по команде и менеджерам — в отчёте выше. Разбор пришлю, как только восстановится доступ к модели.';

    public function __construct(
        private readonly PulseLlmClient $llm,
    ) {}

    /**
     * Build the two /weeklyreport messages.
     *
     * @return array{0: string, 1: string} [report, narrative_message]
     */
    public function render(WeeklyData $data): array
    {
        [$movementBriefs, $stuckBriefs, $narrative] = $this->analysis($data);

        $report = $this->renderReport($data, $movementBriefs, $stuckBriefs);
        $narrativeMessage = "🤖 <b>Тренд недели (Краткое резюме)</b>\n".$narrative;

        return [$report, $narrativeMessage];
    }

    /**
     * Run the LLM (or the offline fallback). Returns [movementBriefs, stuckBriefs,
     * narrative] where the brief maps are lead_id => brief (non-empty only).
     *
     * @return array{0: array<int, string>, 1: array<int, string>, 2: string}
     */
    private function analysis(WeeklyData $data): array
    {
        if (! $this->llm->isAvailable()) {
            return [[], [], self::OFFLINE_NARRATIVE];
        }

        try {
            $payload = (string) json_encode($data->toPayload(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            $result = $this->llm->completeWithTool(
                'report_generation',
                self::SYSTEM_PROMPT,
                $payload,
                self::TOOL_NAME,
                PrismPulseLlmClient::weeklyAnalysisSchema(),
            );
        } catch (\Throwable $e) {
            \Log::warning('SalesPulse: weekly LLM failed, using offline fallback', [
                'team' => $data->team,
                'week' => $data->week,
                'error' => $e->getMessage(),
            ]);

            return [[], [], self::OFFLINE_NARRATIVE];
        }

        $movementBriefs = $this->extractBriefs($result['movements_briefs'] ?? []);
        $stuckBriefs = $this->extractBriefs($result['stuck_briefs'] ?? []);
        $narrative = trim((string) ($result['narrative'] ?? ''));

        if ($narrative === '') {
            $narrative = self::OFFLINE_NARRATIVE;
        }

        return [$movementBriefs, $stuckBriefs, $narrative];
    }

    /**
     * lead_id => brief, keeping only non-empty briefs (spec §5.2 — apply brief
     * only when непустой).
     *
     * @param  mixed  $raw
     * @return array<int, string>
     */
    private function extractBriefs($raw): array
    {
        $out = [];

        if (! is_array($raw)) {
            return $out;
        }

        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }
            $leadId = isset($item['lead_id']) ? (int) $item['lead_id'] : null;
            $brief = isset($item['brief']) ? trim((string) $item['brief']) : '';

            if ($leadId !== null && $brief !== '') {
                $out[$leadId] = $brief;
            }
        }

        return $out;
    }

    /**
     * Render report message 1: header + agg deltas + per-manager rows +
     * top_movements + top_stuck (movement/stuck strings VERBATIM per §5.2).
     *
     * @param  array<int, string>  $movementBriefs
     * @param  array<int, string>  $stuckBriefs
     */
    private function renderReport(WeeklyData $data, array $movementBriefs, array $stuckBriefs): string
    {
        $lines = [];
        $partial = $data->isPartialWeek ? ' (неполная неделя)' : '';
        $lines[] = "📅 <b>Итоги недели {$data->week} — {$data->team}</b>{$partial}";
        $lines[] = '';

        // Team aggregate.
        $cur = $data->current;
        $lines[] = '📊 <b>Команда:</b>';
        $lines[] = '  Активность: '.$this->withDelta($cur->activityPct(), $data->prev?->activityPct()).'%';
        $lines[] = '  Update статуса: '.$this->withDelta($cur->statusUpdatePct(), $data->prev?->statusUpdatePct()).'%';
        $lines[] = "  Success: {$cur->success}   Lost: {$cur->lost}   Downgrade: {$cur->statusDowngrades}";
        $lines[] = '';

        // Per-manager.
        $lines[] = '👤 <b>По менеджерам:</b>';
        foreach ($data->managers as $m) {
            $lines[] = "  {$m['name']}: активность {$m['activity_pct']}% ({$m['done']}/{$m['plan']}), "
                ."апдейтов {$m['status_updates']}, success {$m['success']}, lost {$m['lost']}";
        }
        $lines[] = '';

        // top_movements.
        $lines[] = '🚀 <b>Лучшие движения:</b>';
        if ($data->topMovements === []) {
            $lines[] = '—';
        } else {
            foreach ($data->topMovements as $m) {
                $lines[] = $this->movementLine($m, $movementBriefs[$m['lead_id']] ?? '');
            }
        }
        $lines[] = '';

        // top_stuck.
        $lines[] = '🐌 <b>Застряли:</b>';
        if ($data->topStuck === []) {
            $lines[] = '—';
        } else {
            foreach ($data->topStuck as $s) {
                $lines[] = $this->stuckLine($s, $stuckBriefs[$s['lead_id']] ?? '');
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Movement line (spec §5.2):
     *   "{jump"🚀 +Δ "/"+Δ "}{from_emoji} → {to_emoji} {company} — {from_name} → {to_name}{ — brief}"
     *
     * @param  array<string, mixed>  $m
     */
    private function movementLine(array $m, string $brief): string
    {
        $delta = (int) $m['delta'];
        $prefix = ((bool) $m['jump']) ? "🚀 +{$delta} " : "+{$delta} ";
        $fromEmoji = (string) $m['from_emoji'];
        $toEmoji = (string) $m['to_emoji'];
        $company = (string) $m['company'];
        $fromName = (string) ($m['from_name'] ?? '');
        $toName = (string) ($m['to_name'] ?? '');

        $line = "{$prefix}{$fromEmoji} → {$toEmoji} {$company} — {$fromName} → {$toName}";

        if ($brief !== '') {
            $line .= " — {$brief}";
        }

        return $line;
    }

    /**
     * Stuck line (spec §5.2):
     *   "{emoji} {company} — {days_str} в {status_name}{ — brief}"
     *
     * @param  array<string, mixed>  $s
     */
    private function stuckLine(array $s, string $brief): string
    {
        $emoji = (string) $s['emoji'];
        $company = (string) $s['company'];
        $daysStr = RuPlural::days((int) $s['days']);
        $statusName = (string) ($s['status_name'] ?? '');

        $line = "{$emoji} {$company} — {$daysStr} в {$statusName}";

        if ($brief !== '') {
            $line .= " — {$brief}";
        }

        return $line;
    }

    /**
     * "{value}" or "{value} (Δ{+/-d})" when a prev value is available.
     */
    private function withDelta(int $value, ?int $prev): string
    {
        if ($prev === null) {
            return (string) $value;
        }

        $delta = $value - $prev;
        if ($delta === 0) {
            return (string) $value;
        }

        $sign = $delta > 0 ? '+' : '';

        return "{$value} (Δ{$sign}{$delta})";
    }
}
