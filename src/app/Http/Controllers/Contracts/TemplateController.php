<?php

declare(strict_types=1);

namespace App\Http\Controllers\Contracts;

use App\Domain\Contracts\Models\Template;
use App\Domain\Contracts\Services\TemplateService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contracts\UpdateTemplateRequest;
use App\Http\Resources\Contracts\TemplateResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class TemplateController extends Controller
{
    public function __construct(
        private readonly TemplateService $service,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Template::class);

        $templates = $this->service->list(
            $request->query('kind'),
            $request->query('category'),
        );

        return TemplateResource::collection($templates);
    }

    public function show(Request $request, Template $template): JsonResource
    {
        $this->authorize('view', $template);

        return TemplateResource::make($template->load('currentVersion'));
    }

    public function update(UpdateTemplateRequest $request, Template $template): JsonResource
    {
        $updated = $this->service->update(
            $template,
            $request->validated(),
            $request->user()->id,
        );

        return TemplateResource::make($updated);
    }
}
