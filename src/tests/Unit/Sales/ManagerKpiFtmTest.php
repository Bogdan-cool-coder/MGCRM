<?php

declare(strict_types=1);

namespace Tests\Unit\Sales;

use App\Domain\Sales\Services\ManagerKpiService;
use stdClass;
use Tests\TestCase;

/**
 * Pure-unit tests for ManagerKpiService::ftmCounted().
 * Verifies the 5-condition FTM predicate — no DB.
 */
class ManagerKpiFtmTest extends TestCase
{
    private ManagerKpiService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ManagerKpiService::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a fully-qualified FTM activity object (all 5 conditions true).
     */
    private function ftmActivity(): stdClass
    {
        $obj = new stdClass;
        $obj->kind = 'meeting';
        $obj->is_first_time_meeting = true;
        $obj->ftm_decision_maker_attended = true;
        $obj->ftm_presentation_shown = true;
        $obj->ftm_report_url = 'https://example.com/report/1';

        return $obj;
    }

    // -------------------------------------------------------------------------
    // Positive case
    // -------------------------------------------------------------------------

    public function test_ftm_counted_when_all_5_conditions_true(): void
    {
        $this->assertTrue($this->service->ftmCounted($this->ftmActivity()));
    }

    // -------------------------------------------------------------------------
    // Each missing condition makes it NOT count
    // -------------------------------------------------------------------------

    public function test_ftm_not_counted_when_kind_is_call(): void
    {
        $activity = $this->ftmActivity();
        $activity->kind = 'call';

        $this->assertFalse($this->service->ftmCounted($activity));
    }

    public function test_ftm_not_counted_when_kind_is_task(): void
    {
        $activity = $this->ftmActivity();
        $activity->kind = 'task';

        $this->assertFalse($this->service->ftmCounted($activity));
    }

    public function test_ftm_not_counted_when_is_first_time_false(): void
    {
        $activity = $this->ftmActivity();
        $activity->is_first_time_meeting = false;

        $this->assertFalse($this->service->ftmCounted($activity));
    }

    public function test_ftm_not_counted_when_decision_maker_absent(): void
    {
        $activity = $this->ftmActivity();
        $activity->ftm_decision_maker_attended = false;

        $this->assertFalse($this->service->ftmCounted($activity));
    }

    public function test_ftm_not_counted_when_presentation_not_shown(): void
    {
        $activity = $this->ftmActivity();
        $activity->ftm_presentation_shown = false;

        $this->assertFalse($this->service->ftmCounted($activity));
    }

    public function test_ftm_not_counted_when_report_url_null(): void
    {
        $activity = $this->ftmActivity();
        $activity->ftm_report_url = null;

        $this->assertFalse($this->service->ftmCounted($activity));
    }

    public function test_ftm_not_counted_when_report_url_empty_string(): void
    {
        $activity = $this->ftmActivity();
        $activity->ftm_report_url = '';

        $this->assertFalse($this->service->ftmCounted($activity));
    }
}
