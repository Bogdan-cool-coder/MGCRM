<?php

declare(strict_types=1);

namespace Tests\Unit\Sales;

use App\Domain\Sales\Enums\PlanMetric;
use App\Domain\Sales\Enums\PlanScopeType;
use App\Domain\Sales\Services\Planning\PlanTargetService;
use Tests\TestCase;

/**
 * Pure-unit tests for PlanTargetService::resolveScopeKey() (contract §2.1/§O2)
 * and PlanMetric::isCompatibleWithScope() (contract §3.3 compatibility matrix).
 * No database.
 */
class PlanTargetScopeKeyTest extends TestCase
{
    private PlanTargetService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PlanTargetService::class);
    }

    // -------------------------------------------------------------------------
    // scope_key resolution
    // -------------------------------------------------------------------------

    public function test_user_scope_key(): void
    {
        $this->assertSame('user:12', $this->service->resolveScopeKey(PlanScopeType::User, 12, null, null));
    }

    public function test_pipeline_scope_key(): void
    {
        $this->assertSame('pipeline:1', $this->service->resolveScopeKey(PlanScopeType::Pipeline, null, 1, null));
    }

    public function test_company_scope_key_without_product_group(): void
    {
        $this->assertSame('company', $this->service->resolveScopeKey(PlanScopeType::Company, null, null, null));
    }

    public function test_company_scope_key_with_product_group(): void
    {
        $this->assertSame('pg:3', $this->service->resolveScopeKey(PlanScopeType::Company, null, null, 3));
    }

    public function test_different_users_never_collide(): void
    {
        $this->assertNotSame(
            $this->service->resolveScopeKey(PlanScopeType::User, 1, null, null),
            $this->service->resolveScopeKey(PlanScopeType::User, 2, null, null),
        );
    }

    public function test_user_and_pipeline_scope_keys_never_collide_even_with_same_numeric_id(): void
    {
        $this->assertNotSame(
            $this->service->resolveScopeKey(PlanScopeType::User, 1, null, null),
            $this->service->resolveScopeKey(PlanScopeType::Pipeline, null, 1, null),
        );
    }

    // -------------------------------------------------------------------------
    // metric x scope compatibility (§3.3)
    // -------------------------------------------------------------------------

    public function test_new_income_allows_user_pipeline_company(): void
    {
        $this->assertTrue(PlanMetric::NewIncome->isCompatibleWithScope(PlanScopeType::User));
        $this->assertTrue(PlanMetric::NewIncome->isCompatibleWithScope(PlanScopeType::Pipeline));
        $this->assertTrue(PlanMetric::NewIncome->isCompatibleWithScope(PlanScopeType::Company));
    }

    public function test_tasks_completed_disallows_company(): void
    {
        $this->assertTrue(PlanMetric::TasksCompleted->isCompatibleWithScope(PlanScopeType::User));
        $this->assertTrue(PlanMetric::TasksCompleted->isCompatibleWithScope(PlanScopeType::Pipeline));
        $this->assertFalse(PlanMetric::TasksCompleted->isCompatibleWithScope(PlanScopeType::Company));
    }

    public function test_conversion_disallows_company(): void
    {
        $this->assertFalse(PlanMetric::Conversion->isCompatibleWithScope(PlanScopeType::Company));
    }

    public function test_product_income_only_allows_company(): void
    {
        $this->assertTrue(PlanMetric::ProductIncome->isCompatibleWithScope(PlanScopeType::Company));
        $this->assertFalse(PlanMetric::ProductIncome->isCompatibleWithScope(PlanScopeType::User));
        $this->assertFalse(PlanMetric::ProductIncome->isCompatibleWithScope(PlanScopeType::Pipeline));
    }

    // -------------------------------------------------------------------------
    // isMoney / isCount
    // -------------------------------------------------------------------------

    public function test_money_metrics(): void
    {
        $this->assertTrue(PlanMetric::NewIncome->isMoney());
        $this->assertTrue(PlanMetric::ProductIncome->isMoney());
        $this->assertFalse(PlanMetric::NewIncome->isCount());
    }

    public function test_count_metrics(): void
    {
        $this->assertTrue(PlanMetric::ExpectedDeals->isCount());
        $this->assertTrue(PlanMetric::TasksCompleted->isCount());
        $this->assertTrue(PlanMetric::Conversion->isCount());
        $this->assertFalse(PlanMetric::ExpectedDeals->isMoney());
    }
}
