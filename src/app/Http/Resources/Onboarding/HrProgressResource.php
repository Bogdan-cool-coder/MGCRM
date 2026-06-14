<?php

declare(strict_types=1);

namespace App\Http\Resources\Onboarding;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource for a single HR-dashboard assignment row.
 *
 * The resource wraps an enriched array (not a Model) produced by
 * OnboardingDashboardService::enrichRow(). $wrap = null avoids double-nesting
 * inside LengthAwarePaginator's collection — the paginator's "data" key wraps.
 *
 * Chart-payload contract (§В3 of the plan).
 */
class HrProgressResource extends JsonResource
{
    /**
     * Disable auto-wrapping: the paginator collection wraps in "data" already.
     */
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // $this->resource is the enriched array from OnboardingDashboardService::enrichRow().
        /** @var array<string, mixed> $row */
        $row = $this->resource;

        return [
            'assignment_id' => $row['id'],
            'user_id' => $row['user']['id'] ?? null,
            'user_name' => $row['user']['name'] ?? null,
            'course_id' => $row['course']['id'] ?? null,
            'course_title' => $row['course']['title'] ?? null,
            'progress_pct' => $row['completion_rate'],
            'status' => $row['status'],
            'due_date' => $row['due_date'],
            'is_overdue' => $row['is_overdue'],
            'avg_quiz_score' => $row['avg_quiz_score'],
            'assigned_at' => $row['assigned_at'],
            'completed_at' => $row['completed_at'],
        ];
    }
}
