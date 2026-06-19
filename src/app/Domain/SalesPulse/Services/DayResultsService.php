<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Services;

use App\Domain\Sales\Models\PipelineStage;
use App\Domain\SalesPulse\Contracts\PulseLlmClient;
use App\Domain\SalesPulse\Data\DaySnapshot;
use App\Domain\SalesPulse\Data\PulseTaskRow;
use App\Domain\SalesPulse\Data\StageMeta;
use Illuminate\Database\Eloquent\Collection;

/**
 * DayResultsService — the /dayresults per-manager analysis (spec §5.1). Builds the
 * §5.1 payload (closed_today_tasks / plan_pending_tasks), classifies a hint per
 * closed task (FLAG lost/cold, WIN success / переходная / 🚀 скачок) and asks the
 * LLM (Haiku) for a two-block разбор, wrapped as "<b>{name}</b>\n\n{body}".
 *
 * The LLM is reached through PulseLlmClient so tests stub it. When the client is
 * unavailable (no key) OR the call throws, renderForManagerOffline() builds the
 * deterministic offline fallback (spec §5.1 — buckets by status_sort_key, top-6,
 * fixed headers).
 *
 * Stage emoji / move classification come from StageClassificationService +
 * StageMeta over the snapshots' leads_by_id (status_name is recovered from
 * tasks[].deal_stage_name, spec §2). Stages are loaded once and memoised.
 */
class DayResultsService
{
    public const SYSTEM_PROMPT = <<<'PROMPT'
Ты аналитик отдела продаж MACRO Global. По данным менеджера за день сделай разбор по двум блокам.

КОНТЕКСТ: воронка MACRO Global проходит этапы:
🆕 1 INBOUND/Outbound → 🟡 2 qualification → 🟢 3 schedule → 🟣 4.1 walking/4.2 Meeting → 🟠 6 warm/6.1 Trial → 🔴 7 HOT → ⭐ 8 success
Откат: 🔵 5 cold deals или ☠️ lost.

ЦВЕТА (используй эти эмодзи перед названием этапа ВСЕГДА):
🆕 = INBOUND/Outbound/Неразобранное
🟡 = 2 qualification
🟢 = 3 schedule a meeting
🟣 = 4.1 walking, 4.2 Meeting
🔵 = 5 cold deals (откат)
🟠 = 6 warm deals, 6.1 Trial
🔴 = 7 HOT deals
⭐ = 8 success
☠️ = lost

ПЕРЕХОДНЫЕ ЗАДАЧИ ✅ — их закрытие = ВСЕГДА продвижение (если сделка не ушла в lost/cold одновременно):
- 4.1 MeetingDone ✅ — встреча проведена → переход в 6 warm (или 5 cold если плохо)
- 6.1 Proposal ✅ — КП отправлено → 6.1 Trial или 7 HOT
- 7.1 Contract ✅ — договор отправлен → 8 success
- 8.1 Success ✅ — договор подписан

СОКРАЩЕНИЯ в результатах задач (расшифровывай в выводе человеческим языком):
- ВД = выполнил действие, СД = следующее действие, ЛПР = лицо принимающее решение
- РОП/РОМ = руководители отдела продаж/маркетинга
- КП = коммерческое предложение, ДС = доп. соглашение к договору, ОС = обратная связь
- КЗ = Казахстан, УЗ/УЗБ = Узбекистан, КГ = Кыргызстан
- AI = AI-линейка MACRO, CRM = MacroCRM/MacroSales, ERP = MacroERP

🏆 Ключевые достижения — РЕАЛЬНЫЙ ПРОГРЕСС:
- переходные задачи (4.1/6.1/7.1/8.1) закрыты на сделках в активных или горячих этапах
- сделка переехала на этап вперёд по воронке
- 🚀 СКАЧОК через этап (2→4.2 минуя 3, 4.2→7 минуя 6, 6→8 минуя 6.1+7) — отмечай отдельно как «🚀 скачок»
- ушла в 8 success (оплата/договор подписан)
- содержательный звонок где клиент дал согласие/договорённости

🚩 Красные флаги — ПРОБЛЕМЫ:
- сделка ушла в lost (status_after=lost) — ВСЕГДА флаг, даже если задача формально закрыта
- сделка ушла в 5 cold deals (откат) — ВСЕГДА флаг
- встреча запланирована (4.1 MeetingDone) но не выполнена
- КП отправлено больше 5 дней назад без касания (Follow up)
- результаты «нет ответа», «не дозвонился», «перенесли», «после праздников», «клиент думает»
- «закрываю», «сворачиваются», «не наша тема», «ушли к конкуренту»
- клиент не платит, не подписывает, тянет время

SLA-флаги по `days_in_stage` (сколько дней сделка стоит в текущем этапе):
- 🟡 qualification > 5 дней → флаг «висит на квалификации»
- 🟢 schedule > 3 дней без MeetingDone → «не назначили встречу»
- 🟣 Meeting/walking > 1 дня без MeetingDone → «не провели встречу»
- 🟠 warm/Trial > 3 дней без Proposal/Follow up → «КП не отправили / нет касания»
- 🔴 HOT > 1 дня без Contract/Success → «зависли на финале»
- 🔵 cold > 30 дней → «давно мёртвая»
- Если `carryover_days >= 3` (задача висит 3+ дней в плане без выполнения) → ♻️ «откладывается N дней»

ПРАВИЛА ФОРМАТА:
- Ровно два раздела с заголовками "🏆 Ключевые достижения" и "🚩 Красные флаги"
- В каждом — 2-6 коротких пунктов. Если пусто — поставь "—"
- Формат пункта: "ЭМОДЗИ_УТРЕННЕГО (Компания) — этап утром → этап вечером — суть"
  Первый эмодзи (столбик слева) — ВСЕГДА цвет утреннего этапа сделки.
  Если этап не менялся за день — пиши один этап без стрелки.
  Пример: "🟣 (Apart Developer) — 4.2 Meeting → 🔴 7 HOT — провели встречу, договор подпишут завтра"
  Пример: "🟡 (SB invest Group) — 2 qualification → ☠️ lost — закрываем, нет интереса"
  Пример: "🚀 🟣 (KazSMU) — 4.2 Meeting → 🔴 7 HOT — после встречи сразу в HOT"
  Пример: "🔴 (Beles) — 7 HOT — клиент не отвечает третий день"
- ВНУТРИ каждого блока сортируй пункты по утреннему статусу от самого ГОРЯЧЕГО к НАЧАЛЬНОМУ:
  ⭐ success → 🔴 HOT → 🟠 warm/Trial → 🟣 walking/Meeting → 🟢 schedule → 🟡 qualification → 🆕 INBOUND/Outbound → 🔵 cold → ☠️ lost
- Закрытие задачи на сделке в lost/cold = красный флаг, НЕ достижение
- Расшифровывай ВД/СД/КП/КЗ и т.д. человеческим языком, не оставляй сокращений в выводе
- Никаких таблиц, никаких ** или markdown. Только эмодзи и обычный текст
- Не выдумывай факты. Если в данных пусто — пиши "—"
- Язык — русский
PROMPT;

    public function __construct(
        private readonly PulseLlmClient $llm,
        private readonly StageClassificationService $classifier,
    ) {}

    /**
     * @var array<int, PipelineStage|null> stage_id => stage, memoised per call.
     */
    private array $stageCache = [];

    /**
     * The full /dayresults message for one manager. Uses the LLM when available,
     * else the deterministic offline fallback.
     *
     * @param  array<int, true>  $dealIdsWithNotesToday
     */
    public function renderForManager(
        string $managerName,
        ?DaySnapshot $morningPlan,
        DaySnapshot $eveningSnap,
        array $dealIdsWithNotesToday,
        int $missed = 0,
    ): string {
        $this->primeStageCache($morningPlan, $eveningSnap);

        if (! $this->llm->isAvailable()) {
            return $this->renderForManagerOffline($managerName, $eveningSnap, $missed);
        }

        try {
            $payload = $this->buildPayload($managerName, $morningPlan, $eveningSnap, $dealIdsWithNotesToday);
            $body = $this->llm->completeText(
                'quick_qa',
                self::SYSTEM_PROMPT,
                (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            );
        } catch (\Throwable $e) {
            \Log::warning('SalesPulse: dayresults LLM failed, using offline fallback', [
                'manager' => $managerName,
                'error' => $e->getMessage(),
            ]);

            return $this->renderForManagerOffline($managerName, $eveningSnap, $missed);
        }

        $body = trim($body);
        if ($body === '') {
            return $this->renderForManagerOffline($managerName, $eveningSnap, $missed);
        }

        return "<b>{$managerName}</b>\n\n".$body;
    }

    /**
     * The §5.1 payload (json indent=2 in the caller). Shape:
     *   { manager, date,
     *     closed_today_tasks: [{company, status_before, status_before_emoji,
     *       status_before_id, status_after, status_after_emoji, status_after_id,
     *       stage_changed, task_type, task_result, from_morning_plan,
     *       is_transitional, transitional_name, hint}],
     *     plan_pending_tasks: [{company, status_morning, status_morning_emoji,
     *       status_now, status_now_emoji, stage_changed, task_text, notes_today,
     *       carryover_days, days_in_stage}] }
     *
     * @param  array<int, true>  $dealIdsWithNotesToday
     * @return array<string, mixed>
     */
    public function buildPayload(
        string $managerName,
        ?DaySnapshot $morningPlan,
        DaySnapshot $eveningSnap,
        array $dealIdsWithNotesToday,
    ): array {
        // Total stage resolution even when called standalone (tests / future callers).
        $this->primeStageCache($morningPlan, $eveningSnap);

        $planRows = $morningPlan?->plan ?? [];
        $planTaskIds = $this->taskIdSet($planRows);

        $closed = [];
        foreach ($eveningSnap->plan as $row) {
            if (! $row->isCompleted) {
                continue;
            }

            $dealId = $row->dealId;
            $before = $this->morningStage($morningPlan, $dealId);
            $after = $this->eveningStage($eveningSnap, $dealId);

            $beforeMeta = StageMeta::forStage($before);
            $afterMeta = StageMeta::forStage($after);

            $beforeName = $this->morningStageName($morningPlan, $eveningSnap, $dealId, $row);
            $afterName = $row->dealStageName ?? '';

            $stageChanged = ($before?->id ?? null) !== ($after?->id ?? null);
            $isTransitional = $stageChanged && $this->classifier->isForwardMove($before, $after);

            $closed[] = [
                'company' => $row->dealTitle ?? '',
                'status_before' => $beforeName,
                'status_before_emoji' => $beforeMeta->emoji,
                'status_before_id' => $before?->id,
                'status_after' => $afterName,
                'status_after_emoji' => $afterMeta->emoji,
                'status_after_id' => $after?->id,
                'stage_changed' => $stageChanged,
                'task_type' => $row->typeName,
                'task_result' => $row->resultText ?? '',
                'from_morning_plan' => isset($planTaskIds[$row->taskId]),
                'is_transitional' => $isTransitional,
                'transitional_name' => $isTransitional ? $row->typeName : null,
                'hint' => $this->classifyHint($before, $after, $isTransitional, $row->typeName),
            ];
        }

        $pending = [];
        $eveningByTaskId = $this->indexByTaskId($eveningSnap->plan);
        foreach ($planRows as $row) {
            $eveningRow = $eveningByTaskId[$row->taskId] ?? null;
            if ($eveningRow !== null && $eveningRow->isCompleted) {
                continue; // done → not pending.
            }

            $dealId = $row->dealId;
            $morning = $this->morningStage($morningPlan, $dealId);
            $now = $this->eveningStage($eveningSnap, $dealId) ?? $morning;

            $morningMeta = StageMeta::forStage($morning);
            $nowMeta = StageMeta::forStage($now);

            $pending[] = [
                'company' => $row->dealTitle ?? '',
                'status_morning' => $morning?->name ?? ($row->dealStageName ?? ''),
                'status_morning_emoji' => $morningMeta->emoji,
                'status_now' => $now?->name ?? ($row->dealStageName ?? ''),
                'status_now_emoji' => $nowMeta->emoji,
                'stage_changed' => ($morning?->id ?? null) !== ($now?->id ?? null),
                'task_text' => $row->text,
                'notes_today' => $dealId !== null && isset($dealIdsWithNotesToday[$dealId]),
                'carryover_days' => $row->carryoverDays,
                'days_in_stage' => $row->daysInStage,
            ];
        }

        return [
            'manager' => $managerName,
            'date' => $eveningSnap->onDate,
            'closed_today_tasks' => $closed,
            'plan_pending_tasks' => $pending,
        ];
    }

    /**
     * _classify_hint (spec §5.1): a "; "-joined hint per closed task.
     *   FLAG: ушла в lost
     *   FLAG: откат в cold
     *   WIN: success (оплата/договор)
     *   WIN: переходная задача {name}
     *   WIN: 🚀 скачок через этап ({from} → {to})
     */
    public function classifyHint(?PipelineStage $before, ?PipelineStage $after, bool $isTransitional, string $taskName): string
    {
        $hints = [];

        // FLAGs first (lost/cold are always flags, even on a closed task).
        if ($this->classifier->isLost($after) && ! $this->classifier->isLost($before)) {
            $hints[] = 'FLAG: ушла в lost';
        } elseif ($this->classifier->isCold($after) && ! $this->classifier->isCold($before)) {
            $hints[] = 'FLAG: откат в cold';
        }

        // WINs.
        if ($this->classifier->isWon($after) && ! $this->classifier->isWon($before)) {
            $hints[] = 'WIN: success (оплата/договор)';
        }

        if ($isTransitional) {
            $hints[] = "WIN: переходная задача {$taskName}";
        }

        if ($this->classifier->isStageJump($before, $after) && $this->classifier->isForwardMove($before, $after)) {
            $fromName = $before?->name ?? '';
            $toName = $after?->name ?? '';
            $hints[] = "WIN: 🚀 скачок через этап ({$fromName} → {$toName})";
        }

        return implode('; ', $hints);
    }

    /**
     * renderForManagerOffline (spec §5.1): deterministic fallback over the evening
     * FACT tasks. Bucket lost/cold → flags, success/non-empty → achievements; lines
     * "{emoji} ({company}) {stage} — {msg(120)}"; "+ Пропущено {missed} задач без
     * заметок"; sort by status_sort_key; top-6 each; headers + "• " bullets; empty
     * → "—".
     */
    public function renderForManagerOffline(string $managerName, DaySnapshot $eveningSnap, int $missed): string
    {
        $this->primeStageCache(null, $eveningSnap);

        $achievements = [];
        $flags = [];

        foreach ($eveningSnap->fact as $row) {
            $stage = $this->eveningStage($eveningSnap, $row->dealId);
            $meta = StageMeta::forStage($stage);
            $stageName = $row->dealStageName ?? ($stage?->name ?? '');
            $company = $row->dealTitle ?? '';
            $msg = $this->truncate($row->resultText ?? $row->text, 120);

            $line = "{$meta->emoji} ({$company}) {$stageName} — {$msg}";
            $sortKey = $this->classifier->statusSortKey($stage);

            if ($this->classifier->isLost($stage) || $this->classifier->isCold($stage)) {
                $flags[] = ['key' => $sortKey, 'line' => $line];
            } elseif ($this->classifier->isWon($stage) || ($row->resultText ?? '') !== '') {
                $achievements[] = ['key' => $sortKey, 'line' => $line];
            }
        }

        if ($missed > 0) {
            $flags[] = ['key' => 99, 'line' => "+ Пропущено {$missed} задач без заметок"];
        }

        $achLines = $this->topLines($achievements, 6);
        $flagLines = $this->topLines($flags, 6);

        $body = "🏆 Ключевые достижения\n".$this->bulletBlock($achLines)
            ."\n\n🚩 Красные флаги\n".$this->bulletBlock($flagLines);

        return "<b>{$managerName}</b>\n\n".$body;
    }

    /**
     * @param  list<array{key: int, line: string}>  $items
     * @return list<string>
     */
    private function topLines(array $items, int $limit): array
    {
        usort($items, static fn (array $a, array $b): int => $a['key'] <=> $b['key']);

        return array_slice(array_map(static fn (array $i): string => $i['line'], $items), 0, $limit);
    }

    /**
     * @param  list<string>  $lines
     */
    private function bulletBlock(array $lines): string
    {
        if ($lines === []) {
            return '—';
        }

        return implode("\n", array_map(static fn (string $l): string => "• {$l}", $lines));
    }

    private function truncate(string $text, int $max): string
    {
        $text = trim($text);
        if ($text === '') {
            return '(без текста)';
        }

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max);
    }

    private function morningStage(?DaySnapshot $morningPlan, ?int $dealId): ?PipelineStage
    {
        if ($dealId === null) {
            return null;
        }

        $statusId = $morningPlan?->leadsById[$dealId]['status_id'] ?? null;

        return $this->stage($statusId !== null ? (int) $statusId : null);
    }

    private function eveningStage(DaySnapshot $eveningSnap, ?int $dealId): ?PipelineStage
    {
        if ($dealId === null) {
            return null;
        }

        $statusId = $eveningSnap->leadsById[$dealId]['status_id'] ?? null;

        return $this->stage($statusId !== null ? (int) $statusId : null);
    }

    /**
     * The morning stage NAME for a closed task: prefer the morning leads_by_id
     * stage's name, else fall back to the evening row's deal_stage_name (spec §2 —
     * names come from tasks[].deal_stage_name).
     */
    private function morningStageName(?DaySnapshot $morningPlan, DaySnapshot $eveningSnap, ?int $dealId, PulseTaskRow $row): string
    {
        $stage = $this->morningStage($morningPlan, $dealId);
        if ($stage !== null) {
            return $stage->name;
        }

        // Fall back to the morning plan row's cached name when present.
        if ($morningPlan !== null && $dealId !== null) {
            foreach ($morningPlan->plan as $planRow) {
                if ($planRow->dealId === $dealId && $planRow->dealStageName !== null) {
                    return $planRow->dealStageName;
                }
            }
        }

        return $row->dealStageName ?? '';
    }

    private function primeStageCache(?DaySnapshot $morningPlan, DaySnapshot $eveningSnap): void
    {
        $ids = [];
        foreach ([$morningPlan, $eveningSnap] as $snap) {
            if ($snap === null) {
                continue;
            }
            foreach ($snap->leadsById as $lead) {
                $sid = $lead['status_id'] ?? null;
                if ($sid !== null) {
                    $ids[(int) $sid] = true;
                }
            }
        }

        $missing = array_values(array_filter(
            array_keys($ids),
            fn (int $id): bool => ! array_key_exists($id, $this->stageCache),
        ));

        if ($missing === []) {
            return;
        }

        /** @var Collection<int, PipelineStage> $stages */
        $stages = PipelineStage::query()->whereIn('id', $missing)->get();
        $byId = $stages->keyBy('id');

        foreach ($missing as $id) {
            $this->stageCache[$id] = $byId->get($id);
        }
    }

    private function stage(?int $stageId): ?PipelineStage
    {
        if ($stageId === null) {
            return null;
        }

        return $this->stageCache[$stageId] ?? null;
    }

    /**
     * @param  list<PulseTaskRow>  $rows
     * @return array<int, PulseTaskRow>
     */
    private function indexByTaskId(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[$row->taskId] = $row;
        }

        return $map;
    }

    /**
     * @param  list<PulseTaskRow>  $rows
     * @return array<int, true>
     */
    private function taskIdSet(array $rows): array
    {
        $set = [];
        foreach ($rows as $row) {
            $set[$row->taskId] = true;
        }

        return $set;
    }
}
