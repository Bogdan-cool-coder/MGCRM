<?php

declare(strict_types=1);

namespace App\Domain\Activity\Services;

use App\Domain\Activity\Models\MeetingReportQuestion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * MeetingReportQuestionService — admin CRUD for the question registry. Options
 * are managed inline with their question in a single transaction.
 */
class MeetingReportQuestionService
{
    /**
     * Full registry for the admin editor (no is_active filter): global plus, if
     * given, a specific pipeline.
     *
     * @return Collection<int, MeetingReportQuestion>
     */
    public function all(?int $pipelineId): Collection
    {
        return MeetingReportQuestion::query()
            ->with('options')
            ->when($pipelineId !== null, fn ($q) => $q->where(function ($inner) use ($pipelineId): void {
                $inner->whereNull('pipeline_id')->orWhere('pipeline_id', $pipelineId);
            }))
            ->orderByRaw('pipeline_id is null desc')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): MeetingReportQuestion
    {
        return DB::transaction(function () use ($data): MeetingReportQuestion {
            $options = $data['options'] ?? [];
            unset($data['options']);

            $question = MeetingReportQuestion::create($data);

            $this->syncOptions($question, $options);

            return $question;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(MeetingReportQuestion $question, array $data): MeetingReportQuestion
    {
        return DB::transaction(function () use ($question, $data): MeetingReportQuestion {
            $options = $data['options'] ?? null;
            unset($data['options']);

            $question->update($data);

            if ($options !== null) {
                $question->options()->delete();
                $this->syncOptions($question, $options);
            }

            $question->refresh();

            return $question;
        });
    }

    public function delete(MeetingReportQuestion $question): void
    {
        // options cascade via FK
        $question->delete();
    }

    /**
     * @param  list<array<string, mixed>>  $options
     */
    private function syncOptions(MeetingReportQuestion $question, array $options): void
    {
        foreach ($options as $index => $option) {
            $question->options()->create([
                'text' => $option['text'],
                'sort_order' => $option['sort_order'] ?? ($index + 1),
            ]);
        }
    }
}
