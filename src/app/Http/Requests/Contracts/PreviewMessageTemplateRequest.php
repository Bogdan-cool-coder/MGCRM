<?php

declare(strict_types=1);

namespace App\Http\Requests\Contracts;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/message-templates/{messageTemplate}/preview
 *
 * Accepts a flat map of vars (dot-notation keys → string values).
 * The caller is responsible for building vars from actual models; this
 * endpoint accepts arbitrary test data to verify template rendering.
 */
class PreviewMessageTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Policy checked in controller
    }

    public function rules(): array
    {
        return [
            'vars' => ['present', 'array'],
            'vars.*' => ['nullable', 'string'],
        ];
    }
}
