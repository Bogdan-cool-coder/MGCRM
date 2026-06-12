<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Domain\Sales\Models\LostReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLostReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('lostReason'));
    }

    public function rules(): array
    {
        /** @var LostReason $lostReason */
        $lostReason = $this->route('lostReason');

        return [
            'name' => [
                'sometimes',
                'string',
                'max:128',
                Rule::unique('lost_reasons', 'name')->ignore($lostReason->id),
            ],
            'sort_order' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
