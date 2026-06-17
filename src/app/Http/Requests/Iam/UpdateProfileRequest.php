<?php

declare(strict_types=1);

namespace App\Http\Requests\Iam;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate a self-service profile update (Iam context).
 *
 * Only fields the user may change about their own account live here. For now
 * that is the personalised quick-action navigation: an ordered list of up to 5
 * distinct string action keys. `nullable` clears the list back to the default.
 */
class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Any authenticated user may edit their own profile; the route is
        // gated by auth:sanctum + 2fa and always targets $request->user().
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nav_quick_actions' => ['nullable', 'array', 'max:5'],
            'nav_quick_actions.*' => ['required', 'string', 'max:64', 'distinct'],
        ];
    }
}
