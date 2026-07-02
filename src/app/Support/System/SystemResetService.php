<?php

declare(strict_types=1);

namespace App\Support\System;

use App\Domain\Activity\Services\ActivityService;
use App\Domain\Automation\Services\AutomationService;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Contracts\Services\DocumentService;
use App\Domain\Contracts\Services\MessageTemplateService;
use App\Domain\Crm\Services\CompanyService;
use App\Domain\Crm\Services\ContactService;
use App\Domain\Crm\Services\CrmDirectoryPurgeService;
use App\Domain\Crm\Services\CustomFieldService;
use App\Domain\Crm\Services\TagService;
use App\Domain\Iam\Models\User;
use App\Domain\Log\Enums\LogAction;
use App\Domain\Log\Enums\LogSubjectType;
use App\Domain\Log\Services\EntityLogService;
use App\Domain\Sales\Services\DealService;
use App\Domain\Sales\Services\LostReasonService;
use App\Support\System\Data\ResetPreviewData;
use App\Support\System\Data\ResetResultData;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * SystemResetService — the cross-cutting orchestrator of the selective system reset
 * (docs/contracts/system-reset-api-contract.md). Lives in app/Support (NOT a domain)
 * because "reset the whole system" is inherently cross-domain with no single owner
 * (backend-standard §2, contract §7 boundary decision).
 *
 * It NEVER touches a foreign domain's tables directly (backend-standard §3). Each
 * ResetCategory maps to one or more owning-domain purgers — a public `purgeAll()`
 * on that domain's existing Service (DealService, ContactService, DocumentService,
 * AutomationService, …), plus the backend-architect-owned LogPurger for `entity_logs`
 * (Log has no domain agent). This class only SEQUENCES them: allow-list, prerequisite
 * validation, FK-safe order, per-category transaction, best-effort report, post-hoc
 * audit.
 *
 * SAFETY INVARIANTS (contract §10) enforced here:
 *   - Allow-list: only ResetCategory cases are resettable; the map is the whitelist.
 *   - Prerequisite validation runs BEFORE any transaction → clean 422, nothing
 *     deleted on FK-conflict.
 *   - Per-category DB::transaction() → best-effort; a category that throws rolls
 *     back alone and is reported in `failed[]`.
 *   - `logs` runs LAST; the mandatory audit row is recorded AFTER all deletions
 *     commit (so it survives even a logs-category self-wipe — contract §4).
 *
 * MISSING-PURGER TOLERANCE: a category whose owning-domain Service does not yet
 * expose `purgeAll()` (parallel work not merged) resolves to a null purger and is
 * reported in `failed[]` with a "purger not available" error rather than fatally
 * erroring the whole request — so the endpoint is testable ahead of every domain
 * landing its method. `finance` maps to no purger by design (greenfield, count 0).
 */
class SystemResetService
{
    public function __construct(
        private readonly Container $container,
        private readonly EntityLogService $entityLog,
    ) {}

    /**
     * Live per-category counts + prerequisite matrix for GET .../preview (§6.1).
     * `count` is the representative parent-table COUNT(*) (0 for greenfield/no-table
     * categories). Order = enum declaration order (matches the design checkbox list).
     */
    public function previewCounts(): ResetPreviewData
    {
        $categories = [];

        foreach (ResetCategory::cases() as $category) {
            $categories[] = [
                'key' => $category->value,
                'label' => $category->label(),
                'count' => $this->representativeCount($category),
                'requires' => array_map(
                    static fn (ResetCategory $r): string => $r->value,
                    $category->requires(),
                ),
            ];
        }

        return new ResetPreviewData(
            enabled: (bool) config('system.reset_enabled'),
            confirmationPhrase: SystemResetService::confirmationPhrase(),
            categories: $categories,
        );
    }

    /**
     * Execute the reset for the given category keys. Validation of the keys +
     * confirmation phrase happens upstream in SystemResetRequest; this method
     * assumes the keys are valid ResetCategory values.
     *
     * @param  list<string>  $categoryKeys
     */
    public function reset(array $categoryKeys, User $actor, ?string $ip = null): ResetResultData
    {
        $categories = ResetCategory::fromKeys($categoryKeys);

        // (1) Prerequisite validation — BEFORE any transaction opens (contract §3b).
        // A missing prerequisite is a clean 422 with nothing deleted.
        $this->assertPrerequisites($categories);

        // (2) FK-safe order: deals → … → automations → directories → logs (§3c).
        usort(
            $categories,
            static fn (ResetCategory $a, ResetCategory $b): int => $a->executionRank() <=> $b->executionRank(),
        );

        $deleted = [];
        $failed = [];

        // (3) Per-category transaction (best-effort, §5). A failing category rolls
        // back alone; earlier categories are already committed.
        foreach ($categories as $category) {
            try {
                $count = DB::transaction(fn (): int => $this->purgeCategory($category));
                $deleted[$category->value] = $count;
            } catch (Throwable $e) {
                $failed[] = [
                    'category' => $category->value,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $result = new ResetResultData(deleted: $deleted, failed: $failed);

        // (4) Mandatory audit — written AFTER all category transactions commit,
        // OUTSIDE any of them (contract §4). If `logs` was selected, entity_logs was
        // just truncated; this fresh insert is the first row of the empty log, so the
        // reset's own trace always survives.
        $this->recordAudit($categories, $result, $actor, $ip);

        return $result;
    }

    /**
     * The fixed confirmation phrase (contract §6.2, matches FE S_CONFIRM_WORD).
     */
    public static function confirmationPhrase(): string
    {
        return 'СБРОСИТЬ';
    }

    // -------------------------------------------------------------------------
    // Category → owning-domain purger map (the allow-list, contract §2/§7)
    // -------------------------------------------------------------------------

    /**
     * Resolve the ordered list of purger callables for a category. Each callable
     * returns an int (rows deleted) or a per-table array (reduced to the
     * representative count by the caller). A category with NO purger available
     * (domain method not yet merged) yields an empty list — the caller treats that
     * as unavailable and reports it in failed[]. `finance` intentionally yields an
     * empty list (greenfield no-op, contract P7) but is NOT reported as failed.
     *
     * The map holds class-string + method, resolved lazily from the container, so a
     * domain Service that has not yet added `purgeAll()` degrades to "missing"
     * instead of a class-not-found fatal.
     *
     * @return list<array{service: class-string|object, method: string}>
     */
    private function purgerBindings(ResetCategory $category): array
    {
        return match ($category) {
            ResetCategory::Deals => [
                ['service' => DealService::class, 'method' => 'purgeAll'],
            ],
            ResetCategory::Contacts => [
                ['service' => ContactService::class, 'method' => 'purgeAll'],
            ],
            ResetCategory::Companies => [
                ['service' => CompanyService::class, 'method' => 'purgeAll'],
            ],
            ResetCategory::Tasks => [
                ['service' => ActivityService::class, 'method' => 'purgeAll'],
            ],
            ResetCategory::Docs => [
                ['service' => DocumentService::class, 'method' => 'purgeAll'],
            ],
            // Greenfield — Domain/Finance does not exist. No purger, no-op count 0
            // (contract P7). NOT reported as failed.
            ResetCategory::Finance => [],
            ResetCategory::Automations => [
                ['service' => AutomationService::class, 'method' => 'purgeAll'],
            ],
            // Dictionaries span Crm / Catalog / Sales / Contracts — a COMPOSITION of
            // six owning-domain seams (contract §1 row 9, §7). Each owns its own
            // tables; Support only sequences the calls. Order is independent here
            // (all inter-dictionary FKs are nullOnDelete / self-contained), but the
            // orchestrator's §3 prerequisite matrix guarantees deals/contacts/
            // companies are already gone, so no business row references a dictionary.
            //   - CrmDirectoryPurgeService: crm_sources, acquisition_channels(+history),
            //     disconnect_reasons
            //   - TagService:               crm_tags
            //   - CustomFieldService:       custom_field_defs
            //   - ProductService:           catalog_products family (FX excluded, P5)
            //   - LostReasonService:        lost_reasons (Sales-owned)
            //   - MessageTemplateService:   message_templates(+bindings) (Contracts-owned)
            ResetCategory::Directories => [
                ['service' => CrmDirectoryPurgeService::class, 'method' => 'purgeAll'],
                ['service' => TagService::class, 'method' => 'purgeAll'],
                ['service' => CustomFieldService::class, 'method' => 'purgeAll'],
                ['service' => ProductService::class, 'method' => 'purgeAll'],
                ['service' => LostReasonService::class, 'method' => 'purgeAll'],
                ['service' => MessageTemplateService::class, 'method' => 'purgeAll'],
            ],
            // entity_logs — backend-architect-owned (Log has no domain agent).
            // automation_runs / deal_audits / deal_stage_history are cleared by the
            // Automation / Sales purgers under THEIR categories (no double-count).
            ResetCategory::Logs => [
                ['service' => LogPurger::class, 'method' => 'purgeAll'],
            ],
        };
    }

    /**
     * Run every purger bound to a category and return the reported count. Runs
     * inside the caller's DB::transaction(). Throws if the category has purger
     * bindings but NONE resolve (all domain methods missing) — surfaced as a failed
     * category. `finance` (empty bindings by design) returns 0 without throwing.
     *
     * COUNT SEMANTICS (contract §6.1/§6.2):
     *   - Single-parent category (has representativeTable): the count is that parent
     *     table's row count, found across whichever binding returned it (e.g. `deals`
     *     = 1248, the deals-table entry — not the sum of child pivots).
     *   - Composed category (representativeTable = null, e.g. `directories`): no
     *     single parent exists, so the count is the SUM of ALL rows deleted across
     *     every binding's per-table array — the honest "total dictionary rows wiped".
     */
    private function purgeCategory(ResetCategory $category): int
    {
        $bindings = $this->purgerBindings($category);

        // Greenfield no-op (finance) — nothing to do, count 0, not a failure.
        if ($bindings === [] && $category === ResetCategory::Finance) {
            return 0;
        }

        $ran = false;
        $count = 0;
        $representativeTable = $category->representativeTable();

        foreach ($bindings as $binding) {
            $purger = $this->resolvePurger($binding['service'], $binding['method']);
            if ($purger === null) {
                continue; // domain method not merged yet — skip this binding
            }

            $ran = true;
            /** @var int|array<string, int> $out */
            $out = $purger();
            $count += $this->reduceCount($out, $representativeTable);
        }

        // Bindings existed but not one resolved → the category is genuinely
        // unavailable this run; surface it as a failure (skip-if-missing at the
        // service layer becomes failed[] at the API — tests mark these skip-if-missing).
        if (! $ran) {
            throw new \RuntimeException(
                sprintf('No purger available for category "%s" (domain purgeAll() not merged).', $category->value),
            );
        }

        return $count;
    }

    /**
     * Resolve a purger to an invokable closure, or null if the domain has not yet
     * added the method (class exists but no `purgeAll`) or the class is absent.
     *
     * @param  class-string|object  $service
     * @return (callable(): (int|array<string, int>))|null
     */
    private function resolvePurger(string|object $service, string $method): ?callable
    {
        if (is_string($service) && ! class_exists($service)) {
            return null;
        }

        $instance = is_object($service) ? $service : $this->container->make($service);

        if (! method_exists($instance, $method)) {
            return null;
        }

        /** @return int|array<string, int> */
        return static fn () => $instance->{$method}();
    }

    /**
     * Reduce ONE purger's return (int OR per-table array) to its contribution to the
     * category count.
     *
     *   - $representativeTable != null (single-parent category): contribute this
     *     binding's parent-table row count. If the binding's output carries the
     *     representative key, use it; if not (a composed category should never reach
     *     here with a representative table, but a plain int is the parent count),
     *     use the int or the last array value. Bindings NOT holding the
     *     representative key contribute 0 — no double counting.
     *   - $representativeTable == null (composed category, e.g. `directories`):
     *     contribute the SUM of every table this binding deleted, so the category
     *     count is the honest total across all its dictionaries.
     *
     * @param  int|array<string, int>  $out
     */
    private function reduceCount(int|array $out, ?string $representativeTable): int
    {
        if (is_int($out)) {
            return $out;
        }

        if ($out === []) {
            return 0;
        }

        // Composed category — sum every deleted table in this binding.
        if ($representativeTable === null) {
            return (int) array_sum($out);
        }

        // Single-parent category — only the representative table's count counts.
        if (array_key_exists($representativeTable, $out)) {
            return (int) $out[$representativeTable];
        }

        // Representative key absent — fall back to the parent (last key by the
        // children-before-parents convention).
        return (int) end($out);
    }

    /**
     * The preview COUNT(*) for a category (contract §6.1):
     *   - single-parent category → the representative parent table's live count;
     *   - composed category (directories) → SUM of live rows across all its tables;
     *   - greenfield/no-table (finance) → 0.
     * Table existence is checked so a not-yet-created table yields 0, not an error.
     */
    private function representativeCount(ResetCategory $category): int
    {
        $table = $category->representativeTable();

        if ($table !== null) {
            return Schema::hasTable($table) ? (int) DB::table($table)->count() : 0;
        }

        // Composed category — sum every dictionary table it spans.
        $total = 0;
        foreach ($category->previewTables() as $composedTable) {
            if (Schema::hasTable($composedTable)) {
                $total += (int) DB::table($composedTable)->count();
            }
        }

        return $total;
    }

    // -------------------------------------------------------------------------
    // Prerequisite validation (contract §3b, hard-block 422)
    // -------------------------------------------------------------------------

    /**
     * Reject the whole request (422) if a selected category's prerequisites are not
     * also selected — before any deletion. Message names the missing category so the
     * operator/UI can react (contract §3, §6.2 error copy).
     *
     * @param  list<ResetCategory>  $categories
     */
    private function assertPrerequisites(array $categories): void
    {
        $selected = array_map(static fn (ResetCategory $c): string => $c->value, $categories);

        foreach ($categories as $category) {
            foreach ($category->requires() as $required) {
                if (! in_array($required->value, $selected, true)) {
                    throw ValidationException::withMessages([
                        'categories' => [
                            __('Нельзя удалить «:cat», не удалив «:req».', [
                                'cat' => $category->label(),
                                'req' => $required->label(),
                            ]),
                        ],
                    ]);
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // Mandatory post-hoc audit (contract §4)
    // -------------------------------------------------------------------------

    /**
     * Write the single audit row to entity_logs via EntityLogService::record().
     * Subject = System / id 0, actor = the admin, action = LogAction::SystemReset.
     * Called AFTER every category transaction commits and OUTSIDE any of them, so a
     * `logs`-category self-wipe cannot roll this insert back — the reset trace is the
     * first row of the freshly-emptied log (contract §4 ordering hazard).
     *
     * @param  list<ResetCategory>  $categories  (already ordered)
     */
    private function recordAudit(array $categories, ResetResultData $result, User $actor, ?string $ip): void
    {
        $this->entityLog->record(
            subjectType: LogSubjectType::System,
            subjectId: 0,
            actor: $actor,
            action: LogAction::SystemReset,
            meta: [
                'categories' => array_map(static fn (ResetCategory $c): string => $c->value, $categories),
                'deleted' => $result->deleted,
                'total_deleted' => $result->totalDeleted(),
                'failed' => $result->failed,
                'confirmation_ok' => true,
                'ip' => $ip,
            ],
        );
    }
}
