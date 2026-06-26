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
    public function __construct(
        private readonly ActivityService $activityService,
    ) {}

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
     * The four FTM flags (is_first_time_meeting, ftm_decision_maker_attended,
     * ftm_presentation_shown, ftm_report_url) optionally accompany the report and
     * are persisted onto the meeting activity — the constructor is the canonical
     * FTM-capture surface, so all five FTM conditions can actually be satisfied
     * here (KPI cabinet feeds off them). Booleans default to false; the url is
     * trimmed to null when blank.
     *
     * @param  array{answers?: list<array<string, mixed>>, comment?: string|null, activity_id?: int|null, is_first_time_meeting?: bool|null, ftm_decision_maker_attended?: bool|null, ftm_presentation_shown?: bool|null, ftm_report_url?: string|null}  $data
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

            // Update: only overwrite FTM flags when the request carries an FTM
            // block, so a plain report re-save never wipes prior FTM data.
            $activity->update(array_merge([
                'meeting_report_json' => $reportJson,
                'body' => $body,
            ], $this->ftmFields($data, forCreate: false)));
            $activity->refresh();

            return $activity;
        }

        // Create: a fresh meeting is explicitly "not FTM" unless the block says
        // otherwise, so the four flags get concrete false/null defaults.
        $activity = Activity::create(array_merge([
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
            'is_closed' => true,
            'meeting_report_json' => $reportJson,
            'department_id' => $deal->department_id,
        ], $this->ftmFields($data, forCreate: true)));

        // A report logged through the constructor is a completed meeting: stamp
        // engagement on the deal's company/contacts (last_activity_at) and write
        // the meeting_held entity-log, exactly as POST /complete would (E8). The
        // Activity domain owns both side-effects, so we delegate to it rather
        // than duplicate the engagement/entity-log plumbing here.
        $this->activityService->recordCompletedActivitySideEffects($activity, $user);

        // Pass the acting user as the event actor, matching how ActivityService
        // dispatches ActivityCreated (C8). Harmless today — the audit-log listener's
        // onCreated only writes note_added for kind=note, and this is a meeting — but
        // keeps actor attribution consistent for any future ActivityCreated consumer.
        ActivityCreated::dispatch($activity, $user);

        return $activity;
    }

    /**
     * Normalise the four optional FTM fields off the request payload into a
     * persistable shape: booleans default to false, the report url is trimmed to
     * null when blank.
     *
     * On create ($forCreate=true) the flags are always returned (a fresh meeting
     * is concretely "not FTM" unless the block opts in). On update they are only
     * returned when the request actually carries an FTM key, so a plain report
     * re-save never overwrites previously captured FTM data.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function ftmFields(array $data, bool $forCreate): array
    {
        $hasFtm = array_key_exists('is_first_time_meeting', $data)
            || array_key_exists('ftm_decision_maker_attended', $data)
            || array_key_exists('ftm_presentation_shown', $data)
            || array_key_exists('ftm_report_url', $data);

        if (! $forCreate && ! $hasFtm) {
            return [];
        }

        $url = isset($data['ftm_report_url']) ? trim((string) $data['ftm_report_url']) : '';

        return [
            'is_first_time_meeting' => (bool) ($data['is_first_time_meeting'] ?? false),
            'ftm_decision_maker_attended' => (bool) ($data['ftm_decision_maker_attended'] ?? false),
            'ftm_presentation_shown' => (bool) ($data['ftm_presentation_shown'] ?? false),
            'ftm_report_url' => $url !== '' ? $url : null,
        ];
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
