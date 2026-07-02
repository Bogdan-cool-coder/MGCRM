<?php

declare(strict_types=1);

namespace Tests\Unit\Crm;

use App\Domain\Crm\Models\AcquisitionChannel;
use App\Domain\Crm\Models\AcquisitionChannelHistory;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\DisconnectReason;
use App\Domain\Crm\Models\Source;
use App\Domain\Crm\Services\CrmDirectoryPurgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Unit tests for CrmDirectoryPurgeService::purgeAll() — system-reset
 * `directories` category (docs/contracts/system-reset-api-contract.md
 * §1 row 9), covering the loose Crm dictionaries that have no dedicated
 * CRUD Service of their own: crm_sources, acquisition_channels(+history),
 * disconnect_reasons.
 */
class CrmDirectoryPurgeServiceTest extends TestCase
{
    use RefreshDatabase;

    private CrmDirectoryPurgeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CrmDirectoryPurgeService::class);
    }

    public function test_purges_sources_channels_channel_history_and_disconnect_reasons(): void
    {
        // Migration seeds 5 default sources (INSERT-MISSING) — purgeAll must clear those too.
        $seededSources = DB::table('crm_sources')->count();
        Source::create(['code' => 'custom_src', 'name' => 'Custom', 'sort_order' => 99]);

        $channel = AcquisitionChannel::create(['name' => 'Referral', 'sort_order' => 1]);
        AcquisitionChannelHistory::create([
            'entity_type' => 'company',
            'entity_id' => 1,
            'old_channel_id' => null,
            'new_channel_id' => $channel->id,
            'changed_at' => now(),
        ]);

        DisconnectReason::create(['name' => 'Price too high', 'sort_order' => 1]);

        $counts = $this->service->purgeAll();

        $this->assertSame($seededSources + 1, $counts['crm_sources']);
        $this->assertSame(1, $counts['acquisition_channel_history']);
        $this->assertSame(1, $counts['acquisition_channels']);
        $this->assertSame(1, $counts['disconnect_reasons']);

        $this->assertSame(0, DB::table('crm_sources')->count());
        $this->assertSame(0, DB::table('acquisition_channel_history')->count());
        $this->assertSame(0, DB::table('acquisition_channels')->count());
        $this->assertSame(0, DB::table('disconnect_reasons')->count());
    }

    public function test_does_not_fk_violate_when_companies_still_reference_the_dictionaries(): void
    {
        // acquisition_channel_id / disconnect_reason_id are nullOnDelete — a
        // company still pointing at these rows must not block the purge.
        $channel = AcquisitionChannel::create(['name' => 'Referral', 'sort_order' => 1]);
        $reason = DisconnectReason::create(['name' => 'Price too high', 'sort_order' => 1]);

        $company = Company::factory()->create([
            'acquisition_channel_id' => $channel->id,
            'disconnect_reason_id' => $reason->id,
        ]);

        $this->service->purgeAll();

        $this->assertSame(0, DB::table('acquisition_channels')->count());
        $this->assertSame(0, DB::table('disconnect_reasons')->count());

        // Company survives — this purge only owns the dictionaries, not crm_companies.
        $this->assertNull($company->fresh()->acquisition_channel_id);
        $this->assertNull($company->fresh()->disconnect_reason_id);
    }

    public function test_is_safe_to_call_when_no_rows_exist(): void
    {
        DB::table('crm_sources')->delete();

        $counts = $this->service->purgeAll();

        $this->assertSame([
            'crm_sources' => 0,
            'acquisition_channel_history' => 0,
            'acquisition_channels' => 0,
            'disconnect_reasons' => 0,
        ], $counts);
    }
}
