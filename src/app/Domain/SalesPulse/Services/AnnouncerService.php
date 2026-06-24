<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Services;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Enums\ActivityTargetType;
use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealStageHistory;
use App\Domain\Sales\Models\PipelineStage;
use App\Domain\SalesPulse\Data\StageMeta;
use App\Domain\SalesPulse\Data\Team;
use App\Domain\SalesPulse\Enums\AnnouncedEventType;
use App\Domain\SalesPulse\Enums\SnapKind;
use App\Domain\SalesPulse\Models\PulseAnnouncedEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * AnnouncerService — port of the AMO bot's announcer (spec §4).
 *
 * Every 5 minutes (and on /announce_now) it scans a team for FRESH events — those
 * within a 15-minute freshness window — and posts a one-line celebration to the
 * team chat, deduplicated so a row is announced exactly once.
 *
 * Two MGCRM-native sources (spec §4):
 *   - MeetingDone: a completed first-time meeting Activity (kind=meeting,
 *     status=done, is_first_time_meeting=true) whose completed_at is in the window,
 *     whose responsible is a team manager and whose deal is in a team pipeline.
 *     Deduped by activity_id.
 *   - Success: a DealStageHistory row whose to_stage is an is_won stage, created_at
 *     in the window, user_id (or deal owner) a team manager, deal in a team
 *     pipeline. Deduped by deal_stage_history_id (Success has no activity).
 *
 * Outbound goes through SalesPulseNotifier (no polling — runs in the scheduler /
 * queue container, never 409). A manager on a skip day is skipped.
 *
 * The 15-minute window is behavioural (spec §4 — MAX_AGE_SECONDS=900); we keep it
 * to avoid re-announcing stale events, while the dedup ledger guards against
 * double-posting WITHIN the window across cron ticks.
 */
class AnnouncerService
{
    private const MAX_AGE_SECONDS = 900;

    public function __construct(
        private readonly SalesPulseNotifier $notifier,
        private readonly SnapshotRepository $snapshots,
        private readonly SkipService $skips,
    ) {}

    /**
     * Scan every configured team and announce fresh events. Returns the number of
     * announcements posted (for logging / the manual trigger ack).
     */
    public function runAll(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $posted = 0;

        foreach ($this->teams() as $team) {
            $posted += $this->run($team, $now);
        }

        return $posted;
    }

    /**
     * Scan one team and announce its fresh events. Returns the count posted.
     */
    public function run(Team $team, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $cutoff = $now->subSeconds(self::MAX_AGE_SECONDS);

        $managerIds = $team->managerUserIds();
        if ($managerIds === []) {
            return 0;
        }

        $posted = 0;
        $posted += $this->announceMeetings($team, $managerIds, $cutoff, $now);
        $posted += $this->announceSuccesses($team, $managerIds, $cutoff, $now);

        return $posted;
    }

    /**
     * MeetingDone: completed FTM meetings in the window for the team's managers /
     * pipelines, not yet announced (spec §4).
     *
     * @param  list<int>  $managerIds
     */
    private function announceMeetings(Team $team, array $managerIds, CarbonImmutable $cutoff, CarbonImmutable $now): int
    {
        /** @var Collection<int, Activity> $meetings */
        $meetings = Activity::query()
            ->where('kind', ActivityType::Meeting->value)
            ->where('status', ActivityStatus::Done->value)
            ->where('is_first_time_meeting', true)
            ->where('target_type', ActivityTargetType::Deal->value)
            ->whereNotNull('target_id')
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$cutoff, $now])
            ->whereIn('responsible_id', $managerIds)
            ->get();

        if ($meetings->isEmpty()) {
            return 0;
        }

        // Already-announced activity ids in this batch → skip (dedup pre-check).
        $announced = PulseAnnouncedEvent::query()
            ->whereIn('activity_id', $meetings->pluck('id'))
            ->pluck('activity_id')
            ->map(static fn ($id): int => (int) $id)
            ->flip();

        $posted = 0;

        foreach ($meetings as $meeting) {
            if ($announced->has((int) $meeting->id)) {
                continue;
            }

            $deal = $this->dealInPipelines((int) $meeting->target_id, $team->pipelineIds);
            if ($deal === null) {
                continue; // deal not in a team funnel → drop (spec §4).
            }

            $managerId = (int) $meeting->responsible_id;
            if ($this->managerSkipped($managerId, $team, $now)) {
                continue;
            }

            $message = $this->buildMessage(
                eventType: AnnouncedEventType::MeetingDone,
                managerName: $this->managerName($managerId, $team),
                deal: $deal,
                bodyText: $this->bodyText($meeting->result_text, $meeting->title),
            );

            if ($this->record(
                eventType: AnnouncedEventType::MeetingDone,
                activityId: (int) $meeting->id,
                dealStageHistoryId: null,
                managerId: $managerId,
                dealId: (int) $deal->id,
                team: $team,
                message: $message,
                now: $now,
            )) {
                $posted++;
            }
        }

        return $posted;
    }

    /**
     * Success: deal won (a DealStageHistory transition into an is_won stage) in the
     * window for the team's managers / pipelines, not yet announced (spec §4).
     *
     * @param  list<int>  $managerIds
     */
    private function announceSuccesses(Team $team, array $managerIds, CarbonImmutable $cutoff, CarbonImmutable $now): int
    {
        $wonStageIds = PipelineStage::query()
            ->whereIn('pipeline_id', $team->pipelineIds)
            ->where('is_won', true)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($wonStageIds === []) {
            return 0;
        }

        /** @var Collection<int, DealStageHistory> $transitions */
        $transitions = DealStageHistory::query()
            ->with('deal')
            ->whereIn('to_stage_id', $wonStageIds)
            ->whereBetween('created_at', [$cutoff, $now])
            ->get();

        if ($transitions->isEmpty()) {
            return 0;
        }

        $announced = PulseAnnouncedEvent::query()
            ->whereIn('deal_stage_history_id', $transitions->pluck('id'))
            ->pluck('deal_stage_history_id')
            ->map(static fn ($id): int => (int) $id)
            ->flip();

        $managerIdSet = array_flip($managerIds);
        $posted = 0;

        foreach ($transitions as $transition) {
            if ($announced->has((int) $transition->id)) {
                continue;
            }

            $deal = $transition->deal;
            if ($deal === null || ! in_array((int) $deal->pipeline_id, $team->pipelineIds, true)) {
                continue; // deal not in a team funnel → drop.
            }

            // Attribute to the transition's user, falling back to the deal owner.
            $managerId = $transition->user_id !== null
                ? (int) $transition->user_id
                : (int) $deal->owner_user_id;

            if (! isset($managerIdSet[$managerId])) {
                continue; // mover is not a team manager → drop (spec §4).
            }

            if ($this->managerSkipped($managerId, $team, $now)) {
                continue;
            }

            $message = $this->buildMessage(
                eventType: AnnouncedEventType::Success,
                managerName: $this->managerName($managerId, $team),
                deal: $deal,
                bodyText: $this->bodyText(null, null),
            );

            if ($this->record(
                eventType: AnnouncedEventType::Success,
                activityId: null,
                dealStageHistoryId: (int) $transition->id,
                managerId: $managerId,
                dealId: (int) $deal->id,
                team: $team,
                message: $message,
                now: $now,
            )) {
                $posted++;
            }
        }

        return $posted;
    }

    /**
     * Build the §4 message (HTML, no preview):
     *
     *   {title}
     *   {company} · {stage}
     *
     *   {body}
     *
     * title (meeting_done) → "🤝 <b>{name} провёл встречу</b>"
     * title (success)      → "🎉 <b>{name} закрыл сделку</b>"
     * stage = "{утренний_лейбл} → {текущий_лейбл}" when the morning status is known
     *   and differs, else "{текущий_лейбл}" (label = "{эмодзи} {имя}").
     */
    private function buildMessage(AnnouncedEventType $eventType, string $managerName, Deal $deal, string $bodyText): string
    {
        $title = $eventType === AnnouncedEventType::MeetingDone
            ? "🤝 <b>{$managerName} провёл встречу</b>"
            : "🎉 <b>{$managerName} закрыл сделку</b>";

        $company = $deal->title ?? '';
        $stageLine = $this->stageLine($deal);

        return "{$title}\n{$company} · {$stageLine}\n\n{$bodyText}";
    }

    /**
     * The stage segment: "{morning_label} → {current_label}" when the morning
     * status (from the manager's PLAN snapshot) is known and differs from the
     * current stage, otherwise just the current label.
     */
    private function stageLine(Deal $deal): string
    {
        $currentStage = $deal->stage_id !== null
            ? PipelineStage::query()->find($deal->stage_id)
            : null;
        $currentLabel = $this->stageLabel($currentStage);

        $morningStageId = $this->morningStageId($deal);
        if ($morningStageId === null || $morningStageId === (int) $deal->stage_id) {
            return $currentLabel;
        }

        $morningStage = PipelineStage::query()->find($morningStageId);
        $morningLabel = $this->stageLabel($morningStage);

        return "{$morningLabel} → {$currentLabel}";
    }

    /**
     * The deal's stage id as recorded in the owning manager's morning PLAN snapshot
     * for today (spec §4 — "утренний статус"), or null when no plan / not tracked.
     */
    private function morningStageId(Deal $deal): ?int
    {
        $ownerId = $deal->owner_user_id !== null ? (int) $deal->owner_user_id : null;
        if ($ownerId === null) {
            return null;
        }

        $today = CarbonImmutable::now($this->timezone())->toDateString();
        $plan = $this->snapshots->load($ownerId, $today, SnapKind::Plan);
        if ($plan === null) {
            return null;
        }

        $status = $plan->leadsById[(int) $deal->id]['status_id'] ?? null;

        return $status !== null ? (int) $status : null;
    }

    private function stageLabel(?PipelineStage $stage): string
    {
        $meta = StageMeta::forStage($stage);

        return $meta->label($stage?->name ?? '');
    }

    /**
     * body = result_text or text or "(без текста)" (spec §4).
     */
    private function bodyText(?string $resultText, ?string $text): string
    {
        $resultText = $resultText !== null ? trim($resultText) : '';
        if ($resultText !== '') {
            return $resultText;
        }

        $text = $text !== null ? trim($text) : '';
        if ($text !== '') {
            return $text;
        }

        return '(без текста)';
    }

    /**
     * Post + record. The unique key (activity_id / deal_stage_history_id) is the
     * authoritative dedup boundary: insert FIRST, post only on a fresh insert, so a
     * race between two ticks never double-posts. Returns true when posted.
     */
    private function record(
        AnnouncedEventType $eventType,
        ?int $activityId,
        ?int $dealStageHistoryId,
        int $managerId,
        int $dealId,
        Team $team,
        string $message,
        CarbonImmutable $now,
    ): bool {
        try {
            $event = PulseAnnouncedEvent::create([
                'activity_id' => $activityId,
                'deal_stage_history_id' => $dealStageHistoryId,
                'event_type' => $eventType,
                'manager_id' => $managerId,
                'deal_id' => $dealId,
                'chat_id' => $team->chatId,
                'posted_at' => $now,
            ]);
        } catch (QueryException $e) {
            // Unique violation — another tick already claimed this source. Normal
            // under a race; do not post again (spec §4 dedup).
            Log::info('SalesPulse announcer: event already claimed, skipping', [
                'event_type' => $eventType->value,
                'activity_id' => $activityId,
                'deal_stage_history_id' => $dealStageHistoryId,
            ]);

            return false;
        }

        try {
            $this->notifier->sendToChat($team->chatId, $message);
        } catch (\Throwable $e) {
            // The ledger row is committed but the post failed (Telegram 5xx/429/
            // network). Drop the row so a later tick re-detects + re-announces the
            // event instead of treating it as already posted and silently losing it.
            $event->delete();

            Log::warning('SalesPulse announcer: send failed, rolling back ledger row for retry', [
                'event_type' => $eventType->value,
                'activity_id' => $activityId,
                'deal_stage_history_id' => $dealStageHistoryId,
                'chat_id' => $team->chatId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    private function managerSkipped(int $managerId, Team $team, CarbonImmutable $now): bool
    {
        $manager = User::query()->find($managerId);
        if ($manager === null) {
            return true; // unknown user → do not announce.
        }

        return $this->skips->isManagerSkipped($now, $manager, $team->chatId);
    }

    private function managerName(int $managerId, Team $team): string
    {
        foreach ($team->managers as $entry) {
            if ($entry->userId === $managerId) {
                return $entry->name;
            }
        }

        $user = User::query()->find($managerId);

        return $user !== null ? (string) $user->full_name : '';
    }

    /**
     * @param  list<int>  $pipelineIds
     */
    private function dealInPipelines(int $dealId, array $pipelineIds): ?Deal
    {
        return Deal::query()
            ->whereKey($dealId)
            ->whereIn('pipeline_id', $pipelineIds)
            ->first();
    }

    /**
     * @return list<Team>
     */
    private function teams(): array
    {
        /** @var array<int, array<string, mixed>> $raw */
        $raw = (array) config('salespulse.teams', []);

        return array_values(array_map(
            static fn (array $row): Team => Team::fromArray($row),
            array_filter($raw, 'is_array'),
        ));
    }

    private function timezone(): string
    {
        return (string) config('salespulse.timezone', 'Asia/Dubai');
    }
}
