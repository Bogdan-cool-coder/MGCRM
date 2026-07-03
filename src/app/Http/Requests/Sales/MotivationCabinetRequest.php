<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/motivation/cards/me (contract §6.1). Auth enforced by middleware;
 * visibility (own vs subordinate vs any) resolved in MotivationCardPolicy::view.
 */
class MotivationCabinetRequest extends FormRequest
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
            'year' => ['required', 'integer', 'between:2020,2100'],
            'month' => ['required', 'integer', 'between:1,12'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
