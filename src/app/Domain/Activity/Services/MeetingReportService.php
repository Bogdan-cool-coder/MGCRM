<?php

declare(strict_types=1);

namespace App\Domain\Activity\Services;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Enums\ActivityTargetType;
use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Events\ActivityCreated;
use App\Domain\Activity\Models\Activity;
use App\Domain\Activity\Models\MeetingReportQuestion;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * MeetingReportService — the meeting-report constructor (E8). Builds the active
 * question registry (global + the deal's pipeline) and saves answers as a
 * snapshot into a meeting-kind Activity on the deal.
 */
class MeetingReportService
{
    /**
     * Active questions for a pipeline form: global (pipeline_id NULL) plus the
     * pipeline's own questions, ordered.
     *
     * @return Collection<int, MeetingReportQuestion>
     */
    public function questions(?int $pipelineId): Collection
    {
        return MeetingReportQuestion::query()
            ->with('options')
            ->where('is_active', true)
            ->where(function ($q) use ($pipelineId): void {
                $q->whereNull('pipeline_id');
                if ($pipelineId !== null) {
                    $q->orWhere('pipeline_id', $pipelineId);
                }
            })
            ->orderByRaw('pipeline_id is null desc')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Save a meeting report for a deal (E8). Validates every answer.question_id
     * against the active registry (global + deal's pipeline); rejects an empty
     * report (no answers and no comment). Creates a new meeting activity or
     * updates the existing one when activity_id is supplied.
     *
     * @param  array{answers?: list<array<string, mixed>>, comment?: string|null, activity_id?: int|null}  $data
     */
    public function saveReport(Deal $deal, array $data, User $user): Activity
    {
        $answers = $data['answers'] ?? [];
        $comment = isset($data['comment']) ? trim((string) $data['comment']) : '';
        $comment = $comment !== '' ? $comment : null;

        if ($answers === [] && $comment === null) {
            throw ValidationException::withMessages([
                'comment' => 'The report is empty: answer questions or add a comment.',
            ]);
        }

        $validIds = $this->questions($deal->pipeline_id)->pluck('id')->all();

        foreach ($answers as $answer) {
            $qid = isset($answer['question_id']) ? (int) $answer['question_id'] : null;

            if ($qid === null) {
                throw ValidationException::withMessages([
                    'answers' => 'Each answer must contain a question_id.',
                ]);
            }

            if ($validIds !== [] && ! in_array($qid, $validIds, true)) {
                throw ValidationException::withMessages([
                    'answers' => "Question {$qid} does not exist or is inactive.",
                ]);
            }
        }

        $reportJson = ['answers' => $answers, 'comment' => $comment];
        $body = $this->buildBody($answers, $comment);

        $activityId = isset($data['activity_id']) ? (int) $data['activity_id'] : null;

        if ($activityId !== null) {
            $activity = Activity::query()
                ->where('id', $activityId)
                ->where('target_type', ActivityTargetType::Deal->value)
                ->where('target_id', $deal->id)
                ->first();

            if ($activity === null) {
                throw ValidationException::withMessages([
                    'activity_id' => "Activity {$activityId} was not found for this deal.",
                ]);
            }

            $activity->update([
                'meeting_report_json' => $reportJson,
                'body' => $body,
            ]);
            $activity->refresh();

            return $activity;
        }

        $activity = Activity::create([
            'kind' => ActivityType::Meeting->value,
            'target_type' => ActivityTargetType::Deal->value,
            'target_id' => $deal->id,
            'title' => "Отчёт о встрече — {$deal->title}",
            'body' => $body,
            'created_by_id' => $user->id,
            'responsible_id' => $user->id,
            'status' => ActivityStatus::Done->value,
            'completed_at' => now(),
            'completed_by_id' => $user->id,
            'progress_pct' => 100,
            'meeting_report_json' => $reportJson,
            'department_id' => $deal->department_id,
        ]);

        ActivityCreated::dispatch($activity);

        return $activity;
    }

    /**
     * Build the timeline body: comment first, then a one-line summary per answer.
     *
     * @param  list<array<string, mixed>>  $answers
     */
    private function buildBody(array $answers, ?string $comment): ?string
    {
        $lines = [];

        if ($comment !== null) {
            $lines[] = $comment;
        }

        foreach ($answers as $answer) {
            $label = $answer['text'] ?? $answer['question'] ?? ('Вопрос '.($answer['question_id'] ?? '?'));
            $value = $answer['answer'] ?? '';
            $lines[] = "{$label}: {$value}";
        }

        return $lines === [] ? null : implode("\n", $lines);
    }
}
