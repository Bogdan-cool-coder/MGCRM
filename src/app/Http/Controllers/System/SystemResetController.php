<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Http\Requests\System\SystemResetRequest;
use App\Http\Resources\System\SystemResetPreviewResource;
use App\Http\Resources\System\SystemResetResource;
use App\Support\System\SystemResetService;
use Illuminate\Http\Request;

/**
 * Selective system reset (Settings → Система → «Сброс системы», admin-only).
 * Repurposed from the legacy full-wipe (contract P1-A): per-category permanent
 * deletion, NOT migrate:fresh. The old artisan `app:reset-clean` stays CLI-only.
 *
 * Thin controller (backend-standard §1): guard the feature flag → hand off to the
 * one service method → return a Resource. ALL logic (allow-list, prerequisites,
 * FK order, per-category tx, audit) lives in SystemResetService.
 *
 * Defense in depth:
 *   1. config('system.reset_enabled') — OFF by default; 403 when disabled (both
 *      preview and execute — a disabled feature is not advertised).
 *   2. auth:sanctum + can:system-reset (route + SystemResetRequest::authorize) —
 *      admin only, explicitly NOT director.
 *   3. throttle:3,1 on the execute route — a destructive op is never hammered.
 *   4. SystemResetRequest — categories from the 9-key allow-list + phrase «СБРОСИТЬ».
 */
class SystemResetController extends Controller
{
    public function __construct(private readonly SystemResetService $service) {}

    /**
     * GET /api/system/reset/preview — live per-category counts + prerequisite matrix.
     */
    public function preview(Request $request): SystemResetPreviewResource
    {
        $this->assertEnabled();

        return new SystemResetPreviewResource($this->service->previewCounts());
    }

    /**
     * POST /api/system/reset — execute the selective wipe for the chosen categories.
     */
    public function store(SystemResetRequest $request): SystemResetResource
    {
        $this->assertEnabled();

        $result = $this->service->reset(
            categoryKeys: $request->categories(),
            actor: $request->user(),
            ip: $request->ip(),
        );

        return new SystemResetResource($result);
    }

    /**
     * Feature-flag guard shared by both actions (contract §6). 403 when the reset is
     * disabled for this environment — off by default.
     */
    private function assertEnabled(): void
    {
        abort_unless((bool) config('system.reset_enabled'), 403, __('System reset is disabled.'));
    }
}
