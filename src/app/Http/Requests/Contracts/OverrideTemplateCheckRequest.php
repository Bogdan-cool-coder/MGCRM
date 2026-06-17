<?php

declare(strict_types=1);

namespace App\Http\Requests\Contracts;

use Illuminate\Foundation\Http\FormRequest;

/**
 * OverrideTemplateCheckRequest — POST /api/templates/{template}/versions/{version}/override
 *
 * Action endpoint — no body required. Policy check is done in the controller.
 */
class OverrideTemplateCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Policy check is done in the controller via $this->authorize().
        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
