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
            'attempt' => $this->attempt,
            'context_snapshot' => $this->context_snapshot ?? [],
            'template_version' => $this->template_version,
            'docx_path' => $this->docx_path,
            'pdf_path' => $this->pdf_path,
            'note' => $this->note,
            'created_by_user_id' => $this->created_by_user_id,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
