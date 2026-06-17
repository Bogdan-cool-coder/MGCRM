<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use App\Domain\Onboarding\Models\CourseModule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCourseModuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', CourseModule::class);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
        ];
    }
}
