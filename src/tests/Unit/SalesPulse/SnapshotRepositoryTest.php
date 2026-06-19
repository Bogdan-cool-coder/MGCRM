<?php

declare(strict_types=1);

namespace Tests\Unit\SalesPulse;

use App\Domain\Iam\Models\User;
use App\Domain\SalesPulse\Data\DaySnapshot;
use App\Domain\SalesPulse\Enums\SnapKind;
use App\Domain\SalesPulse\Enums\SnapSource;
use App\Domain\SalesPulse\Models\PulseDailyStatus;
use App\Domain\SalesPulse\Models\PulseSnapshot;
use App\Domain\SalesPulse\Services\SnapshotRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coverage for snapshot persistence (spec §1.1 / §2): PLAN write-once (no
 * overwrite), FACT upsert, and the pulse_daily_status stamps (plan_at/fact_at +
 * source).
 */
class SnapshotRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private SnapshotRepository $repo;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = app(SnapshotRepository::class);
        $this->manager = User::factory()->create();
    }

    private function makeSnapshot(string $title = 'first'): DaySnapshot
    {
        return new DaySnapshot(
            managerId: (int) $this->manager->id,
            managerName: 'M',
            onDate: '2026-06-19',
            plan: [],
            fact: [],
            leadsById: [100 => [
                'name' => $title,
                'status_id' => 5,
                'responsible_user_id' => $this->manager->id,
                'updated_by' => $this->manager->id,
            ]],
        );
    }

    public function test_save_plan_persists_snapshot_and_stamps_daily_status(): void
    {
        $row = $this->repo->savePlan($this->makeSnapshot(), SnapSource::Manual);

        $this->assertSame(SnapKind::Plan, $row->kind);
        $this->assertSame(SnapSource::Manual, $row->source);

        $status = PulseDailyStatus::where('manager_id', $this->manager->id)
            ->whereDate('on_date', '2026-06-19')
            ->firstOrFail();

        $this->assertNotNull($status->plan_at);
        $this->assertSame(SnapSource::Manual, $status->plan_source);
        $this->assertNull($status->fact_at);
    }

    public function test_save_plan_is_write_once(): void
    {
        $first = $this->repo->savePlan($this->makeSnapshot('first'), SnapSource::Manual);

        // Second call with different data must NOT overwrite (immutable morning plan).
        $second = $this->repo->savePlan($this->makeSnapshot('second'), SnapSource::Auto);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PulseSnapshot::where('kind', SnapKind::Plan->value)->count());

        $stored = PulseSnapshot::findOrFail($first->id);
        $this->assertSame('first', $stored->data['leads_by_id']['100']['name']);
        $this->assertSame(SnapSource::Manual, $stored->source); // original source kept
    }

    public function test_save_fact_upserts(): void
    {
        $this->repo->saveFact($this->makeSnapshot('v1'), SnapSource::Manual);
        $this->repo->saveFact($this->makeSnapshot('v2'), SnapSource::Auto);

        $this->assertSame(1, PulseSnapshot::where('kind', SnapKind::Fact->value)->count());

        $stored = PulseSnapshot::where('kind', SnapKind::Fact->value)->firstOrFail();
        $this->assertSame('v2', $stored->data['leads_by_id']['100']['name']);
        $this->assertSame(SnapSource::Auto, $stored->source);

        $status = PulseDailyStatus::where('manager_id', $this->manager->id)->firstOrFail();
        $this->assertNotNull($status->fact_at);
        $this->assertSame(SnapSource::Auto, $status->fact_source);
    }

    public function test_load_round_trips_a_saved_snapshot(): void
    {
        $this->repo->savePlan($this->makeSnapshot('roundtrip'), SnapSource::Manual);

        $loaded = $this->repo->load((int) $this->manager->id, '2026-06-19', SnapKind::Plan);

        $this->assertNotNull($loaded);
        $this->assertSame((int) $this->manager->id, $loaded->managerId);
        $this->assertSame('roundtrip', $loaded->leadsById[100]['name']);
        $this->assertSame(5, $loaded->leadsById[100]['status_id']);
    }

    public function test_load_returns_null_when_absent(): void
    {
        $this->assertNull($this->repo->load((int) $this->manager->id, '2026-06-19', SnapKind::Fact));
    }

    public function test_plan_and_fact_coexist_on_one_daily_status_row(): void
    {
        $this->repo->savePlan($this->makeSnapshot(), SnapSource::Manual);
        $this->repo->saveFact($this->makeSnapshot(), SnapSource::Manual);

        $this->assertSame(1, PulseDailyStatus::where('manager_id', $this->manager->id)->count());

        $status = PulseDailyStatus::where('manager_id', $this->manager->id)->firstOrFail();
        $this->assertNotNull($status->plan_at);
        $this->assertNotNull($status->fact_at);
    }
}
