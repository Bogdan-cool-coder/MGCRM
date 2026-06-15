<?php

declare(strict_types=1);

namespace App\Domain\Automation\Services;

use App\Domain\Automation\Enums\TriggerKind;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Sales\Models\Deal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AutomationScanner (M7 P2) — the cron side of the engine.
 *
 * The two time-based triggers (idle_in_stage_days, date_field_approaching) cannot
 * fire from a domain event — nothing "happens" when a deal merely sits still or a
 * date creeps closer. A scheduled scanner walks the active automations of each
 * kind, finds the matching deals, and queues their action.
 *
 * Idempotency is the whole game here, because the scan runs every tick:
 *   - each match claims a `pending` AutomationRun keyed on a DETERMINISTIC
 *     trigger_event_ts (the stage-entry timestamp for idle; the target date value
 *     for date_field) — NOT "now". So the next scan re-derives the same key, hits
 *     the partial-unique index, and is silently deduped. A deal fires once per
 *     (automation, stage-entry) / (automation, date value), never once per scan.
 *   - the action itself runs in ExecuteAutomationActionJob (claimAndQueue), so a
 *     slow Telegram/webhook send never stalls the scan loop.
 *
 * Fault isolation: every automation and every deal is wrapped in try/catch +
 * continue. One broken rule (or one un-resolvable deal) logs a warning and the
 * scan keeps going — a single bad automation must never take the scheduler down.
 *
 * The whitelist of scannable date fields lives here (DATE_FIELDS); only deal date
 * columns the engine is allowed to watch are listed — never owner/amount/etc.
 */
class AutomationScanner
{
    /**
     * Deal date fields a date_field_approaching automation may watch.
     *
     * @var list<string>
     */
    public const DATE_FIELDS = [
        'expected_close_date',
        'expected_sign_date',
        'expected_payment_date',
        'closed_at',
    ];

    public function __construct(
        private readonly ActionDispatcher $dispatcher,
    ) {}

    /**
     * Scan for deals idle in their stage for >= {days} and queue the action.
     *
     * @return int number of slots claimed (deals fired this scan; deduped ones
     *             are not counted)
     */
    public function scanIdleInStage(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $claimed = 0;

        $automations = PipelineAutomation::query()
            ->where('is_active', true)
            ->where('trigger_kind', TriggerKind::IdleInStageDays->value)
            ->orderBy('id')
            ->get();

        foreach ($automations as $automation) {
            try {
                $days = $this->positiveInt($automation->trigger_config['days'] ?? null);

                if ($days === null) {
                    continue; // misconfigured rule — skip, don't blow up the scan
                }

                $threshold = $now->subDays($days);

                $claimed += $this->fireIdleAutomation($automation, $threshold);
            } catch (Throwable $e) {
                Log::warning('AutomationScanner: idle automation scan failed', [
                    'automation_id' => $automation->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $claimed;
    }

    /**
     * Scan for deals whose whitelisted date field falls within [now, now+{days}]
     * and queue the action.
     *
     * @return int number of slots claimed
     */
    public function scanDateFieldApproaching(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $claimed = 0;

        $automations = PipelineAutomation::query()
            ->where('is_active', true)
            ->where('trigger_kind', TriggerKind::DateFieldApproaching->value)
            ->orderBy('id')
            ->get();

        foreach ($automations as $automation) {
            try {
                $field = (string) ($automation->trigger_config['field'] ?? '');
                $days = $this->positiveInt($automation->trigger_config['days'] ?? null);

                if ($days === null || ! in_array($field, self::DATE_FIELDS, strict: true)) {
                    continue; // misconfigured / non-whitelisted field — skip
                }

                $windowEnd = $now->addDays($days);

                $claimed += $this->fireDateFieldAutomation($automation, $field, $now, $windowEnd);
            } catch (Throwable $e) {
                Log::warning('AutomationScanner: date-field automation scan failed', [
                    'automation_id' => $automation->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $claimed;
    }

    /**
     * Claim+queue every deal in the automation's scope that entered its stage on
     * or before $threshold.
     */
    private function fireIdleAutomation(PipelineAutomation $automation, CarbonImmutable $threshold): int
    {
        $claimed = 0;

        $query = Deal::query()
            ->where('pipeline_id', $automation->pipeline_id)
            ->whereNotNull('stage_changed_at')
            ->where('stage_changed_at', '<=', $threshold)
            ->whereNull('archived_at')
            ->orderBy('id');

        if ($automation->stage_id !== null) {
            $query->where('stage_id', $automation->stage_id);
        }

        foreach ($query->cursor() as $deal) {
            try {
                // trigger_event_ts = the stage-entry instant: deterministic, so a
                // re-scan of a still-idle deal re-derives it and is deduped. A
                // genuine re-entry into the stage moves stage_changed_at forward
                // and re-fires.
                $eventTs = CarbonImmutable::parse($deal->stage_changed_at);

                if ($this->dispatcher->claimAndQueue($automation, $deal, $eventTs) !== null) {
                    $claimed++;
                }
            } catch (Throwable $e) {
                Log::warning('AutomationScanner: failed to enqueue idle deal', [
                    'automation_id' => $automation->id,
                    'deal_id' => $deal->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $claimed;
    }

    /**
     * Claim+queue every deal whose $field value falls inside [$now, $windowEnd].
     */
    private function fireDateFieldAutomation(
        PipelineAutomation $automation,
        string $field,
        CarbonImmutable $now,
        CarbonImmutable $windowEnd,
    ): int {
        $claimed = 0;

        $query = Deal::query()
            ->where('pipeline_id', $automation->pipeline_id)
            ->whereNotNull($field)
            ->where($field, '>=', $now)
            ->where($field, '<=', $windowEnd)
            ->whereNull('archived_at')
            ->orderBy('id');

        if ($automation->stage_id !== null) {
            $query->where('stage_id', $automation->stage_id);
        }

        foreach ($query->cursor() as $deal) {
            try {
                // trigger_event_ts = the target date value itself: one fire per
                // (deal, that date). If the date is edited the new value re-fires;
                // the unchanged value stays deduped across scans.
                $eventTs = CarbonImmutable::parse($deal->{$field});

                if ($this->dispatcher->claimAndQueue($automation, $deal, $eventTs) !== null) {
                    $claimed++;
                }
            } catch (Throwable $e) {
                Log::warning('AutomationScanner: failed to enqueue date-field deal', [
                    'automation_id' => $automation->id,
                    'deal_id' => $deal->id,
                    'field' => $field,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $claimed;
    }

    /**
     * Coerce a trigger_config value to a positive int, or null if it isn't one.
     */
    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
