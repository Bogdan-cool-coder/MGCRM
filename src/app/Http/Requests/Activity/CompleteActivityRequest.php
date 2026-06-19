<?php

declare(strict_types=1);

namespace App\Http\Requests\Activity;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Marking a task done from the list ("Добавить результат"): an optional
 * result_text is saved onto the activity in the same write. Authorisation
 * mirrors the controller's complete policy gate.
 */
class CompleteActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('complete', $this->route('activity'));
    }

    public function rules(): array
    {
        return [
            'result_text' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
