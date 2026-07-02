<?php

declare(strict_types=1);

namespace Tests\Unit\Support\System;

use App\Support\System\ResetCategory;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the ResetCategory allow-list (contract §2, §3, §10). No DB — pure
 * enum behavior: the 9-key allow-list, the prerequisite matrix, and the FK-safe
 * execution order. These are the safety invariants a reviewer must be able to read
 * off one place (contract §4 note).
 */
class ResetCategoryTest extends TestCase
{
    public function test_exactly_nine_categories_form_the_allow_list(): void
    {
        // The allow-list is the enum: exactly the 9 design checkboxes, no more.
        // A new domain (Finance/CS) adding tables must NOT silently appear here.
        $this->assertSame(
            ['deals', 'contacts', 'companies', 'tasks', 'docs', 'finance', 'automations', 'directories', 'logs'],
            ResetCategory::values(),
        );
    }

    public function test_never_delete_tables_are_not_a_category(): void
    {
        // Users/roles/permissions/departments/pipelines/templates are NEVER a reset
        // category (contract §2). from() on any of those keys throws — they can never
        // be requested.
        foreach (['users', 'roles', 'permissions', 'departments', 'pipelines', 'templates'] as $key) {
            $this->assertNull(ResetCategory::tryFrom($key), "$key must not be a resettable category");
        }
    }

    public function test_prerequisite_matrix_matches_contract(): void
    {
        // companies → deals + contacts (contract §3).
        $this->assertEqualsCanonicalizing(
            ['deals', 'contacts'],
            array_map(fn (ResetCategory $c) => $c->value, ResetCategory::Companies->requires()),
        );

        // directories → deals + contacts + companies (heaviest FK web, §3).
        $this->assertEqualsCanonicalizing(
            ['deals', 'contacts', 'companies'],
            array_map(fn (ResetCategory $c) => $c->value, ResetCategory::Directories->requires()),
        );

        // Independent categories have no prerequisites.
        foreach ([ResetCategory::Deals, ResetCategory::Contacts, ResetCategory::Tasks, ResetCategory::Docs, ResetCategory::Finance, ResetCategory::Automations, ResetCategory::Logs] as $c) {
            $this->assertSame([], $c->requires(), "{$c->value} should have no prerequisites");
        }
    }

    public function test_logs_runs_last_in_execution_order(): void
    {
        // logs is deliberately LAST (contract §3c, §4) so per-category counts are
        // recordable before entity_logs is cleared and the audit is a post-wipe insert.
        $ranks = [];
        foreach (ResetCategory::cases() as $c) {
            $ranks[$c->value] = $c->executionRank();
        }

        $this->assertSame(max($ranks), $ranks['logs']);
        // deals runs first (parents of the FK web).
        $this->assertSame(min($ranks), $ranks['deals']);
    }

    public function test_execution_order_is_fk_safe_sequence(): void
    {
        $cases = ResetCategory::cases();
        usort($cases, fn (ResetCategory $a, ResetCategory $b) => $a->executionRank() <=> $b->executionRank());

        $this->assertSame(
            ['deals', 'contacts', 'companies', 'tasks', 'docs', 'finance', 'automations', 'directories', 'logs'],
            array_map(fn (ResetCategory $c) => $c->value, $cases),
        );
    }

    public function test_finance_has_no_representative_table(): void
    {
        // Greenfield — no table → preview count 0 no-op (contract P7).
        $this->assertNull(ResetCategory::Finance->representativeTable());
        $this->assertSame('entity_logs', ResetCategory::Logs->representativeTable());
        $this->assertSame('deals', ResetCategory::Deals->representativeTable());
    }

    public function test_from_keys_dedup_and_maps(): void
    {
        $out = ResetCategory::fromKeys(['logs', 'deals']);
        $this->assertSame([ResetCategory::Logs, ResetCategory::Deals], $out);
    }

    public function test_directories_is_composed_and_excludes_fx(): void
    {
        // directories has no single parent → composed, previewed as the SUM across
        // all its dictionary tables (contract §6.1). Every seam's tables are listed,
        // and catalog_exchange_rates is intentionally absent (FX excluded, P5).
        $this->assertNull(ResetCategory::Directories->representativeTable());

        $tables = ResetCategory::Directories->previewTables();
        $this->assertNotEmpty($tables);
        $this->assertNotContains('catalog_exchange_rates', $tables, 'FX must be excluded (P5)');

        // Each domain seam's representative table is present.
        foreach (['crm_sources', 'crm_tags', 'custom_field_defs', 'catalog_products', 'lost_reasons', 'message_templates'] as $t) {
            $this->assertContains($t, $tables, "$t should be part of directories");
        }

        // Single-parent categories are NOT composed (empty previewTables).
        $this->assertSame([], ResetCategory::Deals->previewTables());
        $this->assertSame([], ResetCategory::Logs->previewTables());
    }
}
