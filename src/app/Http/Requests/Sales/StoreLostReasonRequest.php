<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Domain\Sales\Models\LostReason;
use Illuminate\Foundation\Http\FormRequest;

class StoreLostReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', LostReason::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:128', 'unique:lost_reasons,name'],
            'sort_order' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
