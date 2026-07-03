<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/admin/motivation/plans/{card}/finalize (contract §7.3).
 * No body; authorization delegates to MotivationCardPolicy::transitionStatus
 * (motivation.status — accountant/director/admin).
 */
class FinalizeMotivationCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('transitionStatus', $this->route('card')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
