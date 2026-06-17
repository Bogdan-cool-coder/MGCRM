<?php

declare(strict_types=1);

namespace App\Http\Requests\Activity;

use App\Domain\Activity\Enums\ActivityStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('changeStatus', $this->route('activity'));
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(ActivityStatus::values())],
            'result_text' => ['nullable', 'string'],
            'is_closed' => ['nullable', 'boolean'],
        ];
    }
}
