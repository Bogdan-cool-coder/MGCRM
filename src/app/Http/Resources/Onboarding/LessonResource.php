<?php

declare(strict_types=1);

namespace App\Http\Resources\Onboarding;

use App\Domain\Onboarding\Models\Lesson;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Lesson */
class LessonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'module_id' => $this->module_id,
            'title' => $this->title,
            'kind' => $this->kind?->value,
            'content' => $this->content,
            'duration_minutes' => $this->duration_minutes,
            'sort_order' => $this->sort_order,
            'is_published' => $this->is_published,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
