<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class MoveDealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('move', $this->route('deal'));
    }

    public function rules(): array
    {
        return [
            'to_stage_id' => ['required', 'integer', 'exists:pipeline_stages,id'],
            'lost_reason' => ['nullable', 'string', 'max:1000'],
            'lost_reason_id' => ['nullable', 'integer', 'exists:lost_reasons,id'],
            // won-gate is evaluated in DealMoveService (soft warning in S1.3).
        ];
    }
}
