<?php

declare(strict_types=1);

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ReadBatchNotificationsRequest — validates POST /api/notifications/read-batch.
 * Authorization is handled by the controller (viewAny + per-row user scoping in
 * NotificationService); the request only bounds and shapes the id list.
 *
 * Existence is intentionally NOT validated here. Ownership + existence are both
 * enforced by the service WHERE (forUser + whereIn), so any id that is foreign,
 * non-existent or already-read is silently skipped — no 403, no 422, no leak.
 * Dropping the `exists` rule closes a minor ID oracle: previously a *foreign but
 * real* id passed validation while a *non-existent* id 422'd, letting a caller
 * probe which ids exist globally. Now both behave identically (non-probing).
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
            // No `exists` rule: ownership/existence is enforced by the service's
            // user-scoped WHERE, so non-existent and foreign ids are silently
            // skipped rather than 422'd — see the class docblock (ID-oracle fix).
            'ids.*' => ['integer', 'distinct'],
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
