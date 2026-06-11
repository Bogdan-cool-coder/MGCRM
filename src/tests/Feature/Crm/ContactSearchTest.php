<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Models\Contact;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests for GET /api/contacts?search=<q> (BUG-6 support).
 *
 * ContactService::list already handles ?search= via LIKE on full_name / email / phone.
 * These tests verify the wiring is correct end-to-end.
 */
class ContactSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($this->user, ['*']);
    }

    public function test_search_by_full_name(): void
    {
        $match = Contact::factory()->create(['full_name' => 'Иван Петров', 'owner_id' => $this->user->id]);
        Contact::factory()->create(['full_name' => 'Сергей Сидоров', 'owner_id' => $this->user->id]);

        $response = $this->getJson('/api/contacts?search=Иван')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($match->id, $ids);
        $this->assertNotContains(Contact::where('full_name', 'Сергей Сидоров')->first()->id, $ids);
    }

    public function test_search_by_email(): void
    {
        $match = Contact::factory()->create(['email' => 'ivan@example.com', 'owner_id' => $this->user->id]);
        Contact::factory()->create(['email' => 'sergey@example.com', 'owner_id' => $this->user->id]);

        $response = $this->getJson('/api/contacts?search=ivan')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($match->id, $ids);
    }

    public function test_search_by_phone(): void
    {
        $match = Contact::factory()->create(['phone' => '+7 (999) 111-22-33', 'owner_id' => $this->user->id]);
        Contact::factory()->create(['phone' => '+7 (888) 555-66-77', 'owner_id' => $this->user->id]);

        $response = $this->getJson('/api/contacts?search=999')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($match->id, $ids);
    }

    public function test_search_returns_all_when_no_term(): void
    {
        Contact::factory()->count(3)->create(['owner_id' => $this->user->id]);

        $response = $this->getJson('/api/contacts')->assertOk();

        $this->assertCount(3, $response->json('data'));
    }

    public function test_search_returns_empty_for_no_match(): void
    {
        Contact::factory()->create(['full_name' => 'Алексей Кузнецов', 'owner_id' => $this->user->id]);

        $response = $this->getJson('/api/contacts?search=ZZZNOMATCH')->assertOk();

        $this->assertCount(0, $response->json('data'));
    }
}
