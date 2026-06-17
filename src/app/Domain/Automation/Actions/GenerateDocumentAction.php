<?php

declare(strict_types=1);

namespace App\Domain\Automation\Actions;

use App\Domain\Automation\Data\ActionPreview;
use App\Domain\Automation\Data\ActionResult;
use App\Domain\Automation\Enums\ActionKind;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Contracts\Services\ContractGenerationService;
use App\Domain\Contracts\Services\DocumentService;
use App\Domain\Sales\Models\Deal;
use Illuminate\Validation\ValidationException;

/**
 * generate_document — generate a contract document for the deal from a template.
 *
 * Delegates to contract-specialist's DocumentService::generateByTemplateCode().
 * ContractGenerationService is passed as a METHOD argument (not constructor-
 * injected into DocumentService) to avoid a circular DI chain — both are
 * resolved here from the container.
 *
 * A ValidationException (e.g. city missing, status not editable) is a soft
 * `skipped` (the automation could not produce a document under the current deal
 * state — not a system fault). A transport failure (Gotenberg 503/502) bubbles
 * up to the dispatcher as `failed` so it can be retried.
 *
 * config: { template_code: string, product_code?, country_code?, city?, currency? }
 */
final class GenerateDocumentAction implements ActionHandler
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly ContractGenerationService $generation,
    ) {}

    public function kind(): ActionKind
    {
        return ActionKind::GenerateDocument;
    }

    public function execute(PipelineAutomation $automation, Deal $target, array $config): ActionResult
    {
        $templateCode = isset($config['template_code']) ? (string) $config['template_code'] : '';
        if ($templateCode === '') {
            return ActionResult::skipped('template_code is not set.');
        }

        $opts = $this->opts($config);
        $actorId = (int) ($automation->created_by_user_id ?? $target->owner_user_id ?? 0);

        try {
            $document = $this->documents->generateByTemplateCode(
                deal: $target,
                templateCode: $templateCode,
                generationService: $this->generation,
                opts: $opts,
                actorUserId: $actorId,
            );
        } catch (ValidationException $e) {
            // Business-rule violation under the current deal state — soft skip.
            return ActionResult::skipped(
                'Document not generated: '.implode(' ', $e->validator->errors()->all()),
                ['template_code' => $templateCode],
            );
        }

        return ActionResult::success("Generated document #{$document->id}", [
            'template_code' => $templateCode,
            'document_id' => $document->id,
        ]);
    }

    public function dryRun(PipelineAutomation $automation, Deal $target, array $config): ActionPreview
    {
        $templateCode = isset($config['template_code']) ? (string) $config['template_code'] : '';

        if ($templateCode === '') {
            return ActionPreview::wont('template_code is not set.');
        }

        return ActionPreview::will("Would generate document from template '{$templateCode}'", [
            'generate_document' => [
                'template_code' => $templateCode,
                'opts' => $this->opts($config),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function opts(array $config): array
    {
        return array_filter([
            'product_code' => $config['product_code'] ?? null,
            'country_code' => $config['country_code'] ?? null,
            'city' => $config['city'] ?? null,
            'currency' => $config['currency'] ?? null,
        ], static fn ($v): bool => $v !== null);
    }
}
