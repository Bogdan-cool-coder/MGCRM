<?php

declare(strict_types=1);

namespace App\Http\Resources\Onboarding;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource for the HR-dashboard summary endpoint (S3.7).
 *
 * Wraps KPI counters + 2 ECharts payloads (pie + horizontal bar) per §В3.
 */
class HrProgressSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->resource;

        return [
            'kpi' => $data['kpi'],
            'status_chart' => $data['status_chart'],
            'top_courses_chart' => $data['top_courses_chart'],
        ];
    }
}
