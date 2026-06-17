<?php

declare(strict_types=1);

namespace App\Http\Requests\System;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Guards POST /api/system/reset (admin-only "Сброс настроек").
 *
 * authorize(): admin role only (system-reset gate). rules(): requires the client
 * to echo a fixed confirmation phrase — a server-side backstop for the frontend
 * "type the phrase to confirm" UX, so a stray POST can never trigger the wipe.
 */
class SystemResetRequest extends FormRequest
{
    /** The phrase the client must type to confirm the destructive reset. */
    public const CONFIRMATION_PHRASE = 'СБРОСИТЬ НАСТРОЙКИ';

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
            'confirmation' => ['required', 'string', Rule::in([self::CONFIRMATION_PHRASE])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirmation.in' => __('Введите фразу подтверждения точно, чтобы сбросить настройки.'),
        ];
    }
}
