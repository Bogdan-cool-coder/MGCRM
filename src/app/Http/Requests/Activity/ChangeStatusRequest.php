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
        // is_closed is intentionally NOT validated here: it is server-derived by
        // the status machine (changeStatus()/complete()) from the target status,
        // never accepted from the client. The request contract is exactly what the
        // service consumes — status (+ optional result_text) (D14).
        return [
            'status' => ['required', 'string', Rule::in(ActivityStatus::values())],
            'result_text' => ['nullable', 'string'],
        ];
    }
}
