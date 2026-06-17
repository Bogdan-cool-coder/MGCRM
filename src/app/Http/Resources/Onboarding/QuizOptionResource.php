<?php

declare(strict_types=1);

namespace App\Http\Resources\Onboarding;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Student-facing option resource — is_correct is hidden.
 * Students must not know correct answers before submit.
 */
class QuizOptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'text' => $this->text,
            'sort_order' => $this->sort_order,
        ];
    }
}
