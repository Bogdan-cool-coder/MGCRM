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

    // =========================================================================
    // BUG-2.1: Case-insensitive search (CRM-2.1)
    // In PostgreSQL, LIKE is case-sensitive. The fix uses ILIKE on PG and
    // LOWER() LIKE on SQLite.
    //
    // NOTE on SQLite + Cyrillic: SQLite's LOWER() is only case-aware for
    // ASCII characters. Cyrillic case-folding (е→Е) is not supported in
    // SQLite without ICU extension. Therefore case-insensitive Cyrillic tests
    // run correctly ONLY in the production PostgreSQL (via ILIKE).
    // The tests below use ASCII strings to exercise the SQLite-compatible path
    // and same-case Cyrillic to confirm the search pipeline is wired correctly.
    // The PostgreSQL ILIKE behaviour is validated by AppServiceProviderTest.
    // =========================================================================

    public function test_search_full_name_finds_by_complete_name_with_spaces(): void
    {
        // Regression: full ФИО with spaces must find the exact record.
        $match = Contact::factory()->create([
            'full_name' => 'Петрова Анна Михайловна',
            'owner_id' => $this->user->id,
        ]);
        Contact::factory()->create(['full_name' => 'Иванов Иван', 'owner_id' => $this->user->id]);

        $response = $this->getJson('/api/contacts?search='.urlencode('Петрова Анна Михайловна'))->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($match->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_search_by_email_case_insensitive_ascii(): void
    {
        // ASCII email — LOWER() LIKE works on SQLite for ASCII.
        $match = Contact::factory()->create([
            'email' => 'Ivan.Petrov@Example.com',
            'owner_id' => $this->user->id,
        ]);
        Contact::factory()->create(['email' => 'other@test.com', 'owner_id' => $this->user->id]);

        // Lowercase email query must match mixed-case stored value via LOWER()/ILIKE.
        $response = $this->getJson('/api/contacts?search=ivan.petrov')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($match->id, $ids);
    }

    public function test_search_partial_ascii_case_insensitive(): void
    {
        // ASCII name — LOWER() LIKE works on SQLite for ASCII chars.
        $match = Contact::factory()->create([
            'full_name' => 'John Smith',
            'owner_id' => $this->user->id,
        ]);
        Contact::factory()->create(['full_name' => 'Jane Doe', 'owner_id' => $this->user->id]);

        // Lowercase fragment — finds via LOWER()/ILIKE.
        $response = $this->getJson('/api/contacts?search=john')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($match->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_empty_search_string_returns_all_contacts(): void
    {
        // BUG fix: isset($filters['search']) was true for '' — fixed to !empty().
        // Passing search='' must be a no-op (return all visible contacts).
        Contact::factory()->count(3)->create(['owner_id' => $this->user->id]);

        $response = $this->getJson('/api/contacts?search=')->assertOk();

        $this->assertCount(3, $response->json('data'));
    }
}
