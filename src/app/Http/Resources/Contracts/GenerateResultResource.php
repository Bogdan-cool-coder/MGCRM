<?php

declare(strict_types=1);

namespace App\Http\Resources\Contracts;

use App\Domain\Contracts\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GenerateResultResource — response for the three generate endpoints.
 *
 * {
 *   "document_id": 42,
 *   "number": "ТШК-220/UZ",
 *   "docx_url": "/api/documents/42/download/docx",
 *   "pdf_url":  "/api/documents/42/download/pdf",
 *   "warnings": []
 * }
 *
 * warnings: ["template_not_checked"] when TemplateVersion.pdf_ok = null/false.
 *
 * @mixin Document
 */
class GenerateResultResource extends JsonResource
{
    /**
     * @param  list<string>  $warnings
     */
    public function __construct(
        mixed $resource,
        private readonly array $warnings = [],
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'document_id' => $this->id,
            'number' => $this->number,
            'docx_url' => $this->docx_path !== null
                ? "/api/documents/{$this->id}/download/docx"
                : null,
            'pdf_url' => $this->pdf_path !== null
                ? "/api/documents/{$this->id}/download/pdf"
                : null,
            'warnings' => $this->warnings,
        ];
    }
}
