<?php

declare(strict_types=1);

namespace App\Http\Controllers\Onboarding;

use App\Domain\Onboarding\Models\Quiz;
use App\Domain\Onboarding\Models\QuizOption;
use App\Domain\Onboarding\Models\QuizQuestion;
use App\Domain\Onboarding\Services\QuizOptionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\ReorderRequest;
use App\Http\Requests\Onboarding\StoreQuizOptionRequest;
use App\Http\Requests\Onboarding\UpdateQuizOptionRequest;
use App\Http\Resources\Onboarding\QuizOptionAdminResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class QuizOptionController extends Controller
{
    public function __construct(
        private readonly QuizOptionService $service,
    ) {}

    /**
     * GET /api/admin/onboarding/quiz-questions/{question}/options
     * GET /api/admin/onboarding/quizzes/{quiz}/questions/{question}/options (FE nested path)
     */
    public function index(Request $request, ?Quiz $quiz, QuizQuestion $question): AnonymousResourceCollection
    {
        $this->authorize('viewAny', QuizOption::class);

        return QuizOptionAdminResource::collection(
            $this->service->listByQuestion($question)
        );
    }

    /**
     * POST /api/admin/onboarding/quiz-questions/{question}/options
     * POST /api/admin/onboarding/quizzes/{quiz}/questions/{question}/options (FE nested path)
     */
    public function store(StoreQuizOptionRequest $request, ?Quiz $quiz, QuizQuestion $question): JsonResponse
    {
        $option = $this->service->create($question, $request->validated());

        return QuizOptionAdminResource::make($option)->response()->setStatusCode(201);
    }

    /**
     * PATCH /api/admin/onboarding/quiz-options/{option}
     * PATCH /api/admin/onboarding/quizzes/{quiz}/questions/{question}/options/{option} (FE nested path)
     */
    public function update(UpdateQuizOptionRequest $request, ?Quiz $quiz, ?QuizQuestion $question, QuizOption $option): JsonResource
    {
        return QuizOptionAdminResource::make(
            $this->service->update($option, $request->validated())
        );
    }

    /**
     * DELETE /api/admin/onboarding/quiz-options/{option}
     * DELETE /api/admin/onboarding/quizzes/{quiz}/questions/{question}/options/{option} (FE nested path)
     */
    public function destroy(Request $request, ?Quiz $quiz, ?QuizQuestion $question, QuizOption $option): JsonResponse
    {
        $this->authorize('delete', $option);

        $this->service->delete($option);

        return response()->json(null, 204);
    }

    /**
     * POST /api/admin/onboarding/quiz-questions/{question}/options/reorder
     * POST /api/admin/onboarding/quizzes/{quiz}/questions/{question}/options/reorder (FE nested path)
     */
    public function reorder(ReorderRequest $request, ?Quiz $quiz, QuizQuestion $question): AnonymousResourceCollection
    {
        $this->authorize('update', $question);

        return QuizOptionAdminResource::collection(
            $this->service->reorder($question, $request->validated('order'))
        );
    }
}
