<?php

declare(strict_types=1);

namespace App\Http\Resources\Contracts;

use App\Domain\Contracts\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Document
 */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind?->value,
            'number' => $this->number,
            'title' => $this->title,
            'product_code' => $this->product_code,
            'country_code' => $this->country_code,
            'city' => $this->city,
            'city_code' => $this->city_code,
            'status' => $this->status?->value,
            'currency' => $this->currency,

            // Money (kopecks) — raw integers for client-side formatting
            'subtotal' => $this->subtotal,
            'discount_pct' => $this->discount_pct,
            'discount_amount' => $this->discount_amount,
            'total' => $this->total,
            'total_rub' => $this->total_rub,
            'fx_rate' => $this->fx_rate,
            'fx_rate_date' => $this->fx_rate_date?->toDateString(),

            // Context JSONB
            'context' => $this->context ?? [],
            'extra_fields' => $this->extra_fields ?? [],

            // Template / file references
            'template_version' => $this->template_version,
            'docx_path' => $this->docx_path,
            'pdf_path' => $this->pdf_path,

            // Drive links (M11)
            'drive_folder_url' => $this->drive_folder_url,
            'drive_docx_url' => $this->drive_docx_url,
            'drive_pdf_url' => $this->drive_pdf_url,

            // Cross-domain references
            'source_deal_id' => $this->source_deal_id,
            'source_company_id' => $this->source_company_id,
            'author_user_id' => $this->author_user_id,

            // Timestamps
            'signed_at' => $this->signed_at?->toISOString(),
            'archived_at' => $this->archived_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),

            // Conditional relations
            'items' => DocumentItemResource::collection($this->whenLoaded('items')),
            'revisions' => DocumentRevisionResource::collection($this->whenLoaded('revisions')),
            'attachments' => DocumentAttachmentResource::collection($this->whenLoaded('attachments')),
            'remarks' => DocumentRemarkResource::collection($this->whenLoaded('remarks')),
        ];
    }
}
