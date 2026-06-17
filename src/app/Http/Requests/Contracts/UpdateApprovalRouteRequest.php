<?php

declare(strict_types=1);

namespace App\Http\Requests\Contracts;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateApprovalRouteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Policy checked in controller via authorize()
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'document_kind' => ['sometimes', 'string', Rule::in(['contract', 'invoice', 'act', 'reconciliation'])],
            'template_id' => ['nullable', 'integer', 'exists:templates,id'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'stages' => ['sometimes', 'array', 'min:1'],
            'stages.*.order' => ['required_with:stages', 'integer', 'min:1'],
            'stages.*.name' => ['required_with:stages', 'string', 'max:255'],
            'stages.*.user_ids' => ['required_with:stages', 'array', 'min:1'],
            'stages.*.user_ids.*' => ['required', 'integer', Rule::exists('users', 'id')],
            'stages.*.min_required' => ['required_with:stages', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $stages = $this->input('stages');
            if (! is_array($stages)) {
                return;
            }

            foreach ($stages as $i => $stage) {
                $userIds = $stage['user_ids'] ?? [];
                $minRequired = $stage['min_required'] ?? 1;
                if (is_array($userIds) && (int) $minRequired > count($userIds)) {
                    $v->errors()->add(
                        "stages.{$i}.min_required",
                        'min_required не может превышать количество согласантов в этапе.'
                    );
                }
            }

            $orders = array_column($stages, 'order');
            if (count($orders) !== count(array_unique($orders))) {
                $v->errors()->add('stages', 'Порядковые номера этапов должны быть уникальны.');
            }
        });
    }
}
