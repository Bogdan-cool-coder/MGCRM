<?php

declare(strict_types=1);

namespace App\Http\Resources\Contracts;

use App\Domain\Contracts\Models\DocumentRevision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DocumentRevision
 */
class DocumentRevisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_id' => $this->document_id,
            'version_number' => $this->version_number,
            // FE alias: DocumentRevisionsTab reads `version`
            'version' => $this->version_number,
            'attempt' => $this->attempt,
            'context_snapshot' => $this->context_snapshot ?? [],
            'template_version' => $this->template_version,
            // Raw storage paths — kept for reference; FE must use /download/* API endpoints.
            'docx_path' => $this->docx_path,
            'pdf_path' => $this->pdf_path,
            'note' => $this->note,
            'created_by_user_id' => $this->created_by_user_id,
            // FE alias: DocumentRevisionsTab reads `created_by_name`
            'created_by_name' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->full_name),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
