<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Log\Enums\LogAction;
use App\Domain\Log\Enums\LogSubjectType;
use App\Domain\Log\Models\EntityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for the SELECTIVE system reset (contract §6).
 *
 * SAFETY: these tests run against the sqlite :memory: schema only (triple-isolated
 * TestCase). They exercise the GUARDS + orchestration (auth, flag, phrase, prereq,
 * per-category report, mandatory audit). The actual per-category DELETEs run against
 * the throwaway in-memory DB — they NEVER touch a live/dev DB.
 *
 * Domain purger integration (deals/contacts/companies/... counts) is asserted only
 * for the categories backend-architect owns end-to-end (logs) + the finance no-op.
 * The cross-domain purge counts are covered by each domain's own purgeAll() tests;
 * the full multi-category integration run is a post-merge concern (see the reset
 * contract §7 sequencing).
 */
class SystemResetTest extends TestCase
{
    use RefreshDatabase;

    private function enableReset(): void
    {
        config()->set('system.reset_enabled', true);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    /** @param  list<string>  $categories */
    private function payload(array $categories = ['logs'], string $confirmation = 'СБРОСИТЬ'): array
    {
        return ['categories' => $categories, 'confirmation' => $confirmation];
    }

    // -------------------------------------------------------------------------
    // Auth / role (contract §6.2 — admin only, NOT director)
    // -------------------------------------------------------------------------

    public function test_unauthenticated_returns_401(): void
    {
        $this->enableReset();

        $this->postJson('/api/system/reset', $this->payload())->assertUnauthorized();
        $this->getJson('/api/system/reset/preview')->assertUnauthorized();
    }

    public function test_manager_is_forbidden(): void
    {
        $this->enableReset();
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->postJson('/api/system/reset', $this->payload())->assertForbidden();
        $this->getJson('/api/system/reset/preview')->assertForbidden();
    }

    public function test_director_is_forbidden(): void
    {
        // Reset is strictly admin-only — director (who has admin-write) is denied.
        $this->enableReset();
        $director = User::factory()->create(['role' => Role::Director]);
        Sanctum::actingAs($director, ['*']);

        $this->postJson('/api/system/reset', $this->payload())->assertForbidden();
        $this->getJson('/api/system/reset/preview')->assertForbidden();
    }

    public function test_admin_is_allowed(): void
    {
        $this->enableReset();
        $this->actingAsAdmin();

        $this->getJson('/api/system/reset/preview')->assertOk();
    }

    // -------------------------------------------------------------------------
    // Feature flag (contract §6 — off by default, 403 on both paths when off)
    // -------------------------------------------------------------------------

    public function test_default_flag_is_false(): void
    {
        $this->assertFalse((bool) config('system.reset_enabled'));
    }

    public function test_disabled_flag_forbids_admin_execute(): void
    {
        config()->set('system.reset_enabled', false);
        $this->actingAsAdmin();

        $this->postJson('/api/system/reset', $this->payload())->assertForbidden();
    }

    public function test_disabled_flag_forbids_admin_preview(): void
    {
        // A disabled feature is not advertised — preview 403s too (contract §6.1).
        config()->set('system.reset_enabled', false);
        $this->actingAsAdmin();

        $this->getJson('/api/system/reset/preview')->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Preview (contract §6.1 — counts + requires matrix)
    // -------------------------------------------------------------------------

    public function test_preview_returns_nine_categories_with_requires_matrix(): void
    {
        $this->enableReset();
        $this->actingAsAdmin();

        $res = $this->getJson('/api/system/reset/preview')->assertOk();

        $res->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.confirmation_phrase', 'СБРОСИТЬ')
            ->assertJsonCount(9, 'data.categories');

        $cats = collect($res->json('data.categories'))->keyBy('key');

        // Prerequisite matrix is API-driven (contract §3 / §6.1).
        $this->assertSame([], $cats['deals']['requires']);
        $this->assertEqualsCanonicalizing(['deals', 'contacts'], $cats['companies']['requires']);
        $this->assertEqualsCanonicalizing(['deals', 'contacts', 'companies'], $cats['directories']['requires']);

        // finance is greenfield → count 0 no-op (contract P7), stable key present.
        $this->assertSame(0, $cats['finance']['count']);
        $this->assertArrayHasKey('label', $cats['deals']);
    }

    public function test_preview_counts_reflect_live_rows(): void
    {
        $this->enableReset();
        $this->actingAsAdmin();

        EntityLog::factory()->count(3)->create();

        $res = $this->getJson('/api/system/reset/preview')->assertOk();
        $logs = collect($res->json('data.categories'))->firstWhere('key', 'logs');

        $this->assertSame(3, $logs['count']);
    }

    // -------------------------------------------------------------------------
    // Confirmation phrase (contract §6.2 — 422)
    // -------------------------------------------------------------------------

    public function test_missing_confirmation_phrase_returns_422(): void
    {
        $this->enableReset();
        $this->actingAsAdmin();

        $this->postJson('/api/system/reset', ['categories' => ['logs']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirmation');
    }

    public function test_wrong_confirmation_phrase_returns_422(): void
    {
        $this->enableReset();
        $this->actingAsAdmin();

        $this->postJson('/api/system/reset', $this->payload(['logs'], 'НЕТ'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirmation');
    }

    public function test_legacy_phrase_is_rejected(): void
    {
        // The phrase changed from «СБРОСИТЬ НАСТРОЙКИ» to «СБРОСИТЬ» (contract P1-A).
        $this->enableReset();
        $this->actingAsAdmin();

        $this->postJson('/api/system/reset', $this->payload(['logs'], 'СБРОСИТЬ НАСТРОЙКИ'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirmation');
    }

    // -------------------------------------------------------------------------
    // Category validation (contract §6.2 — allow-list)
    // -------------------------------------------------------------------------

    public function test_empty_categories_returns_422(): void
    {
        $this->enableReset();
        $this->actingAsAdmin();

        $this->postJson('/api/system/reset', $this->payload([]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('categories');
    }

    public function test_unknown_category_key_returns_422(): void
    {
        $this->enableReset();
        $this->actingAsAdmin();

        $this->postJson('/api/system/reset', $this->payload(['users']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('categories.0');
    }

    // -------------------------------------------------------------------------
    // Prerequisite guard (contract §3 — hard-block 422, nothing deleted)
    // -------------------------------------------------------------------------

    public function test_companies_without_prerequisites_returns_422(): void
    {
        $this->enableReset();
        $this->actingAsAdmin();

        // companies requires deals + contacts (contract §3).
        $this->postJson('/api/system/reset', $this->payload(['companies']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('categories');
    }

    public function test_prerequisite_conflict_deletes_nothing(): void
    {
        $this->enableReset();
        $this->actingAsAdmin();

        EntityLog::factory()->count(2)->create();

        // directories requires deals+contacts+companies; selecting it with logs only
        // must 422 and delete NOTHING (validation runs before any transaction, §3).
        $this->postJson('/api/system/reset', $this->payload(['directories', 'logs']))
            ->assertUnprocessable();

        $this->assertSame(2, EntityLog::query()->count());
    }

    public function test_directories_with_all_prerequisites_succeeds_with_no_failures(): void
    {
        // deals+contacts+companies+directories satisfies every prereq edge — no 422.
        // All six directories seams (Crm/Catalog/Sales/Contracts) are merged, so the
        // run reports NO failed categories (composition end-to-end).
        $this->enableReset();
        $this->actingAsAdmin();

        $this->postJson('/api/system/reset', $this->payload(
            ['deals', 'contacts', 'companies', 'directories'],
        ))
            ->assertOk()
            ->assertJsonPath('data.reset', true)
            ->assertJsonPath('data.failed', []);
    }

    public function test_all_nine_categories_together_succeed_with_no_failures(): void
    {
        // The full multi-category run (contract §3c order deals→…→logs) with every
        // real domain purgeAll merged. On an empty schema every count is 0, but the
        // point is orchestration: no FK violation, no missing purger, no failed[].
        $this->enableReset();
        $this->actingAsAdmin();

        EntityLog::factory()->count(2)->create();

        $res = $this->postJson('/api/system/reset', $this->payload([
            'deals', 'contacts', 'companies', 'tasks', 'docs',
            'finance', 'automations', 'directories', 'logs',
        ]))
            ->assertOk()
            ->assertJsonPath('data.reset', true)
            ->assertJsonPath('data.failed', []);

        // Every selected category has a deleted entry (finance = 0 no-op included).
        foreach (['deals', 'contacts', 'companies', 'tasks', 'docs', 'finance', 'automations', 'directories', 'logs'] as $key) {
            $this->assertArrayHasKey($key, $res->json('data.deleted'));
        }

        // logs ran last: the 2 pre-existing rows are gone, only the audit remains.
        $this->assertSame(1, EntityLog::query()->count());
    }

    // -------------------------------------------------------------------------
    // Execute — logs category (backend-architect-owned, end-to-end)
    // -------------------------------------------------------------------------

    public function test_reset_logs_category_wipes_entity_logs_and_reports_count(): void
    {
        $this->enableReset();
        $this->actingAsAdmin();

        EntityLog::factory()->count(5)->create();

        $res = $this->postJson('/api/system/reset', $this->payload(['logs']))
            ->assertOk()
            ->assertJsonPath('data.reset', true)
            ->assertJsonPath('data.requires_relogin', false)
            ->assertJsonPath('data.failed', []);

        // Representative count = the pre-wipe entity_logs count.
        $this->assertSame(5, $res->json('data.deleted.logs'));

        // After the wipe, only the mandatory audit row remains (contract §4).
        $this->assertSame(1, EntityLog::query()->count());
    }

    public function test_reset_finance_is_a_no_op_with_zero_count(): void
    {
        // finance maps to no tables (greenfield) — key accepted, count 0, no failure.
        $this->enableReset();
        $this->actingAsAdmin();

        $res = $this->postJson('/api/system/reset', $this->payload(['finance']))
            ->assertOk()
            ->assertJsonPath('data.failed', []);

        $this->assertSame(0, $res->json('data.deleted.finance'));
        $this->assertSame(0, $res->json('data.total_deleted'));
    }

    // -------------------------------------------------------------------------
    // Mandatory audit (contract §4 — written AFTER the logs self-wipe)
    // -------------------------------------------------------------------------

    public function test_audit_row_is_written_after_logs_wipe(): void
    {
        $this->enableReset();
        $admin = $this->actingAsAdmin();

        EntityLog::factory()->count(4)->create();

        $this->postJson('/api/system/reset', $this->payload(['logs']))->assertOk();

        // Exactly one row survives: the reset's own audit, inserted post-wipe.
        $audit = EntityLog::query()->sole();

        $this->assertSame(LogSubjectType::System, $audit->subject_type);
        $this->assertSame(0, $audit->subject_id);
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame(LogAction::SystemReset, $audit->action);
        $this->assertSame(['logs'], $audit->meta['categories']);
        $this->assertSame(4, $audit->meta['deleted']['logs']);
        $this->assertSame(4, $audit->meta['total_deleted']);
        $this->assertTrue($audit->meta['confirmation_ok']);
    }

    public function test_audit_row_written_even_without_logs_category(): void
    {
        // Every reset writes exactly one audit row (contract §4), even when `logs`
        // is not selected. finance-only run → one audit row, no data touched.
        $this->enableReset();
        $this->actingAsAdmin();

        $this->postJson('/api/system/reset', $this->payload(['finance']))->assertOk();

        $audit = EntityLog::query()
            ->where('action', LogAction::SystemReset->value)
            ->sole();

        $this->assertSame(['finance'], $audit->meta['categories']);
    }

    // -------------------------------------------------------------------------
    // Rate limit (contract §6.2 — throttle:3,1)
    // -------------------------------------------------------------------------

    public function test_execute_is_rate_limited(): void
    {
        $this->enableReset();
        $this->actingAsAdmin();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/system/reset', $this->payload(['finance']))->assertOk();
        }

        $this->postJson('/api/system/reset', $this->payload(['finance']))
            ->assertStatus(429);
    }
}
