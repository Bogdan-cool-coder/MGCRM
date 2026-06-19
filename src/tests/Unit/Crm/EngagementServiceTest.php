<?php

declare(strict_types=1);

namespace Tests\Unit\Crm;

use App\Domain\Crm\Enums\EngagementTier;
use App\Domain\Crm\Services\EngagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Unit tests for EngagementService tier computation (B2).
 * No DB: pure date math tested in isolation.
 */
class EngagementServiceTest extends TestCase
{
    use RefreshDatabase;

    private EngagementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EngagementService;
    }

    public function test_null_last_activity_is_cold(): void
    {
        $tier = $this->service->computeTier(null, 14, 45);
        $this->assertSame(EngagementTier::Cold, $tier);
    }

    public function test_recent_activity_is_fresh(): void
    {
        // 5 days ago — well within warm_days=14
        $lastActivity = Carbon::now()->subDays(5);
        $tier = $this->service->computeTier($lastActivity, 14, 45);
        $this->assertSame(EngagementTier::Fresh, $tier);
    }

    public function test_boundary_warm_days_is_fresh(): void
    {
        // Exactly at warm_days=14 → Fresh
        $lastActivity = Carbon::now()->subDays(14);
        $tier = $this->service->computeTier($lastActivity, 14, 45);
        $this->assertSame(EngagementTier::Fresh, $tier);
    }

    public function test_just_past_warm_days_is_cooling(): void
    {
        // 15 days ago → Cooling (warm=14, cold=45)
        $lastActivity = Carbon::now()->subDays(15);
        $tier = $this->service->computeTier($lastActivity, 14, 45);
        $this->assertSame(EngagementTier::Cooling, $tier);
    }

    public function test_boundary_cold_days_is_cooling(): void
    {
        // Exactly at cold_days=45 → Cooling
        $lastActivity = Carbon::now()->subDays(45);
        $tier = $this->service->computeTier($lastActivity, 14, 45);
        $this->assertSame(EngagementTier::Cooling, $tier);
    }

    public function test_past_cold_days_is_cold(): void
    {
        // 50 days ago → Cold
        $lastActivity = Carbon::now()->subDays(50);
        $tier = $this->service->computeTier($lastActivity, 14, 45);
        $this->assertSame(EngagementTier::Cold, $tier);
    }

    public function test_company_thresholds_30_90(): void
    {
        // 20 days → Fresh for company (warm=30)
        $fresh = $this->service->computeTier(Carbon::now()->subDays(20), 30, 90);
        $this->assertSame(EngagementTier::Fresh, $fresh);

        // 60 days → Cooling (30 < 60 <= 90)
        $cooling = $this->service->computeTier(Carbon::now()->subDays(60), 30, 90);
        $this->assertSame(EngagementTier::Cooling, $cooling);

        // 100 days → Cold
        $cold = $this->service->computeTier(Carbon::now()->subDays(100), 30, 90);
        $this->assertSame(EngagementTier::Cold, $cold);
    }
}
