<?php

declare(strict_types=1);

namespace App\Http\Resources\Contracts;

use App\Domain\Contracts\Models\DocumentRemark;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DocumentRemark
 */
class DocumentRemarkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_id' => $this->document_id,
            'attempt' => $this->attempt,
            'stage_order' => $this->stage_order,
            'author_user_id' => $this->author_user_id,
            'author' => $this->whenLoaded('author', fn () => [
                'id' => $this->author->id,
                'full_name' => $this->author->full_name,
            ]),
            'text' => $this->text,
            'is_resolved' => $this->is_resolved,
            'resolved_at' => $this->resolved_at?->toISOString(),
            'resolved_by_user_id' => $this->resolved_by_user_id,
            'resolved_by' => $this->whenLoaded('resolvedBy', fn () => $this->resolvedBy ? [
                'id' => $this->resolvedBy->id,
                'full_name' => $this->resolvedBy->full_name,
            ] : null),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
