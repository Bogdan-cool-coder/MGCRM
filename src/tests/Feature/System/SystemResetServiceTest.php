<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Contracts\Models\MessageTemplate;
use App\Domain\Contracts\Models\MessageTemplateBinding;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Log\Enums\LogAction;
use App\Domain\Log\Models\EntityLog;
use App\Domain\Sales\Models\LostReason;
use App\Support\System\ResetCategory;
use App\Support\System\SystemResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Unit-style tests for the SystemResetService orchestrator (contract §3-§5). Uses a
 * DB (RefreshDatabase) because the service issues real per-category transactions +
 * the audit insert, but drives the service directly (not through HTTP) to isolate
 * the orchestration behavior: best-effort per-category, missing-purger reporting,
 * prerequisite validation, and post-hoc audit ordering.
 */
class SystemResetServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): SystemResetService
    {
        return app(SystemResetService::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin]);
    }

    public function test_prerequisite_violation_throws_before_any_delete(): void
    {
        EntityLog::factory()->count(3)->create();

        $this->expectException(ValidationException::class);

        try {
            $this->service()->reset(['companies'], $this->admin());
        } finally {
            // Nothing deleted — validation runs before any transaction (contract §3b).
            $this->assertSame(3, EntityLog::query()->count());
        }
    }

    public function test_directories_category_composes_all_domain_seams(): void
    {
        // directories is a COMPOSITION of six owning-domain purgers (Crm/Catalog/
        // Sales/Contracts). Seed the seams that have factories (Sales lost_reasons,
        // Contracts message_templates(+bindings), Catalog product family) and prove
        // the orchestrator sequences them all: every table ends empty and the
        // reported count is the honest SUM of rows wiped across the seams.
        LostReason::factory()->count(3)->create();
        $template = MessageTemplate::factory()->create();
        MessageTemplateBinding::factory()->count(2)->create(['message_template_id' => $template->id]);
        $group = ProductGroup::factory()->create();
        Product::factory()->forGroup($group)->count(4)->create();

        // Snapshot the composed-seam totals BEFORE the wipe (products family adds
        // groups + products + any plans/prices the factory made).
        $before = $this->directoryRowTotal();
        $this->assertGreaterThanOrEqual(3 + 1 + 2 + 1 + 4, $before);

        // directories requires deals+contacts+companies (contract §3) — co-select so
        // validation passes; those tables are empty here so they contribute 0.
        $result = $this->service()->reset(
            ['deals', 'contacts', 'companies', 'directories'],
            $this->admin(),
        );

        // No seam failed — every purger resolved.
        $this->assertSame([], $result->failed);

        // Every composed dictionary table is now empty.
        $this->assertSame(0, LostReason::query()->count());
        $this->assertSame(0, MessageTemplate::query()->count());
        $this->assertSame(0, MessageTemplateBinding::query()->count());
        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, ProductGroup::query()->count());

        // The category count is the summed total across all its tables (§6.1 composed).
        $this->assertSame($before, $result->deleted['directories']);

        // Mandatory audit row written after the run (contract §4).
        $this->assertSame(
            1,
            EntityLog::query()->where('action', LogAction::SystemReset->value)->count(),
        );
    }

    /**
     * Live SUM of rows across every table the `directories` category spans — the
     * expected composed count (contract §6.1). Single-sourced from the enum so this
     * helper and production read the same table list.
     */
    private function directoryRowTotal(): int
    {
        $total = 0;
        foreach (ResetCategory::Directories->previewTables() as $table) {
            if (Schema::hasTable($table)) {
                $total += (int) DB::table($table)->count();
            }
        }

        return $total;
    }

    public function test_logs_category_wipes_then_audits_in_correct_order(): void
    {
        EntityLog::factory()->count(6)->create();
        $admin = $this->admin();

        $result = $this->service()->reset(['logs'], $admin, '10.0.0.5');

        // Representative count = pre-wipe count.
        $this->assertSame(6, $result->deleted['logs']);
        $this->assertSame(6, $result->totalDeleted());
        $this->assertSame([], $result->failed);

        // Only the post-wipe audit row survives, and it carries the actor + ip.
        $audit = EntityLog::query()->sole();
        $this->assertSame(LogAction::SystemReset, $audit->action);
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame('10.0.0.5', $audit->meta['ip']);
    }

    public function test_finance_no_op_returns_zero_and_still_audits(): void
    {
        $result = $this->service()->reset(['finance'], $this->admin());

        $this->assertSame(0, $result->deleted['finance']);
        $this->assertSame([], $result->failed);
        $this->assertSame(
            1,
            EntityLog::query()->where('action', LogAction::SystemReset->value)->count(),
        );
    }

    public function test_preview_counts_use_representative_tables(): void
    {
        EntityLog::factory()->count(2)->create();

        $preview = $this->service()->previewCounts();

        $logs = collect($preview->categories)->firstWhere('key', 'logs');
        $finance = collect($preview->categories)->firstWhere('key', 'finance');

        $this->assertSame(2, $logs['count']);
        $this->assertSame(0, $finance['count']); // greenfield, no table
        $this->assertSame('СБРОСИТЬ', $preview->confirmationPhrase);
    }

    public function test_confirmation_phrase_is_the_new_word(): void
    {
        $this->assertSame('СБРОСИТЬ', SystemResetService::confirmationPhrase());
        $this->assertContains('logs', ResetCategory::values());
    }
}
