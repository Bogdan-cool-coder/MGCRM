<?php

declare(strict_types=1);

namespace App\Http\Resources\Onboarding;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin-facing question resource — includes explanation and is_correct on options.
 */
class QuizQuestionAdminResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'text' => $this->text,
            'kind' => $this->kind,
            'sort_order' => $this->sort_order,
            'points' => $this->points,
            'explanation' => $this->explanation,
            // Exposed for HR-review: AI-generated drafts have is_draft=true until approved.
            'is_draft' => (bool) $this->is_draft,
            'options' => QuizOptionAdminResource::collection(
                $this->whenLoaded('options')
            ),
        ];
    }
}
