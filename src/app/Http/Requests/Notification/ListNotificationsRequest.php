<?php

declare(strict_types=1);

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ListNotificationsRequest — validates the feed pagination query for
 * GET /api/notifications. Authorization is handled by the controller via the
 * NotificationPolicy (viewAny); the request only bounds per_page.
 */
class ListNotificationsRequest extends FormRequest
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
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->query('per_page', 20);
    }
}
