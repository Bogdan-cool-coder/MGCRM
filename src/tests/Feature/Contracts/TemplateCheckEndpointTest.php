<?php

declare(strict_types=1);

namespace Tests\Feature\Contracts;

use App\Domain\Contracts\Enums\AiCheckStatus;
use App\Domain\Contracts\Models\Template;
use App\Domain\Contracts\Models\TemplateVersion;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Jobs\Contracts\CheckTemplateJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for the AI-check lifecycle endpoints (S2.3):
 *   POST   /api/templates/{template}/versions/{version}/check
 *   POST   /api/templates/{template}/versions/{version}/override
 *   GET    /api/templates/{template}/versions
 *   GET    /api/templates/{template}/versions/{version}
 */
class TemplateCheckEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Template $template;

    private TemplateVersion $version;

    private User $lawyer;

    private User $admin;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');

        $this->template = Template::factory()->docx()->create();
        $this->version = TemplateVersion::factory()->create([
            'template_id' => $this->template->id,
            'ai_check_status' => AiCheckStatus::Checked,
            'ai_remarks' => [
                [
                    'type' => 'grammar',
                    'severity' => 'warning',
                    'text' => 'Тестовое замечание',
                ],
            ],
            'ai_overridden' => false,
            'pdf_ok' => true,
        ]);

        $this->lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $this->admin = User::factory()->create(['role' => Role::Admin]);
        $this->manager = User::factory()->create(['role' => Role::Manager]);
    }

    // ------------------------------------------------------------------
    // POST /check — re-dispatch
    // ------------------------------------------------------------------

    public function test_check_endpoint_dispatches_job_and_returns_202(): void
    {
        Bus::fake();
        Sanctum::actingAs($this->lawyer, ['*']);

        $this->postJson("/api/templates/{$this->template->id}/versions/{$this->version->id}/check")
            ->assertStatus(202);

        Bus::assertDispatched(CheckTemplateJob::class);
    }

    public function test_check_resets_status_to_pending(): void
    {
        Bus::fake();
        Sanctum::actingAs($this->lawyer, ['*']);

        $response = $this->postJson("/api/templates/{$this->template->id}/versions/{$this->version->id}/check")
            ->assertStatus(202);

        $this->assertEquals(AiCheckStatus::Pending->value, $response->json('data.ai_check_status'));

        $this->version->refresh();
        $this->assertSame(AiCheckStatus::Pending, $this->version->ai_check_status);
    }

    // ------------------------------------------------------------------
    // POST /override
    // ------------------------------------------------------------------

    public function test_override_sets_ai_overridden_true(): void
    {
        Sanctum::actingAs($this->lawyer, ['*']);

        $response = $this->postJson("/api/templates/{$this->template->id}/versions/{$this->version->id}/override")
            ->assertOk();

        $this->assertTrue($response->json('data.ai_overridden'));

        $this->version->refresh();
        $this->assertTrue($this->version->ai_overridden);
    }

    public function test_override_preserves_ai_remarks(): void
    {
        Sanctum::actingAs($this->lawyer, ['*']);

        $response = $this->postJson("/api/templates/{$this->template->id}/versions/{$this->version->id}/override")
            ->assertOk();

        $remarks = $response->json('data.ai_remarks');
        $this->assertIsArray($remarks);
        $this->assertNotEmpty($remarks);
        $this->assertEquals('grammar', $remarks[0]['type']);
    }

    public function test_override_requires_lawyer_or_admin(): void
    {
        Sanctum::actingAs($this->manager, ['*']);

        $this->postJson("/api/templates/{$this->template->id}/versions/{$this->version->id}/override")
            ->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // GET /versions — list
    // ------------------------------------------------------------------

    public function test_versions_list_returns_all_versions(): void
    {
        // Create a second version
        TemplateVersion::factory()->create([
            'template_id' => $this->template->id,
            'version_number' => 2,
        ]);

        Sanctum::actingAs($this->manager, ['*']);

        $response = $this->getJson("/api/templates/{$this->template->id}/versions")
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->assertCount(2, $response->json('data'));
    }

    public function test_versions_list_ordered_desc(): void
    {
        TemplateVersion::factory()->create([
            'template_id' => $this->template->id,
            'version_number' => 2,
        ]);

        Sanctum::actingAs($this->manager, ['*']);

        $response = $this->getJson("/api/templates/{$this->template->id}/versions")
            ->assertOk();

        $numbers = array_column($response->json('data'), 'version_number');
        $this->assertEquals([2, 1], array_values($numbers));
    }

    // ------------------------------------------------------------------
    // GET /versions/{version} — single (polling)
    // ------------------------------------------------------------------

    public function test_versions_show_returns_single(): void
    {
        Sanctum::actingAs($this->manager, ['*']);

        $response = $this->getJson("/api/templates/{$this->template->id}/versions/{$this->version->id}")
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'id',
                'template_id',
                'version_number',
                'ai_check_status',
                'ai_checked_at',
                'ai_remarks',
                'ai_overridden',
                'pdf_ok',
            ]]);

        $this->assertEquals($this->version->id, $response->json('data.id'));
        $this->assertEquals(AiCheckStatus::Checked->value, $response->json('data.ai_check_status'));
    }

    public function test_versions_show_rejects_cross_template_access(): void
    {
        // Another template with its own version
        $otherTemplate = Template::factory()->docx()->create();
        $otherVersion = TemplateVersion::factory()->create(['template_id' => $otherTemplate->id]);

        Sanctum::actingAs($this->manager, ['*']);

        // Accessing $otherVersion under $this->template — must 404
        $this->getJson("/api/templates/{$this->template->id}/versions/{$otherVersion->id}")
            ->assertStatus(404);
    }
}
