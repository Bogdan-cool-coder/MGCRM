<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Actions;

use App\Domain\Automation\Actions\GenerateDocumentAction;
use App\Domain\Automation\Enums\ActionKind;
use App\Domain\Automation\Enums\ActionStatus;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Services\ContractGenerationService;
use App\Domain\Contracts\Services\DocumentService;
use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class GenerateDocumentActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_kind(): void
    {
        $action = new GenerateDocumentAction(
            Mockery::mock(DocumentService::class),
            Mockery::mock(ContractGenerationService::class),
        );

        $this->assertSame(ActionKind::GenerateDocument, $action->kind());
    }

    public function test_execute_delegates_to_document_service(): void
    {
        $deal = Deal::factory()->create();
        $automation = PipelineAutomation::factory()->create(['created_by_user_id' => null]);

        $document = new Document;
        $document->id = 321;

        $documents = Mockery::mock(DocumentService::class);
        $generation = Mockery::mock(ContractGenerationService::class);

        $documents->shouldReceive('generateByTemplateCode')
            ->once()
            ->with(Mockery::on(fn ($arg): bool => $arg instanceof Deal && $arg->id === $deal->id),
                'master_skeleton',
                $generation,
                Mockery::on(fn ($opts): bool => ($opts['product_code'] ?? null) === 'macrocrm'),
                Mockery::type('int'),
            )
            ->andReturn($document);

        $action = new GenerateDocumentAction($documents, $generation);

        $result = $action->execute($automation, $deal, [
            'template_code' => 'master_skeleton',
            'product_code' => 'macrocrm',
        ]);

        $this->assertSame(ActionStatus::Success, $result->status);
        $this->assertSame(321, $result->data['document_id']);
    }

    public function test_execute_skips_without_template_code(): void
    {
        $deal = Deal::factory()->create();
        $automation = PipelineAutomation::factory()->create();

        $documents = Mockery::mock(DocumentService::class);
        $documents->shouldNotReceive('generateByTemplateCode');

        $action = new GenerateDocumentAction($documents, Mockery::mock(ContractGenerationService::class));

        $result = $action->execute($automation, $deal, []);

        $this->assertSame(ActionStatus::Skipped, $result->status);
    }

    public function test_execute_soft_skips_on_validation_exception(): void
    {
        $deal = Deal::factory()->create();
        $automation = PipelineAutomation::factory()->create();

        $documents = Mockery::mock(DocumentService::class);
        $generation = Mockery::mock(ContractGenerationService::class);
        $documents->shouldReceive('generateByTemplateCode')
            ->once()
            ->andThrow(ValidationException::withMessages(['city' => 'City is required.']));

        $action = new GenerateDocumentAction($documents, $generation);

        $result = $action->execute($automation, $deal, ['template_code' => 'x']);

        // A business-rule failure under the current deal state is a soft skip,
        // not a hard run failure.
        $this->assertSame(ActionStatus::Skipped, $result->status);
        $this->assertStringContainsString('City is required', $result->summary);
    }

    public function test_dry_run_does_not_call_service(): void
    {
        $deal = Deal::factory()->create();
        $automation = PipelineAutomation::factory()->create();

        $documents = Mockery::mock(DocumentService::class);
        $documents->shouldNotReceive('generateByTemplateCode');

        $action = new GenerateDocumentAction($documents, Mockery::mock(ContractGenerationService::class));

        $preview = $action->dryRun($automation, $deal, ['template_code' => 'master_skeleton']);

        $this->assertTrue($preview->wouldExecute);
        $this->assertSame('master_skeleton', $preview->data['generate_document']['template_code']);
    }
}
