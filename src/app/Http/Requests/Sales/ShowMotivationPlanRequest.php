<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/admin/motivation/plans/{userId}/{year}/{month} (contract §6.2).
 * authorize() is true — the route is gated by `can:motivation.manage` middleware.
 */
class ShowMotivationPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
