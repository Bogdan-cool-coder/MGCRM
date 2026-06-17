<?php

declare(strict_types=1);

namespace App\Http\Resources\Onboarding;

use App\Domain\Onboarding\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Course */
class CourseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'cover_image_path' => $this->cover_image_path,
            'is_published' => $this->is_published,
            'passing_score_pct' => $this->passing_score_pct,
            'completion_policy' => $this->completion_policy?->value,
            'deadline_days' => $this->deadline_days,
            'sort_order' => $this->sort_order,
            'created_by_user_id' => $this->created_by_user_id,

            'modules_count' => $this->whenCounted('modules'),
            'modules' => CourseModuleResource::collection($this->whenLoaded('modules')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
