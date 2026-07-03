<?php

declare(strict_types=1);

namespace App\Http\Requests\Shared;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Generic drag-and-drop reorder payload for a flat `sort_order` directory.
 *
 * Body: { "items": [ { "id": 3 }, { "id": 1 }, { "id": 2 } ] }
 *
 * The ARRAY ORDER is authoritative — a client-sent `sort_order` (if any) is
 * ignored; position in the array becomes the new dense sort_order (see
 * App\Support\SortOrderReorderer). Id membership/existence is enforced in the
 * service against the target table, so this request only shape-validates.
 *
 * The admin-write Gate is enforced in each controller's reorder() action
 * ($this->authorize('admin-write')), consistent with the other directory writes.
 */
class ReorderDirectoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // admin-write Gate is checked in the controller action.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
        ];
    }

    /**
     * The ordered list of ids as sent (array order = new order).
     *
     * @return array<int, int>
     */
    public function orderedIds(): array
    {
        return array_map(
            static fn (array $item): int => (int) $item['id'],
            $this->validated('items'),
        );
    }
}
