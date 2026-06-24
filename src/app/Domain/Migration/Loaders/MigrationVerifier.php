<?php

declare(strict_types=1);

namespace App\Domain\Migration\Loaders;

use App\Domain\Log\Enums\LogAction;
use App\Domain\Log\Enums\LogSubjectType;
use App\Domain\Migration\Models\ExternalRef;
use App\Domain\Sales\Models\Deal;
use Illuminate\Support\Facades\DB;

/**
 * MigrationVerifier — parity check between the EXTRACT staging and what the LOAD
 * actually wrote. Temporary migration bounded-context (dropped at M12).
 *
 * Parity = staging row count (the AMO source of truth on disk) vs the number of
 * external_refs for that entity type (what we loaded). A gap means a deal /
 * contact / company silently failed to load (e.g. an unmapped status hard-gate).
 *
 * Events have NO external_refs provenance (they fan out into stage_history /
 * deal_audits / entity_logs, not a 1:1 entity). Their parity is therefore the
 * staging event count vs the number of timeline entity_logs the import wrote for
 * deals (genesis → created, stage_change → stage_changed, data_change →
 * data_changed). A gap there means the timeline reconstruction silently dropped
 * rows (an unmapped target status / a malformed audit field) — which the old
 * staging-vs-itself comparison could never surface.
 *
 * spot-check returns a handful of loaded deals with their resolved stage / owner
 * / amount / timeline counts so the operator can eyeball a few cards in the UI.
 */
final class MigrationVerifier
{
    public function __construct(
        private readonly StagingReader $reader,
    ) {}

    /**
     * @return array{
     *     parity: array<string, array{staging: int, loaded: int, diff: int}>,
     *     spot_checks: list<array<string, mixed>>
     * }
     */
    public function verify(int $spotCheckCount = 5): array
    {
        return [
            'parity' => $this->parity(),
            'spot_checks' => $this->spotChecks($spotCheckCount),
        ];
    }

    /**
     * @return array<string, array{staging: int, loaded: int, diff: int}>
     */
    public function parity(): array
    {
        $stagingLeads = $this->reader->exists('leads') ? $this->reader->count('leads') : 0;
        $stagingContacts = $this->reader->exists('contacts') ? $this->reader->count('contacts') : 0;
        $stagingCompanies = $this->reader->exists('companies') ? $this->reader->count('companies') : 0;
        $stagingTasks = $this->reader->exists('tasks') ? $this->reader->count('tasks') : 0;
        $stagingEvents = $this->reader->exists('events') ? $this->reader->count('events') : 0;
        $stagingNotes = $this->reader->exists('notes') ? $this->reader->count('notes') : 0;

        return [
            'deals' => $this->row($stagingLeads, $this->loadedCount('deal')),
            'contacts' => $this->row($stagingContacts, $this->loadedCount('contact')),
            // Companies parity is approximate: synthetic (lead-company) refs are
            // counted alongside real ones, and many leads share a company — so
            // loaded <= staging is normal here.
            'companies' => $this->row($stagingCompanies, $this->loadedCount('company') + $this->loadedCount('lead-company')),
            // Activities (tasks+notes) are one ref type; staging is tasks+notes.
            'activities' => $this->row($stagingTasks + $stagingNotes, $this->loadedCount('activity')),
            // Events have no external_refs — compare staging events to the timeline
            // entity_logs the import actually wrote (created / stage_changed /
            // data_changed on deal subjects). loaded < staging surfaces a silently
            // dropped timeline row (unmapped target status / malformed audit).
            'events' => $this->row($stagingEvents, $this->loadedTimelineLogs()),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function spotChecks(int $count): array
    {
        $deals = Deal::query()
            ->with(['stage:id,name,code', 'owner:id,full_name', 'company:id,name'])
            ->latest('id')
            ->limit(max(0, $count))
            ->get();

        return $deals->map(fn (Deal $deal): array => [
            'deal_id' => $deal->id,
            'title' => $deal->title,
            'company' => $deal->company?->name,
            'stage' => $deal->stage?->name,
            'owner' => $deal->owner?->full_name,
            'amount_kopecks' => $deal->amount,
            'amount_locked' => $deal->amount_locked,
            'is_primary_deal' => $deal->is_primary_deal,
            'stage_history' => $deal->stageHistory()->count(),
            'audits' => $deal->audits()->count(),
        ])->all();
    }

    private function loadedCount(string $entityType): int
    {
        return (int) ExternalRef::query()
            ->where('source', 'amocrm')
            ->where('entity_type', $entityType)
            ->count();
    }

    /**
     * Count the timeline entity_logs the import reconstructs from AMO events:
     * genesis → created, stage_change → stage_changed, data_change → data_changed,
     * all on deal subjects. This is the "loaded" side of the events parity (events
     * have no external_refs of their own).
     */
    private function loadedTimelineLogs(): int
    {
        return (int) DB::table('entity_logs')
            ->where('subject_type', LogSubjectType::Deal->value)
            ->whereIn('action', [
                LogAction::Created->value,
                LogAction::StageChanged->value,
                LogAction::DataChanged->value,
            ])
            ->count();
    }

    /**
     * @return array{staging: int, loaded: int, diff: int}
     */
    private function row(int $staging, int $loaded): array
    {
        return ['staging' => $staging, 'loaded' => $loaded, 'diff' => $staging - $loaded];
    }
}
