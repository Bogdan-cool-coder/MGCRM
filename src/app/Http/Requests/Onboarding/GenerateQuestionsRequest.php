<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;

class GenerateQuestionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'desired_count' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ];
    }
}
