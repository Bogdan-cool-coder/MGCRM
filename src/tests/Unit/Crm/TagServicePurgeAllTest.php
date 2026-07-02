<?php

declare(strict_types=1);

namespace Tests\Unit\Crm;

use App\Domain\Crm\Models\Tag;
use App\Domain\Crm\Services\TagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Unit tests for TagService::purgeAll() — system-reset `directories` category
 * (docs/contracts/system-reset-api-contract.md §1 row 9).
 */
class TagServicePurgeAllTest extends TestCase
{
    use RefreshDatabase;

    private TagService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TagService::class);
    }

    public function test_purges_all_tags_including_seeded_defaults(): void
    {
        // Migration seeds 7 default tags (INSERT-MISSING) — purgeAll must clear those too.
        $seeded = DB::table('crm_tags')->count();
        Tag::create(['name' => 'Custom Tag', 'scope' => null, 'sort_order' => 99]);

        $counts = $this->service->purgeAll();

        $this->assertSame($seeded + 1, $counts['crm_tags']);
        $this->assertSame(0, DB::table('crm_tags')->count());
    }

    public function test_is_safe_to_call_twice_in_a_row(): void
    {
        $this->service->purgeAll();
        $counts = $this->service->purgeAll();

        $this->assertSame(['crm_tags' => 0], $counts);
    }
}
