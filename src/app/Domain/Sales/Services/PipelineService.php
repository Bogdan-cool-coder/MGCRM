<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Enums\PipelineKind;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PipelineService — pipeline & stage editor (S1.5). All domain invariants live
 * here, not in controllers/FormRequests:
 *  - creating a pipeline auto-seeds 3 system stages (new/won/lost) so
 *    DealService/DealMoveService work immediately;
 *  - won/lost semantics are read-only for the editor (only the seeder sets the
 *    flags) — they are never accepted from request data;
 *  - a stage/pipeline holding deals cannot be deleted (409, DB RESTRICT backs it);
 *  - the last sales pipeline cannot be deleted (422);
 *  - sub-statuses are limited to one nesting level.
 */
class PipelineService
{
    /** Settings keys the editor is allowed to persist (Q3). */
    private const SAFE_SETTINGS_KEYS = ['stage_features', 'kanban'];

    /**
     * Whitelist of deal fields usable in stage required_fields (Е).
     *
     * Only fields where "blank" is a reachable state belong here. `amount` is
     * NOT NULL with a default of 0 and is derived from deal_products; since
     * blank(0) === false the required-fields gate can never fire for it, so it
     * is excluded to keep the contract honest (no dead, unenforceable rule). A
     * "amount must be > 0" rule is a separate concern, not required_fields (E,
     * BUG-8). The kept fields are either nullable or blank-able strings.
     */
    private const REQUIRED_DEAL_FIELDS = [
        'title', 'currency', 'contract_id',
        'expected_close_date', 'expected_sign_date', 'expected_payment_date',
    ];

    /** Whitelist of company fields usable in stage required_fields (Е). */
    private const REQUIRED_COMPANY_FIELDS = [
        'name', 'legal_name', 'tax_id', 'phone', 'email', 'website',
        'address', 'country_code', 'city', 'industry', 'source',
    ];

    /**
     * System stages auto-seeded on pipeline creation. Codes/names mirror the
     * locked seeder defaults so funnels stay consistent.
     *
     * @var list<array{code: string, name: string, is_won?: bool, is_lost?: bool, hidden?: bool, won_gate?: bool}>
     */
    private const SYSTEM_STAGES = [
        ['code' => 'new', 'name' => 'Новые лиды'],
        ['code' => 'won', 'name' => 'Успешная сделка', 'is_won' => true, 'won_gate' => true],
        ['code' => 'lost', 'name' => 'Сделка проиграна', 'is_lost' => true, 'hidden' => true],
    ];

    // =====================================================================
    // Reads (S1.3)
    // =====================================================================

    /**
     * List pipelines (optionally filtered by kind) with ordered stages eager-loaded.
     *
     * When $user is given, pipeline-level visibility is ENFORCED: a manager/etc.
     * only sees pipelines they are allowed to (canAccess). Admins/directors —
     * who configure visibility — always see every pipeline. Stages carrying a
     * stage-level restriction are filtered per-pipeline by the same rule so a
     * manager never bords/lists a stage hidden from them. Passing null keeps the
     * unscoped behaviour for internal/system callers (seeders, automations).
     *
     * @return Collection<int, Pipeline>
     */
    public function list(?string $kind = null, ?User $user = null): Collection
    {
        $pipelines = Pipeline::query()
            ->with('stages')
            ->when($kind !== null, fn ($q) => $q->where('kind', $kind))
            // Active funnels first so list[0] is the meaningful default the
            // dashboard/board pre-select latches onto (an archived "копия" must
            // never sort ahead of the live funnel).
            ->orderByDesc('is_active')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($user === null || $this->managesPipelines($user)) {
            return $pipelines;
        }

        return $pipelines
            ->filter(fn (Pipeline $pipeline): bool => $this->canAccess($pipeline, $user))
            ->each(fn (Pipeline $pipeline) => $pipeline->setRelation(
                'stages',
                $pipeline->stages->filter(
                    fn (PipelineStage $stage): bool => $this->canAccessStage($stage, $user),
                )->values(),
            ))
            ->values();
    }

    /**
     * Whether a user may see/list a pipeline (visibility enforcement, M1).
     *
     * Admins/directors manage the funnels and always pass. For everyone else a
     * pipeline is visible when it carries NO restriction (visible_role null AND
     * visible_user_ids empty) OR the user's role matches visible_role OR the
     * user's id is in visible_user_ids. Fail-OPEN on an unrestricted pipeline so
     * the default (no config) stays "visible to all", matching today's behaviour.
     */
    public function canAccess(Pipeline $pipeline, User $user): bool
    {
        if ($this->managesPipelines($user)) {
            return true;
        }

        $role = $pipeline->visible_role;
        $userIds = $pipeline->visible_user_ids ?? [];

        // No restriction configured → visible to everyone.
        if (($role === null || $role === '') && $userIds === []) {
            return true;
        }

        // Per-pipeline role-identity match (IAM-2): the pipeline stores a role
        // NAME string in `visible_role`; resolve the user's role through spatie
        // (sanctum guard) rather than the enum accessor. $role is guarded non-empty
        // here, so hasRole() is never asked about a null/blank name.
        if ($role !== null && $role !== '' && $user->hasRole($role)) {
            return true;
        }

        return in_array((int) $user->id, array_map('intval', $userIds), true);
    }

    /**
     * Whether a user may see a stage within a pipeline (stage-level visibility).
     * Same fail-open default: no visible_department_ids/visible_user_ids → visible.
     */
    public function canAccessStage(PipelineStage $stage, User $user): bool
    {
        if ($this->managesPipelines($user)) {
            return true;
        }

        $departmentIds = $stage->visible_department_ids ?? [];
        $userIds = $stage->visible_user_ids ?? [];

        if ($departmentIds === [] && $userIds === []) {
            return true;
        }

        if ($user->department_id !== null
            && in_array((int) $user->department_id, array_map('intval', $departmentIds), true)) {
            return true;
        }

        return in_array((int) $user->id, array_map('intval', $userIds), true);
    }

    /**
     * Whether the user sees ALL pipelines (full visibility read-scope) vs only
     * those visible to them. Admins/directors configure the funnels and carry
     * `pipelines.view-all`, so they always see every pipeline. IAM-2: resolved
     * through spatie (sanctum guard) — was an inline `users.role` list.
     */
    private function managesPipelines(User $user): bool
    {
        return $user->can('pipelines.view-all');
    }

    public function find(int $id): Pipeline
    {
        return Pipeline::query()->with('stages')->findOrFail($id);
    }

    /**
     * Ordered stages for a pipeline. System stages (won/lost) always sort to the
     * bottom regardless of their stored sort_order — the seeder/editor may give a
     * lost stage a low sort_order, but it must never render at the top of the
     * editor list. A single system-rank (0 = funnel stage, 1 = won/lost) keeps
     * the relative sort_order among system stages (won group before lost), then
     * sort_order breaks the rest. The CASE is portable across PG and SQLite.
     *
     * @return Collection<int, PipelineStage>
     */
    public function stagesFor(int $pipelineId): Collection
    {
        return PipelineStage::query()
            ->where('pipeline_id', $pipelineId)
            ->orderByRaw('CASE WHEN is_won THEN 1 WHEN is_lost THEN 1 ELSE 0 END')
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * The default sales pipeline (first active sales pipeline by sort order).
     */
    public function defaultSalesPipeline(): ?Pipeline
    {
        return Pipeline::query()
            ->where('kind', PipelineKind::Sales->value)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
    }

    // =====================================================================
    // Pipeline CRUD (Д-bis)
    // =====================================================================

    /**
     * Create a pipeline + its 3 system stages (new/won/lost) atomically. Without
     * the system stages a pipeline is non-functional (DealService can't find an
     * entry stage; DealMoveService can't close). kind is forced to sales in S1.5.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Pipeline
    {
        return DB::transaction(function () use ($data): Pipeline {
            $pipeline = Pipeline::create([
                'name' => $data['name'],
                'kind' => PipelineKind::Sales->value,
                'settings' => $this->safeSettings($data['settings'] ?? []),
                'is_active' => $data['is_active'] ?? true,
                'sort_order' => $data['sort_order'] ?? 0,
                // Pipeline-level visibility (M1). Absent keys → null/[] (visible to all).
                'visible_role' => $data['visible_role'] ?? null,
                'visible_user_ids' => $data['visible_user_ids'] ?? null,
            ]);

            foreach (self::SYSTEM_STAGES as $index => $def) {
                PipelineStage::create([
                    'pipeline_id' => $pipeline->id,
                    'name' => $def['name'],
                    'code' => $def['code'],
                    'sort_order' => $index + 1,
                    'is_won' => $def['is_won'] ?? false,
                    'is_lost' => $def['is_lost'] ?? false,
                    'hidden_by_default' => $def['hidden'] ?? false,
                    'won_gate' => $def['won_gate'] ?? false,
                ]);
            }

            return $pipeline->load('stages');
        });
    }

    /**
     * Stage columns carried over verbatim when duplicating a pipeline. Excludes
     * the identity (id), the parent link (remapped separately) and timestamps;
     * pipeline_id is set to the new pipeline. System won/lost flags are copied as
     * data so the clone is a faithful, immediately-usable funnel (the editor still
     * refuses to mutate them later).
     *
     * @var list<string>
     */
    private const DUPLICATED_STAGE_FIELDS = [
        'name', 'code', 'sort_order', 'color', 'warn_days', 'danger_days',
        'is_won', 'is_lost', 'hidden_by_default', 'stage_features', 'task_types',
        'required_fields', 'won_gate', 'won_gate_contract_required', 'sla_hours',
        'visible_department_ids', 'visible_user_ids',
    ];

    /**
     * Automation columns carried over when duplicating a pipeline. pipeline_id /
     * stage_id are remapped to the clone; the rotation cursor and run cursor are
     * reset (a fresh funnel has no run history), created_by is preserved.
     *
     * @var list<string>
     */
    private const DUPLICATED_AUTOMATION_FIELDS = [
        'name', 'description', 'trigger_kind', 'trigger_config',
        'action_kind', 'action_config', 'is_active', 'created_by_user_id',
    ];

    /**
     * Deep-copy a pipeline into a new, inactive funnel: every stage (colors,
     * order, system flags, rotting thresholds, gates, visibility, sub-statuses)
     * and every attached automation are cloned. Done in one transaction so a
     * partial clone can never surface.
     *
     * The clone is created inactive: defaultSalesPipeline() only picks active
     * pipelines, so a fresh copy never silently steals the default-funnel slot
     * before an admin has reviewed it. Stage `code` is reused verbatim — it is
     * unique per (pipeline_id, code), and the new pipeline_id keeps it distinct.
     * parent_stage_id and automation stage_id are remapped old->new id so the
     * clone is fully self-contained with no dangling references to the original.
     */
    public function duplicate(Pipeline $pipeline): Pipeline
    {
        return DB::transaction(function () use ($pipeline): Pipeline {
            $copy = Pipeline::create([
                'name' => $pipeline->name.' (копия)',
                'kind' => $pipeline->kind->value,
                'settings' => $pipeline->settings ?? [],
                'graph_layout' => $pipeline->graph_layout,
                'visible_role' => $pipeline->visible_role,
                'visible_user_ids' => $pipeline->visible_user_ids,
                'is_active' => false,
                'sort_order' => $pipeline->sort_order,
            ]);

            // Two passes: create every stage first (so parents exist), then wire
            // up parent_stage_id from the old->new id map. A stage cannot be
            // nested deeper than one level (assertParentIsValid), so a single
            // remap pass is sufficient.
            $stageIdMap = [];
            $sourceStages = $pipeline->stages()->get();

            foreach ($sourceStages as $stage) {
                $clone = PipelineStage::create([
                    ...$stage->only(self::DUPLICATED_STAGE_FIELDS),
                    'pipeline_id' => $copy->id,
                ]);
                $stageIdMap[$stage->id] = $clone->id;
            }

            foreach ($sourceStages as $stage) {
                if ($stage->parent_stage_id !== null) {
                    PipelineStage::query()
                        ->whereKey($stageIdMap[$stage->id])
                        ->update(['parent_stage_id' => $stageIdMap[$stage->parent_stage_id]]);
                }
            }

            foreach ($pipeline->automations()->get() as $automation) {
                PipelineAutomation::create([
                    ...$automation->only(self::DUPLICATED_AUTOMATION_FIELDS),
                    'pipeline_id' => $copy->id,
                    // NULL stage_id = whole-pipeline rule; only remap concrete stages.
                    'stage_id' => $automation->stage_id === null
                        ? null
                        : ($stageIdMap[$automation->stage_id] ?? null),
                    'round_robin_cursor' => 0,
                    'last_run_at' => null,
                ]);
            }

            return $copy->load('stages');
        });
    }

    /**
     * Partial pipeline update. kind is immutable; settings are filtered to the
     * safe keyset (Q3) before persisting.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Pipeline $pipeline, array $data): Pipeline
    {
        unset($data['kind']); // immutable after creation (defence in depth alongside the FormRequest)

        if (array_key_exists('settings', $data)) {
            $data['settings'] = $this->safeSettings(array_merge($pipeline->settings ?? [], $data['settings'] ?? []));
        }

        $pipeline->update($data);
        $pipeline->refresh();

        return $pipeline->load('stages');
    }

    /**
     * Delete a pipeline. Refused (409) if it holds deals; refused (422) if it is
     * the last sales pipeline. Empty pipelines cascade their stages (FK CASCADE).
     */
    public function delete(Pipeline $pipeline): void
    {
        if ($pipeline->deals()->exists()) {
            throw ValidationException::withMessages([
                'pipeline' => 'Cannot delete a pipeline that has deals — move or delete them first.',
            ])->status(409);
        }

        if ($pipeline->kind === PipelineKind::Sales
            && Pipeline::query()->sales()->count() === 1) {
            throw ValidationException::withMessages([
                'pipeline' => 'Cannot delete the only sales pipeline.',
            ])->status(422);
        }

        try {
            $pipeline->delete();
        } catch (QueryException) {
            // Race: a deal was created between the precheck and delete. RESTRICT
            // fires → surface a clean 409 instead of a 500.
            throw ValidationException::withMessages([
                'pipeline' => 'Cannot delete a pipeline that has deals — move or delete them first.',
            ])->status(409);
        }
    }

    // =====================================================================
    // Stage editor (Д)
    // =====================================================================

    /**
     * Create a top-level (or sub-) stage. won/lost flags are never accepted here
     * (system semantics are seeder-only). sort_order defaults to end of list.
     *
     * @param  array<string, mixed>  $data
     */
    public function createStage(Pipeline $pipeline, array $data): PipelineStage
    {
        $data = $this->sanitizeStageData($data);
        $this->assertRequiredFieldsShape($data['required_fields'] ?? null);
        $this->assertParentIsValid($pipeline, $data['parent_stage_id'] ?? null);

        $data['pipeline_id'] = $pipeline->id;
        $data['sort_order'] ??= $this->nextSortOrder($pipeline);

        $stage = PipelineStage::create($data);
        // Reload so DB-defaulted flags (is_won/is_lost/hidden_by_default/won_gate)
        // surface as their real boolean values rather than null in the response.
        $stage->refresh();

        return $stage;
    }

    /**
     * Partial stage update. won/lost flags are never accepted (system semantics
     * are seeder-only). parent/required_fields validation mirrors createStage.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateStage(PipelineStage $stage, array $data): PipelineStage
    {
        $data = $this->sanitizeStageData($data);

        if (array_key_exists('required_fields', $data)) {
            $this->assertRequiredFieldsShape($data['required_fields']);
        }

        if (array_key_exists('parent_stage_id', $data)) {
            $this->assertParentIsValid($stage->pipeline, $data['parent_stage_id'], $stage);
        }

        $stage->update($data);
        $stage->refresh();

        return $stage;
    }

    /**
     * Delete a stage. Order of guards:
     *   1. system won/lost → 422 (never deletable);
     *   2. has sub-statuses → 409;
     *   3. has deals → 409 (DB RESTRICT backs it against races).
     */
    public function deleteStage(PipelineStage $stage): void
    {
        if ($stage->is_won || $stage->is_lost) {
            throw ValidationException::withMessages([
                'stage' => 'A system (won/lost) stage cannot be deleted.',
            ])->status(422);
        }

        if ($stage->children()->exists()) {
            throw ValidationException::withMessages([
                'stage' => 'The stage has sub-statuses — delete or move them first.',
            ])->status(409);
        }

        if ($stage->deals()->exists()) {
            throw ValidationException::withMessages([
                'stage' => 'The stage holds deals — move them first.',
            ])->status(409);
        }

        try {
            $stage->delete();
        } catch (QueryException) {
            throw ValidationException::withMessages([
                'stage' => 'The stage holds deals — move them first.',
            ])->status(409);
        }
    }

    /**
     * Bulk reorder. Transactional + row-locked (anti concurrent-reorder race).
     * sort_order is normalized to a dense 1..N sequence from the ARRAY ORDER
     * (incoming sort_order values are ignored — the array position is the order),
     * matching vuedraggable semantics. Every id must belong to this pipeline.
     *
     * @param  list<array{id: int, sort_order?: int}>  $order
     * @return Collection<int, PipelineStage>
     */
    public function reorderStages(Pipeline $pipeline, array $order): Collection
    {
        return DB::transaction(function () use ($pipeline, $order): Collection {
            $stages = PipelineStage::query()
                ->where('pipeline_id', $pipeline->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $position = 1;
            foreach ($order as $item) {
                $id = (int) $item['id'];
                $stage = $stages->get($id);

                if ($stage === null) {
                    throw ValidationException::withMessages([
                        'stages' => 'A stage in the payload does not belong to this pipeline.',
                    ])->status(422);
                }

                $stage->update(['sort_order' => $position]);
                $position++;
            }

            return PipelineStage::query()
                ->where('pipeline_id', $pipeline->id)
                ->orderBy('sort_order')
                ->get();
        });
    }

    // =====================================================================
    // Private
    // =====================================================================

    /**
     * Strip request keys the editor must never write (system semantics).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sanitizeStageData(array $data): array
    {
        unset($data['is_won'], $data['is_lost'], $data['pipeline_id']);

        return $data;
    }

    private function nextSortOrder(Pipeline $pipeline): int
    {
        return (int) PipelineStage::query()
            ->where('pipeline_id', $pipeline->id)
            ->max('sort_order') + 1;
    }

    /**
     * Validate parent_stage_id: must belong to the same pipeline, be at most one
     * nesting level (parent must itself be top-level), and not be the stage itself.
     */
    private function assertParentIsValid(Pipeline $pipeline, ?int $parentId, ?PipelineStage $self = null): void
    {
        if ($parentId === null) {
            return;
        }

        if ($self !== null && (int) $self->id === $parentId) {
            throw ValidationException::withMessages([
                'parent_stage_id' => 'A stage cannot be its own parent.',
            ])->status(422);
        }

        $parent = PipelineStage::query()->find($parentId);

        if ($parent === null || (int) $parent->pipeline_id !== (int) $pipeline->id) {
            throw ValidationException::withMessages([
                'parent_stage_id' => 'The parent stage must belong to the same pipeline.',
            ])->status(422);
        }

        if ($parent->parent_stage_id !== null) {
            throw ValidationException::withMessages([
                'parent_stage_id' => 'A sub-status cannot be nested inside another sub-status.',
            ])->status(422);
        }
    }

    /**
     * Validate the required_fields shape: keys ⊆ {deal,company}; values are lists
     * of fields drawn from the per-entity whitelists (guards DealMoveService from
     * dereferencing unknown attributes).
     */
    private function assertRequiredFieldsShape(mixed $required): void
    {
        if ($required === null || $required === []) {
            return;
        }

        if (! is_array($required)) {
            throw ValidationException::withMessages([
                'required_fields' => 'required_fields must be an object {deal:[], company:[]}.',
            ])->status(422);
        }

        $allowed = [
            'deal' => self::REQUIRED_DEAL_FIELDS,
            'company' => self::REQUIRED_COMPANY_FIELDS,
        ];

        foreach ($required as $entity => $fields) {
            if (! isset($allowed[$entity])) {
                throw ValidationException::withMessages([
                    'required_fields' => "Unknown required-fields group: {$entity}.",
                ])->status(422);
            }

            foreach ((array) $fields as $field) {
                if (! in_array($field, $allowed[$entity], true)) {
                    throw ValidationException::withMessages([
                        'required_fields' => "Unknown {$entity} field in required_fields: {$field}.",
                    ])->status(422);
                }
            }
        }
    }

    /**
     * Keep only editor-safe settings keys (Q3): auto_assign / duplicate_check_*
     * belong to automation / crm domains and are ignored here.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function safeSettings(array $settings): array
    {
        return array_intersect_key($settings, array_flip(self::SAFE_SETTINGS_KEYS));
    }
}
