<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Services;

use App\Domain\SalesPulse\Data\DaySnapshot;
use App\Domain\SalesPulse\Enums\SnapKind;
use App\Domain\SalesPulse\Enums\SnapSource;
use App\Domain\SalesPulse\Models\PulseDailyStatus;
use App\Domain\SalesPulse\Models\PulseSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SnapshotRepository — persistence for pulse snapshots + the per-day status row
 * (spec §1.1 / §2). Two write paths, exactly as the AMO bot:
 *
 *   - savePlan(): WRITE-ONCE. If a PLAN already exists for (manager, on_date) the
 *     morning plan is immutable — we log a warning and DO NOT overwrite, returning
 *     the existing row. Stamps pulse_daily_status.plan_at / plan_source.
 *   - saveFact(): UPSERT. Re-collected evening fact overwrites the prior FACT for
 *     the manager-day. Stamps pulse_daily_status.fact_at / fact_source.
 *
 * The serialized payload is DaySnapshot::toArray(). All writes run inside a
 * transaction so the snapshot row and its daily-status stamp commit together.
 */
class SnapshotRepository
{
    /**
     * Persist the morning PLAN (write-once). Returns the stored snapshot — the
     * existing one when a PLAN was already fixed for the day (no overwrite).
     */
    public function savePlan(DaySnapshot $snapshot, SnapSource $source, ?CarbonImmutable $capturedAt = null): PulseSnapshot
    {
        $onDate = $snapshot->onDate;
        $capturedAt ??= CarbonImmutable::now();

        $existing = PulseSnapshot::query()
            ->where('manager_id', $snapshot->managerId)
            ->whereDate('on_date', $onDate)
            ->where('kind', SnapKind::Plan->value)
            ->first();

        if ($existing !== null) {
            Log::warning('SalesPulse: PLAN snapshot already exists, refusing overwrite (write-once)', [
                'manager_id' => $snapshot->managerId,
                'on_date' => $onDate,
            ]);

            return $existing;
        }

        try {
            return DB::transaction(function () use ($snapshot, $source, $onDate, $capturedAt): PulseSnapshot {
                $row = PulseSnapshot::create([
                    'manager_id' => $snapshot->managerId,
                    'on_date' => $this->normalizeDate($onDate),
                    'kind' => SnapKind::Plan->value,
                    'source' => $source->value,
                    'captured_at' => $capturedAt,
                    'data' => $snapshot->toArray(),
                ]);

                $this->stampDailyStatus($snapshot->managerId, $onDate, SnapKind::Plan, $source, $capturedAt);

                return $row;
            });
        } catch (QueryException $e) {
            // Race: another process (e.g. a manual /startday colliding with the
            // 10:15 AutoCapturePlanJob) inserted the PLAN between the pre-check and
            // this insert. The unique constraint (uq_pulse_snapshots_manager_date_kind)
            // makes that the authoritative write — honour write-once and return it.
            $row = $this->findSnapshot($snapshot->managerId, $onDate, SnapKind::Plan);
            if ($row === null) {
                throw $e; // not a uniqueness collision — surface the real error.
            }

            Log::warning('SalesPulse: PLAN snapshot lost a write race, returning the committed row (write-once)', [
                'manager_id' => $snapshot->managerId,
                'on_date' => $onDate,
            ]);

            return $row;
        }
    }

    /**
     * Persist the evening FACT (upsert). Overwrites any prior FACT for the
     * manager-day; stamps pulse_daily_status.fact_at / fact_source.
     */
    public function saveFact(DaySnapshot $snapshot, SnapSource $source, ?CarbonImmutable $capturedAt = null): PulseSnapshot
    {
        $onDate = $snapshot->onDate;
        $capturedAt ??= CarbonImmutable::now();

        $attributes = [
            'manager_id' => $snapshot->managerId,
            'on_date' => $this->normalizeDate($onDate),
            'kind' => SnapKind::Fact->value,
            'source' => $source->value,
            'captured_at' => $capturedAt,
            'data' => $snapshot->toArray(),
        ];

        $persist = function () use ($snapshot, $source, $onDate, $capturedAt, $attributes): PulseSnapshot {
            $existing = $this->findSnapshot($snapshot->managerId, $onDate, SnapKind::Fact);

            if ($existing !== null) {
                $existing->fill($attributes)->save();
                $row = $existing;
            } else {
                $row = PulseSnapshot::create($attributes);
            }

            $this->stampDailyStatus($snapshot->managerId, $onDate, SnapKind::Fact, $source, $capturedAt);

            return $row;
        };

        try {
            return DB::transaction($persist);
        } catch (QueryException $e) {
            // Race: a concurrent saveFact created the row between our existence
            // check and the INSERT. The unique constraint guarantees only one row,
            // so re-run as an update of the now-committed row instead of throwing.
            if ($this->findSnapshot($snapshot->managerId, $onDate, SnapKind::Fact) === null) {
                throw $e; // not a uniqueness collision — surface the real error.
            }

            return DB::transaction($persist);
        }
    }

    /**
     * Locate the stored snapshot row for a manager-day-kind, or null.
     */
    private function findSnapshot(int $managerId, string $onDate, SnapKind $kind): ?PulseSnapshot
    {
        /** @var PulseSnapshot|null $row */
        $row = PulseSnapshot::query()
            ->where('manager_id', $managerId)
            ->whereDate('on_date', $onDate)
            ->where('kind', $kind->value)
            ->first();

        return $row;
    }

    /**
     * Load a stored snapshot of a given kind for a manager-day, hydrated into a
     * DaySnapshot (or null if absent).
     */
    public function load(int $managerId, string $onDate, SnapKind $kind): ?DaySnapshot
    {
        $row = PulseSnapshot::query()
            ->where('manager_id', $managerId)
            ->whereDate('on_date', $onDate)
            ->where('kind', $kind->value)
            ->first();

        if ($row === null) {
            return null;
        }

        /** @var array<string, mixed> $data */
        $data = $row->data ?? [];

        return DaySnapshot::fromArray($data);
    }

    /**
     * Stamp the plan_at/fact_at + source slot on the per-day status row
     * (one row per manager-day). The opposite slot is left untouched.
     */
    private function stampDailyStatus(int $managerId, string $onDate, SnapKind $kind, SnapSource $source, CarbonImmutable $capturedAt): void
    {
        $status = PulseDailyStatus::query()
            ->where('manager_id', $managerId)
            ->whereDate('on_date', $onDate)
            ->first();

        if ($status === null) {
            $status = new PulseDailyStatus([
                'manager_id' => $managerId,
                'on_date' => $this->normalizeDate($onDate),
            ]);
        }

        if ($kind === SnapKind::Plan) {
            $status->plan_at = $capturedAt;
            $status->plan_source = $source;
        } else {
            $status->fact_at = $capturedAt;
            $status->fact_source = $source;
        }

        $status->save();
    }

    /**
     * Normalise an on_date to the Y-m-d the `date` cast stores (midnight in the
     * model's date column). Accepts the ISO yyyy-mm-dd a DaySnapshot carries.
     */
    private function normalizeDate(string $onDate): string
    {
        return CarbonImmutable::parse($onDate)->toDateString();
    }
}
