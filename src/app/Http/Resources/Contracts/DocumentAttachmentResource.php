<?php

declare(strict_types=1);

namespace App\Http\Resources\Contracts;

use App\Domain\Contracts\Models\DocumentAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DocumentAttachment
 */
class DocumentAttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_id' => $this->document_id,
            'kind' => $this->kind?->value,
            'path' => $this->path,
            'original_name' => $this->original_name,
            'content_type' => $this->content_type,
            'size' => $this->size_bytes,
            'uploaded_by' => $this->uploaded_by_user_id,
            'uploaded_by_name' => $this->whenLoaded('uploadedBy', fn () => $this->uploadedBy?->full_name),
            'created_at' => $this->created_at?->toISOString(),
            'download_url' => route('documents.attachments.download', [
                $this->document_id,
                $this->id,
            ]),
        ];
    }
}
