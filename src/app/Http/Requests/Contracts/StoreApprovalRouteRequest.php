<?php

declare(strict_types=1);

namespace App\Http\Requests\Contracts;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApprovalRouteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Policy checked in controller via authorize()
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'document_kind' => ['required', 'string', Rule::in(['contract', 'invoice', 'act', 'reconciliation'])],
            'template_id' => ['nullable', 'integer', 'exists:templates,id'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
            'stages' => ['required', 'array', 'min:1'],
            'stages.*.order' => ['required', 'integer', 'min:1'],
            'stages.*.name' => ['required', 'string', 'max:255'],
            'stages.*.user_ids' => ['required', 'array', 'min:1'],
            'stages.*.user_ids.*' => ['required', 'integer', Rule::exists('users', 'id')],
            'stages.*.min_required' => ['required', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $stages = $this->input('stages', []);

            // min_required <= count(user_ids) for each stage
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

            // stage orders must be unique within the route
            $orders = array_column($stages, 'order');
            if (count($orders) !== count(array_unique($orders))) {
                $v->errors()->add('stages', 'Порядковые номера этапов должны быть уникальны.');
            }
        });
    }
}
