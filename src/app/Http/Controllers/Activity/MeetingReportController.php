<?php

declare(strict_types=1);

namespace App\Http\Controllers\Activity;

use App\Domain\Activity\Services\MeetingReportService;
use App\Domain\Sales\Models\Deal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Activity\SaveMeetingReportRequest;
use App\Http\Resources\Activity\ActivityResource;
use App\Http\Resources\Activity\MeetingReportQuestionResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Thin meeting-report controller. The form-question registry is public to any
 * authenticated user; saving a report is gated on the deal's view policy.
 */
class MeetingReportController extends Controller
{
    public function __construct(
        private readonly MeetingReportService $service,
    ) {}

    public function questions(Request $request): AnonymousResourceCollection
    {
        $pipelineId = $request->filled('pipeline_id')
            ? (int) $request->query('pipeline_id')
            : null;

        return MeetingReportQuestionResource::collection(
            $this->service->questions($pipelineId)
        );
    }

    public function save(SaveMeetingReportRequest $request, Deal $deal): JsonResource
    {
        // Writing a meeting report into a deal requires access to that deal.
        $this->authorize('view', $deal);

        $activity = $this->service->saveReport($deal, $request->validated(), $request->user());

        return ActivityResource::make(
            $activity->load(['responsible:id,full_name', 'createdBy:id,full_name'])
        );
    }
}
