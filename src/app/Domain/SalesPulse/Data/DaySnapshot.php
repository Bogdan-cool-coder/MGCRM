<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Data;

/**
 * DaySnapshot — the in-memory result of one `collect_day` run (port of the AMO
 * bot's collect_day output, spec §1.1). It carries the manager, the on-date
 * (ISO yyyy-mm-dd), the PLAN/FACT task buckets and the `leads_by_id` map that the
 * metrics layer rebuilds the day from.
 *
 *   - plan  = every task that was "in work today" (all in_work_today rows).
 *   - fact  = the subset of plan that was closed today (completed in the day window).
 *   - leadsById = { deal_id => {name, status_id, responsible_user_id, updated_by} }
 *
 * CRITICAL (spec §2): leads_by_id NEVER stores status_name — the stage label is
 * recovered from tasks[].dealStageName by every consumer. Replicate exactly,
 * otherwise the metrics break.
 *
 * The JSON shape persisted into pulse_snapshots.data mirrors the AMO snapshot:
 *   { manager_id, manager_name, on_date, tasks: [...], leads_by_id: {...} }
 * where `tasks` is the PLAN bucket on a morning snapshot and the full re-collected
 * task list on an evening FACT snapshot (plan == every in_work_today row).
 *
 * Manual DTO (no spatie/laravel-data — ARCHITECTURE.md §7 / blacklist).
 */
final class DaySnapshot
{
    /**
     * @param  list<PulseTaskRow>  $plan  Every in_work_today row (the day's tasks).
     * @param  list<PulseTaskRow>  $fact  Subset of $plan closed today.
     * @param  array<int, array{name: string|null, status_id: int|null, responsible_user_id: int|null, updated_by: int|null}>  $leadsById
     */
    public function __construct(
        public int $managerId,
        public string $managerName,
        public string $onDate,
        public array $plan,
        public array $fact,
        public array $leadsById,
    ) {}

    /**
     * Serialised form for pulse_snapshots.data. The `tasks` list is the PLAN
     * bucket (= every in_work_today row), exactly as the AMO snapshot stored it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $leadsById = [];
        foreach ($this->leadsById as $dealId => $lead) {
            // JSON object keys are strings (spec §2 — leads_by_id keyed by "<deal_id>").
            $leadsById[(string) $dealId] = [
                'name' => $lead['name'] ?? null,
                'status_id' => $lead['status_id'] ?? null,
                'responsible_user_id' => $lead['responsible_user_id'] ?? null,
                'updated_by' => $lead['updated_by'] ?? null,
            ];
        }

        return [
            'manager_id' => $this->managerId,
            'manager_name' => $this->managerName,
            'on_date' => $this->onDate,
            'tasks' => array_map(static fn (PulseTaskRow $r): array => $r->toArray(), $this->plan),
            'leads_by_id' => $leadsById,
        ];
    }

    /**
     * Rebuild a snapshot from its persisted JSON (round-trip with toArray()).
     * The `tasks` list rehydrates the PLAN bucket; FACT is recomputed by the
     * caller (metrics layer) from the rehydrated rows, so it is left empty here.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $plan = [];
        /** @var array<int, array<string, mixed>> $rawTasks */
        $rawTasks = $data['tasks'] ?? [];
        foreach ($rawTasks as $raw) {
            $plan[] = PulseTaskRow::fromArray($raw);
        }

        $leadsById = [];
        /** @var array<string, array<string, mixed>> $rawLeads */
        $rawLeads = $data['leads_by_id'] ?? [];
        foreach ($rawLeads as $dealId => $lead) {
            $leadsById[(int) $dealId] = [
                'name' => isset($lead['name']) ? (string) $lead['name'] : null,
                'status_id' => isset($lead['status_id']) ? (int) $lead['status_id'] : null,
                'responsible_user_id' => isset($lead['responsible_user_id']) ? (int) $lead['responsible_user_id'] : null,
                'updated_by' => isset($lead['updated_by']) ? (int) $lead['updated_by'] : null,
            ];
        }

        return new self(
            managerId: (int) ($data['manager_id'] ?? 0),
            managerName: (string) ($data['manager_name'] ?? ''),
            onDate: (string) ($data['on_date'] ?? ''),
            plan: $plan,
            fact: [],
            leadsById: $leadsById,
        );
    }

    /**
     * @return list<int>
     */
    public function planTaskIds(): array
    {
        return array_values(array_map(static fn (PulseTaskRow $r): int => $r->taskId, $this->plan));
    }

    /**
     * @return list<int>
     */
    public function factTaskIds(): array
    {
        return array_values(array_map(static fn (PulseTaskRow $r): int => $r->taskId, $this->fact));
    }
}
