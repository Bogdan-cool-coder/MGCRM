<?php

declare(strict_types=1);

namespace App\Http\Controllers\Contracts;

use App\Domain\Contracts\Enums\AiCheckStatus;
use App\Domain\Contracts\Models\Template;
use App\Domain\Contracts\Models\TemplateVersion;
use App\Domain\Contracts\Services\TemplateService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contracts\OverrideTemplateCheckRequest;
use App\Http\Requests\Contracts\UploadTemplateVersionRequest;
use App\Http\Resources\Contracts\TemplateVersionResource;
use App\Jobs\Contracts\CheckTemplateJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * TemplateVersionController — docx upload and AI-check lifecycle (S2.3).
 *
 * Routes (all under auth:sanctum + 2fa + locale + visibility):
 *   POST   /api/templates/{template}/upload                         → upload()
 *   GET    /api/templates/{template}/versions                       → index()
 *   GET    /api/templates/{template}/versions/{version}             → show()
 *   POST   /api/templates/{template}/versions/{version}/check       → check()
 *   POST   /api/templates/{template}/versions/{version}/override    → override()
 *
 * Policy: uploadVersion / checkVersion / overrideVersion — lawyer / admin.
 *         viewVersions — any authenticated user (for polling).
 */
class TemplateVersionController extends Controller
{
    public function __construct(
        private readonly TemplateService $service,
    ) {}

    /**
     * POST /api/templates/{template}/upload
     *
     * Saves the uploaded docx to disk `documents` under
     * `templates/{template_id}/v{next_number}/template.docx`,
     * creates a new TemplateVersion (ai_check_status=pending),
     * and dispatches CheckTemplateJob.
     *
     * Returns 201 TemplateVersionResource.
     */
    public function upload(UploadTemplateVersionRequest $request, Template $template): JsonResponse
    {
        $this->authorize('uploadVersion', $template);

        $file = $request->file('file');

        // Determine the next version number for the path prefix.
        // The actual version_number is assigned atomically inside createVersion().
        $lastVersion = $this->service->latestVersion($template);
        $nextNumber = $lastVersion ? $lastVersion->version_number + 1 : 1;

        $docxPath = "templates/{$template->id}/v{$nextNumber}/template.docx";
        Storage::disk('documents')->put($docxPath, $file->get());

        $version = $this->service->createVersion(
            $template,
            $docxPath,
            $request->user()->id,
        );

        return TemplateVersionResource::make($version->load('createdBy:id,full_name'))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/templates/{template}/versions
     *
     * Returns all versions for a template, newest first.
     * Used by the lawyer to browse history and by the UI for polling.
     */
    public function index(Template $template): AnonymousResourceCollection
    {
        $this->authorize('viewVersions', $template);

        $versions = $template->versions()
            ->with('createdBy:id,full_name')
            ->orderByDesc('version_number')
            ->get();

        return TemplateVersionResource::collection($versions);
    }

    /**
     * GET /api/templates/{template}/versions/{version}
     *
     * Single version — the primary polling endpoint.
     * Client polls every 3-5 s until ai_check_status ∈ {checked, failed}.
     */
    public function show(Template $template, TemplateVersion $version): JsonResource
    {
        $this->authorize('viewVersions', $template);

        // Ensure the version belongs to this template (no cross-template leaking).
        abort_if($version->template_id !== $template->id, 404);

        return TemplateVersionResource::make($version->load('createdBy:id,full_name'));
    }

    /**
     * POST /api/templates/{template}/versions/{version}/check
     *
     * Re-dispatches CheckTemplateJob for a version that is in `failed` or `checked`
     * state (or stuck in `pending`). Resets ai_check_status to `pending`.
     *
     * Returns 202 TemplateVersionResource.
     */
    public function check(OverrideTemplateCheckRequest $request, Template $template, TemplateVersion $version): JsonResponse
    {
        $this->authorize('checkVersion', $template);

        abort_if($version->template_id !== $template->id, 404);

        // Concurrency guard: reject if a check job is already running.
        abort_if($version->ai_check_status === AiCheckStatus::Checking, 409, 'AI check already in progress.');

        $version->update(['ai_check_status' => AiCheckStatus::Pending]);

        CheckTemplateJob::dispatch($version->id);

        return TemplateVersionResource::make($version->refresh()->load('createdBy:id,full_name'))
            ->response()
            ->setStatusCode(202);
    }

    /**
     * POST /api/templates/{template}/versions/{version}/override
     *
     * Marks ai_overridden = true so the lawyer can proceed despite AI warnings.
     * Remarks are preserved (not cleared) for audit trail.
     *
     * Returns 200 TemplateVersionResource.
     */
    public function override(OverrideTemplateCheckRequest $request, Template $template, TemplateVersion $version): JsonResource
    {
        $this->authorize('overrideVersion', $template);

        abort_if($version->template_id !== $template->id, 404);

        $version->update(['ai_overridden' => true]);

        return TemplateVersionResource::make($version->refresh()->load('createdBy:id,full_name'));
    }
}
