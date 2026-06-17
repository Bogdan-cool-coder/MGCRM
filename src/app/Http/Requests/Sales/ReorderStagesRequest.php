<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Domain\Sales\Models\Pipeline;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Bulk stage reorder. The array order is authoritative — sort_order values are
 * accepted for contract parity but the service normalizes 1..N from positions.
 */
class ReorderStagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Pipeline $pipeline */
        $pipeline = $this->route('pipeline');

        return $this->user()->can('update', $pipeline);
    }

    public function rules(): array
    {
        return [
            'stages' => ['required', 'array', 'min:1'],
            'stages.*.id' => ['required', 'integer', 'exists:pipeline_stages,id'],
            'stages.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
