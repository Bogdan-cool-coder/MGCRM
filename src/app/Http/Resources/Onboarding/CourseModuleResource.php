<?php

declare(strict_types=1);

namespace App\Http\Resources\Onboarding;

use App\Domain\Onboarding\Models\CourseModule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CourseModule */
class CourseModuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'course_id' => $this->course_id,
            'title' => $this->title,
            'sort_order' => $this->sort_order,

            'lessons_count' => $this->whenCounted('lessons'),
            'lessons' => LessonResource::collection($this->whenLoaded('lessons')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
