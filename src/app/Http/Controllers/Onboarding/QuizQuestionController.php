<?php

declare(strict_types=1);

namespace App\Http\Controllers\Onboarding;

use App\Domain\Onboarding\Models\Quiz;
use App\Domain\Onboarding\Models\QuizQuestion;
use App\Domain\Onboarding\Services\QuizQuestionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\ReorderRequest;
use App\Http\Requests\Onboarding\StoreQuizQuestionRequest;
use App\Http\Requests\Onboarding\UpdateQuizQuestionRequest;
use App\Http\Resources\Onboarding\QuizQuestionAdminResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class QuizQuestionController extends Controller
{
    public function __construct(
        private readonly QuizQuestionService $service,
    ) {}

    /**
     * GET /api/admin/onboarding/quizzes/{quiz}/questions
     */
    public function index(Request $request, Quiz $quiz): AnonymousResourceCollection
    {
        $this->authorize('viewAny', QuizQuestion::class);

        return QuizQuestionAdminResource::collection(
            $this->service->listByQuiz($quiz)
        );
    }

    /**
     * POST /api/admin/onboarding/quizzes/{quiz}/questions
     */
    public function store(StoreQuizQuestionRequest $request, Quiz $quiz): JsonResponse
    {
        $question = $this->service->create($quiz, $request->validated());

        return QuizQuestionAdminResource::make($question)->response()->setStatusCode(201);
    }

    /**
     * PATCH /api/admin/onboarding/quiz-questions/{question}
     * PATCH /api/admin/onboarding/quizzes/{quiz}/questions/{question}  (FE nested path)
     *
     * The nullable $quiz parameter absorbs the {quiz} segment on the nested route
     * without breaking the shallow route (where {quiz} is absent).
     */
    public function update(UpdateQuizQuestionRequest $request, ?Quiz $quiz = null, QuizQuestion $question): JsonResource
    {
        return QuizQuestionAdminResource::make(
            $this->service->update($question, $request->validated())
        );
    }

    /**
     * DELETE /api/admin/onboarding/quiz-questions/{question}
     * DELETE /api/admin/onboarding/quizzes/{quiz}/questions/{question}  (FE nested path)
     */
    public function destroy(Request $request, ?Quiz $quiz = null, QuizQuestion $question): JsonResponse
    {
        $this->authorize('delete', $question);

        $this->service->delete($question);

        return response()->json(null, 204);
    }

    /**
     * POST /api/admin/onboarding/quizzes/{quiz}/questions/reorder
     * Reuses generic ReorderRequest (order[].id).
     */
    public function reorder(ReorderRequest $request, Quiz $quiz): AnonymousResourceCollection
    {
        $this->authorize('update', $quiz);

        return QuizQuestionAdminResource::collection(
            $this->service->reorder($quiz, $request->validated('order'))
        );
    }
}
