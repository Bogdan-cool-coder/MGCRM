<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Services;

use App\Domain\Inbox\Models\InboundMessage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * InboundMessageService — the query-build home for the Inbox triage log.
 *
 * Moved out of InboundMessageController::index (backend-standard §1 layering,
 * contract §2 refactor debt): the controller must stay thin. This also holds
 * the snooze-aware unread-count helper (contract §4.5.1) so the sidebar badge
 * and the counts endpoint never drift from each other — one source of the
 * number.
 */
class InboundMessageService
{
    /**
     * Eager-load set for the triage resource: channel + the routed deal with its
     * stage. Batched (no N+1) and shared across index/show/read/unread/reroute.
     *
     * @return array<int|string, mixed>
     */
    public function triageRelations(): array
    {
        return ['channel', 'targetDeal.stage'];
    }

    /**
     * Build + paginate the triage list (`GET /api/inbox`).
     *
     * @param  array<string, mixed>  $filters  validated IndexInboundMessageRequest data
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $starred = $filters['starred'] ?? null;
        $important = $filters['important'] ?? null;
        $snoozed = $filters['snoozed'] ?? null;
        $failed = ($filters['routing_status'] ?? null) === 'failed';
        $hasDeal = $filters['has_deal'] ?? null;

        // «Чистые Входящие» — no explicit triage/status filter chosen at all.
        // Only then does the snooze-hiding default kick in (contract §4.4).
        $applySnoozeHiding = $starred === null
            && $important === null
            && $snoozed === null
            && ! $failed
            && $hasDeal !== true;

        $perPage = min((int) ($filters['per_page'] ?? 50), 100);

        return InboundMessage::query()
            ->with($this->triageRelations())
            ->when($filters['channel_id'] ?? null, fn (Builder $q, $id) => $q->where('channel_id', (int) $id))
            ->when($filters['routing_status'] ?? null, fn (Builder $q, $status) => $q->where('routing_status', $status))
            // q → substring across sender + subject + body (the triage search box).
            ->when(filled($filters['q'] ?? null), function (Builder $q) use ($filters): void {
                $term = trim((string) $filters['q']);
                $q->where(function (Builder $sub) use ($term): void {
                    $sub->whereLikeCi('from_identifier', $term)
                        ->orWhereLikeCi('from_name', $term)
                        ->orWhereLikeCi('subject', $term)
                        ->orWhereLikeCi('body', $term);
                });
            })
            // has_deal → routed-to-a-deal vs not (true = has a target_deal_id).
            ->when($hasDeal !== null, fn (Builder $q) => $hasDeal
                ? $q->whereNotNull('target_deal_id')
                : $q->whereNull('target_deal_id'))
            // unread → read_at IS NULL (true) / IS NOT NULL (false).
            ->when(array_key_exists('unread', $filters) && $filters['unread'] !== null, fn (Builder $q) => $filters['unread']
                ? $q->whereNull('read_at')
                : $q->whereNotNull('read_at'))
            // starred → starred_at IS NOT NULL (true) / IS NULL (false).
            ->when($starred !== null, fn (Builder $q) => $starred
                ? $q->whereNotNull('starred_at')
                : $q->whereNull('starred_at'))
            // important → important = true / false.
            ->when($important !== null, fn (Builder $q) => $q->where('important', $important))
            // snoozed → активно-отложенные (папка «Отложенные»); snoozed=0 → none active.
            ->when($snoozed !== null, fn (Builder $q) => $snoozed
                ? $q->where('snoozed_until', '>', now())
                : $q->where(fn (Builder $sub) => $sub->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now())))
            // «Чистые Входящие» hide actively-snoozed messages by default (contract §4.3/§4.4).
            ->when($applySnoozeHiding, fn (Builder $q) => $q->where(
                fn (Builder $sub) => $sub->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now())
            ))
            // date_from / date_to → received_at range, day bounds in the operational
            // timezone (Дубай-окно) so the calendar day matches the rest of the app.
            ->when($filters['date_from'] ?? null, fn (Builder $q, $date) => $q->where('received_at', '>=', $this->operationalDayStart((string) $date)))
            ->when($filters['date_to'] ?? null, fn (Builder $q, $date) => $q->where('received_at', '<=', $this->operationalDayEnd((string) $date)))
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            // Clamp per_page so a hostile/absurd value can't pull the whole table
            // into one page (#15). Mirrors DocumentController's min(..., 100) cap.
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Snooze-aware unread count — the single source shared by the sidebar
     * `GET /inbox/unread-count` badge and `counts.folders.inbox_unread`
     * (contract §4.5.1): unread AND not actively snoozed.
     */
    public function inboxUnreadCount(): int
    {
        return InboundMessage::query()
            ->whereNull('read_at')
            ->where(fn (Builder $q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()))
            ->count();
    }

    /**
     * Toggle helpers — idempotent, mirroring the existing read()/unread() style
     * in the controller (contract §4.1/§4.2/§4.3).
     */
    public function star(InboundMessage $message): InboundMessage
    {
        if ($message->starred_at === null) {
            $message->forceFill(['starred_at' => now()])->save();
        }

        return $message;
    }

    public function unstar(InboundMessage $message): InboundMessage
    {
        if ($message->starred_at !== null) {
            $message->forceFill(['starred_at' => null])->save();
        }

        return $message;
    }

    public function markImportant(InboundMessage $message): InboundMessage
    {
        if (! $message->important) {
            $message->forceFill(['important' => true])->save();
        }

        return $message;
    }

    public function unmarkImportant(InboundMessage $message): InboundMessage
    {
        if ($message->important) {
            $message->forceFill(['important' => false])->save();
        }

        return $message;
    }

    public function snooze(InboundMessage $message, Carbon $until): InboundMessage
    {
        $message->forceFill(['snoozed_until' => $until])->save();

        return $message;
    }

    public function unsnooze(InboundMessage $message): InboundMessage
    {
        if ($message->snoozed_until !== null) {
            $message->forceFill(['snoozed_until' => null])->save();
        }

        return $message;
    }

    /**
     * Two-query aggregate for `GET /api/inbox/counts` (contract §4.5): folder
     * counts via one conditional-aggregate query, per-channel unread via a
     * second GROUP BY query. Never N separate count() calls.
     *
     * @return array{folders: array<string, int>, channels: array<string, int>}
     */
    public function counts(int $draftsUserId): array
    {
        $folders = $this->folderCounts();
        $folders['drafts'] = DB::table('inbox_drafts')->where('user_id', $draftsUserId)->count();

        return [
            'folders' => $folders,
            'channels' => $this->channelUnreadCounts(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function folderCounts(): array
    {
        $row = InboundMessage::query()->selectRaw(
            <<<'SQL'
                COUNT(*) FILTER (WHERE read_at IS NULL AND (snoozed_until IS NULL OR snoozed_until <= CURRENT_TIMESTAMP)) AS inbox_unread,
                COUNT(*) FILTER (WHERE starred_at IS NOT NULL) AS starred,
                COUNT(*) FILTER (WHERE important) AS important,
                COUNT(*) FILTER (WHERE routing_status = 'failed') AS failed,
                COUNT(*) FILTER (WHERE target_deal_id IS NOT NULL) AS in_deals,
                COUNT(*) FILTER (WHERE snoozed_until > CURRENT_TIMESTAMP) AS snoozed
                SQL
        )->first();

        return [
            'inbox_unread' => (int) $row->inbox_unread,
            'starred' => (int) $row->starred,
            'important' => (int) $row->important,
            'failed' => (int) $row->failed,
            'in_deals' => (int) $row->in_deals,
            'snoozed' => (int) $row->snoozed,
        ];
    }

    /**
     * Per-channel-kind unread counts (chip badges, contract §4.5). Group by
     * channel_id then fold into kind → count in PHP (channels table is tiny —
     * this avoids a raw SQL JOIN just to resolve the enum).
     *
     * @return array<string, int>
     */
    private function channelUnreadCounts(): array
    {
        $rows = InboundMessage::query()
            ->whereNull('read_at')
            ->selectRaw('channel_id, COUNT(*) AS unread_count')
            ->groupBy('channel_id')
            ->get();

        $channelKinds = DB::table('channels')
            ->whereIn('id', $rows->pluck('channel_id'))
            ->pluck('kind', 'id');

        $result = [];
        foreach ($rows as $row) {
            $kind = $channelKinds->get($row->channel_id);

            if ($kind === null) {
                continue;
            }

            $result[$kind] = ($result[$kind] ?? 0) + (int) $row->unread_count;
        }

        return $result;
    }

    /**
     * Start-of-day for a YYYY-MM-DD filter bound, in the operational timezone,
     * returned as a UTC instant to compare against the UTC-stored received_at.
     */
    private function operationalDayStart(string $date): Carbon
    {
        return Carbon::parse($date, $this->operationalTimezone())->startOfDay()->utc();
    }

    /** End-of-day (inclusive) for a YYYY-MM-DD filter bound, operational tz → UTC. */
    private function operationalDayEnd(string $date): Carbon
    {
        return Carbon::parse($date, $this->operationalTimezone())->endOfDay()->utc();
    }

    /**
     * The operational timezone (Дубай-окно) — the single source already used by
     * the SalesPulse / Activity day-window math, so the Inbox date filter never
     * drifts from the rest of the app.
     */
    private function operationalTimezone(): string
    {
        /** @var string $tz */
        $tz = config('salespulse.timezone', 'Asia/Dubai');

        return $tz;
    }
}
