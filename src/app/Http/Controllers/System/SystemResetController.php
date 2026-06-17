<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Http\Requests\System\SystemResetRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;

/**
 * "Сброс настроек" (admin-only) — drops all tables and re-seeds baseline config.
 *
 * Guards (defense in depth):
 *   1. config('system.reset_enabled') — OFF by default; aborts 403 when disabled.
 *   2. SystemResetRequest::authorize() — admin role only (system-reset gate).
 *   3. SystemResetRequest::rules() — client must echo the confirmation phrase.
 *
 * Runs synchronously in-request: migrations are fast on dev/staging and the
 * caller wants the JSON result. The reset drops the sessions table and all
 * personal access tokens (the admin's own token included), so the response flags
 * requires_relogin — the SPA must redirect to login afterwards.
 */
class SystemResetController extends Controller
{
    public function store(SystemResetRequest $request): JsonResponse
    {
        // Guard 1: feature must be explicitly enabled for this environment.
        abort_unless((bool) config('system.reset_enabled'), 403, __('System reset is disabled.'));

        // Guards 2 + 3 are enforced by SystemResetRequest (admin + phrase).

        Artisan::call('app:reset-clean', ['--force' => true]);

        return response()->json([
            'data' => [
                'reset' => true,
                // The wipe invalidates the current token + session — the client
                // must re-authenticate.
                'requires_relogin' => true,
                'message' => __('Настройки сброшены к базовой конфигурации. Войдите снова.'),
            ],
        ]);
    }
}
