<?php

declare(strict_types=1);

namespace App\Http\Controllers\Activity;

use App\Domain\Activity\Models\MeetingReportQuestion;
use App\Domain\Activity\Services\MeetingReportQuestionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Activity\StoreMeetingReportQuestionRequest;
use App\Http\Requests\Activity\UpdateMeetingReportQuestionRequest;
use App\Http\Resources\Activity\MeetingReportQuestionResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;

/**
 * Admin CRUD for the meeting-report question registry (admin/director).
 */
class MeetingReportQuestionController extends Controller
{
    public function __construct(
        private readonly MeetingReportQuestionService $service,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', MeetingReportQuestion::class);

        $pipelineId = $request->filled('pipeline_id')
            ? (int) $request->query('pipeline_id')
            : null;

        return MeetingReportQuestionResource::collection(
            $this->service->all($pipelineId)
        );
    }

    public function store(StoreMeetingReportQuestionRequest $request): JsonResource
    {
        $question = $this->service->create($request->validated());

        return MeetingReportQuestionResource::make($question->load('options'));
    }

    public function update(UpdateMeetingReportQuestionRequest $request, MeetingReportQuestion $question): JsonResource
    {
        $updated = $this->service->update($question, $request->validated());

        return MeetingReportQuestionResource::make($updated->load('options'));
    }

    public function destroy(Request $request, MeetingReportQuestion $question): Response
    {
        $this->authorize('delete', $question);

        $this->service->delete($question);

        return response()->noContent();
    }
}
