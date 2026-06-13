<?php

declare(strict_types=1);

namespace App\Http\Resources\Onboarding;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Student-facing question resource — hides explanation and is_correct on options.
 * explanation is only revealed in QuizAttemptResultResource (after submit).
 */
class QuizQuestionResource extends JsonResource
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
            // explanation intentionally omitted — shown only after submit
            'options' => QuizOptionResource::collection(
                $this->whenLoaded('options')
            ),
        ];
    }
}
