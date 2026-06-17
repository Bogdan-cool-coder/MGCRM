<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inbox;

use App\Domain\Inbox\Models\Form;
use App\Domain\Inbox\Services\FormService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inbox\StoreFormRequest;
use App\Http\Requests\Inbox\UpdateFormRequest;
use App\Http\Resources\Inbox\FormResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;

/**
 * Thin Form controller. Forms are admin-grade config — all operations are
 * admin/director (policy). Public render/submit lives in PublicFormController.
 */
class FormController extends Controller
{
    public function __construct(
        private readonly FormService $service,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Form::class);

        $forms = $this->service->list(
            $request->only(['is_active', 'channel_id']),
            (int) $request->query('per_page', 25),
        );

        return FormResource::collection($forms);
    }

    public function store(StoreFormRequest $request): JsonResource
    {
        $form = $this->service->create($request->validated(), $request->user());

        return FormResource::make($form);
    }

    public function show(Request $request, Form $form): JsonResource
    {
        $this->authorize('view', $form);

        return FormResource::make($form);
    }

    public function update(UpdateFormRequest $request, Form $form): JsonResource
    {
        $updated = $this->service->update($form, $request->validated());

        return FormResource::make($updated);
    }

    public function destroy(Request $request, Form $form): Response
    {
        $this->authorize('delete', $form);

        $this->service->delete($form);

        return response()->noContent();
    }
}
