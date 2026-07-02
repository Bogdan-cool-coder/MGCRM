<?php

declare(strict_types=1);

namespace App\Http\Requests\System;

use App\Support\System\ResetCategory;
use App\Support\System\SystemResetService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Guards POST /api/system/reset — the SELECTIVE data wipe (contract §6.2).
 * Repurposed from the legacy full-wipe (P1-A): the confirmation phrase changed from
 * «СБРОСИТЬ НАСТРОЙКИ» to «СБРОСИТЬ» and the body now carries the category selection.
 *
 * Defense in depth:
 *   - authorize(): $user->can('system-reset') — admin only, NOT director (§6.2,
 *     backend-standard §4 — never inline role check).
 *   - rules(): categories from the 9-key allow-list + the fixed confirmation phrase.
 *
 * config('system.reset_enabled') + the throttle live on the route/controller, not
 * here (the FormRequest owns validation + request-authorization only).
 */
class SystemResetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('system-reset') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'categories' => ['required', 'array', 'min:1'],
            'categories.*' => ['required', 'string', Rule::in(ResetCategory::values())],
            'confirmation' => ['required', 'string', Rule::in([SystemResetService::confirmationPhrase()])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'categories.required' => __('Выберите хотя бы одну категорию для сброса.'),
            'categories.min' => __('Выберите хотя бы одну категорию для сброса.'),
            'categories.*.in' => __('Неизвестная категория сброса.'),
            'confirmation.in' => __('Введите фразу подтверждения точно, чтобы сбросить данные.'),
        ];
    }

    /**
     * The validated, de-duplicated category keys.
     *
     * @return list<string>
     */
    public function categories(): array
    {
        /** @var list<string> $keys */
        $keys = array_values(array_unique($this->validated('categories')));

        return $keys;
    }
}
