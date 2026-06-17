<?php

declare(strict_types=1);

namespace App\Http\Requests\Activity;

use Illuminate\Foundation\Http\FormRequest;

class SaveMeetingReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Visibility of the deal is gated in the controller via the deal policy.
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'answers' => ['nullable', 'array'],
            'answers.*.question_id' => ['required', 'integer'],
            'answers.*.text' => ['nullable', 'string'],
            'answers.*.answer' => ['nullable', 'string'],
            'comment' => ['nullable', 'string'],
            'activity_id' => ['nullable', 'integer', 'exists:activities,id'],
            // FTM flags optionally accompany a meeting report.
            'is_first_time_meeting' => ['nullable', 'boolean'],
            'ftm_decision_maker_attended' => ['nullable', 'boolean'],
            'ftm_presentation_shown' => ['nullable', 'boolean'],
            'ftm_report_url' => ['nullable', 'string'],
        ];
    }
}
