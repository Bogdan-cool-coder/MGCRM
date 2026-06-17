<?php

declare(strict_types=1);

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ReadBatchNotificationsRequest — validates POST /api/notifications/read-batch.
 * Authorization is handled by the controller (viewAny + per-row user scoping in
 * NotificationService); the request only bounds and shapes the id list.
 *
 * `exists` is intentionally NOT user-scoped here: ownership is enforced in the
 * service WHERE so foreign ids are silently ignored (no 403, no leak) rather
 * than failing validation — that keeps the endpoint idempotent and non-probing.
 */
class ReadBatchNotificationsRequest extends FormRequest
{
    /**
     * Cap on a single batch — generous for "mark visible page read" callers
     * while preventing an unbounded IN (...) statement.
     */
    private const MAX_IDS = 200;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_IDS],
            'ids.*' => ['integer', 'distinct', 'exists:notifications,id'],
        ];
    }

    /**
     * @return list<int>
     */
    public function ids(): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->validated('ids');

        return array_values(array_map(intval(...), $ids));
    }
}
