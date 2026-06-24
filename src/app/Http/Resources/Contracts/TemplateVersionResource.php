<?php

declare(strict_types=1);

namespace App\Http\Resources\Contracts;

use App\Domain\Contracts\Models\TemplateVersion;
use App\Domain\Iam\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TemplateVersion
 */
class TemplateVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var User|null $author */
        $author = $this->whenLoaded('createdBy');

        return [
            'id' => $this->id,
            'template_id' => $this->template_id,
            'version_number' => $this->version_number,
            'docx_path' => $this->docx_path,
            'ai_check_status' => $this->ai_check_status?->value,
            'ai_checked_at' => $this->ai_checked_at,
            'ai_remarks' => $this->ai_remarks,
            'ai_overridden' => $this->ai_overridden,
            'pdf_ok' => $this->pdf_ok,
            'created_by_user_id' => $this->created_by_user_id,
            'created_by_name' => $author instanceof User ? $author->full_name : null,
            'created_at' => $this->created_at,
        ];
    }
}
