<?php

declare(strict_types=1);

namespace App\Http\Requests\Iam;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates query params for the colleague directory (GET /api/users).
 *
 * It is a read-only reference list of co-workers used by assign/responsible
 * dropdowns, so any authenticated user may call it (authorization happens in
 * the route middleware). Only the optional ?search= filter is validated.
 */
class UserIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
        ];
    }
}
