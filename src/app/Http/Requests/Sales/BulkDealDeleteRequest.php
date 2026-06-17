<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Http\FormRequest;

/**
 * DELETE /api/deals/bulk — mass soft-delete from the board toolbar. Per-deal
 * authorisation (the `delete` ability, all-or-nothing 403) lives in
 * BulkDealService; viewAny is the base gate here.
 */
class BulkDealDeleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Deal::class);
    }

    public function rules(): array
    {
        return [
            'deal_ids' => ['required', 'array', 'min:1'],
            'deal_ids.*' => ['integer', 'exists:deals,id'],
        ];
    }

    /** @return list<int> */
    public function dealIds(): array
    {
        return array_map('intval', $this->validated('deal_ids'));
    }
}
