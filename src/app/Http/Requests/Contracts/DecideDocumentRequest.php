<?php

declare(strict_types=1);

namespace App\Http\Requests\Contracts;

use App\Domain\Contracts\Enums\ApprovalDecision;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DecideDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Policy checked in controller via authorize()
    }

    public function rules(): array
    {
        return [
            'decision' => [
                'required',
                'string',
                Rule::in([
                    ApprovalDecision::Approved->value,
                    ApprovalDecision::Rejected->value,
                    ApprovalDecision::NeedsRework->value,
                ]),
            ],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $decision = $this->input('decision');
            $comment = trim((string) $this->input('comment', ''));

            if (
                in_array($decision, [ApprovalDecision::Rejected->value, ApprovalDecision::NeedsRework->value], strict: true)
                && $comment === ''
            ) {
                $v->errors()->add('comment', 'Комментарий обязателен при отклонении или возврате на доработку.');
            }
        });
    }
}
