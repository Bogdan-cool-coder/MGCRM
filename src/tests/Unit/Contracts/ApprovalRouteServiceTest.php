<?php

declare(strict_types=1);

namespace Tests\Unit\Contracts;

use App\Domain\Contracts\Models\ApprovalRoute;
use App\Domain\Contracts\Models\Template;
use App\Domain\Contracts\Services\ApprovalRouteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ApprovalRouteServiceTest extends TestCase
{
    use RefreshDatabase;

    private ApprovalRouteService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ApprovalRouteService::class);
    }

    public function test_match_by_template_id_exact(): void
    {
        $template = Template::factory()->create();
        $route = ApprovalRoute::factory()->create([
            'document_kind' => 'contract',
            'template_id' => $template->id,
            'is_default' => false,
            'is_active' => true,
            'stages' => [['order' => 1, 'name' => 'Stage 1', 'user_ids' => [999], 'min_required' => 1]],
        ]);

        $matched = $this->service->match('contract', $template->id);

        $this->assertSame($route->id, $matched->id);
    }

    public function test_fallback_to_default_when_no_template_match(): void
    {
        $defaultRoute = ApprovalRoute::factory()->create([
            'document_kind' => 'contract',
            'template_id' => null,
            'is_default' => true,
            'is_active' => true,
            'stages' => [['order' => 1, 'name' => 'Stage 1', 'user_ids' => [999], 'min_required' => 1]],
        ]);

        // Ask for a template_id that does not exist as exact match
        $matched = $this->service->match('contract', 99999);

        $this->assertSame($defaultRoute->id, $matched->id);
    }

    public function test_422_when_no_route_at_all(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->match('contract', null);
    }

    public function test_inactive_route_not_matched(): void
    {
        ApprovalRoute::factory()->create([
            'document_kind' => 'contract',
            'is_default' => true,
            'is_active' => false,
            'stages' => [['order' => 1, 'name' => 'Stage 1', 'user_ids' => [999], 'min_required' => 1]],
        ]);

        $this->expectException(ValidationException::class);
        $this->service->match('contract', null);
    }

    public function test_exact_match_takes_priority_over_default(): void
    {
        $template = Template::factory()->create();
        $exactRoute = ApprovalRoute::factory()->create([
            'document_kind' => 'contract',
            'template_id' => $template->id,
            'is_default' => false,
            'is_active' => true,
            'stages' => [['order' => 1, 'name' => 'Exact', 'user_ids' => [1], 'min_required' => 1]],
        ]);
        ApprovalRoute::factory()->create([
            'document_kind' => 'contract',
            'template_id' => null,
            'is_default' => true,
            'is_active' => true,
            'stages' => [['order' => 1, 'name' => 'Default', 'user_ids' => [2], 'min_required' => 1]],
        ]);

        $matched = $this->service->match('contract', $template->id);

        $this->assertSame($exactRoute->id, $matched->id);
    }
}
