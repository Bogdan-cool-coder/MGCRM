<?php

declare(strict_types=1);

namespace App\Http\Resources\Activity;

use App\Domain\Activity\Models\MeetingReportQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MeetingReportQuestion */
class MeetingReportQuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pipeline_id' => $this->pipeline_id,
            'text' => $this->text,
            'kind' => $this->kind,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'options' => $this->whenLoaded('options', fn () => $this->options->map(static fn ($o): array => [
                'id' => $o->id,
                'text' => $o->text,
                'sort_order' => $o->sort_order,
            ])->all()),
        ];
    }
}
