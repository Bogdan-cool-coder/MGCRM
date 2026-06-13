<?php

declare(strict_types=1);

namespace App\Http\Controllers\Contracts;

use App\Domain\Contracts\Models\MessageTemplate;
use App\Domain\Contracts\Models\MessageTemplateBinding;
use App\Domain\Contracts\Services\MessageTemplateService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contracts\ContextQueryRequest;
use App\Http\Requests\Contracts\PreviewMessageTemplateRequest;
use App\Http\Requests\Contracts\StoreMessageTemplateBindingRequest;
use App\Http\Requests\Contracts\StoreMessageTemplateRequest;
use App\Http\Requests\Contracts\UpdateMessageTemplateRequest;
use App\Http\Resources\Contracts\MessageTemplateBindingResource;
use App\Http\Resources\Contracts\MessageTemplatePreviewResource;
use App\Http\Resources\Contracts\MessageTemplateResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * MessageTemplateController — CRUD + preview + binding sub-resource + context-match.
 *
 * Routes (declared in api.php):
 *   GET    /api/message-templates/context            → context()
 *   GET    /api/message-templates                    → index()
 *   POST   /api/message-templates                    → store()
 *   GET    /api/message-templates/{messageTemplate}  → show()
 *   PATCH  /api/message-templates/{messageTemplate}  → update()
 *   DELETE /api/message-templates/{messageTemplate}  → destroy()
 *   POST   /api/message-templates/{messageTemplate}/preview   → preview()
 *   GET    /api/message-templates/{messageTemplate}/bindings  → bindingIndex()
 *   POST   /api/message-templates/{messageTemplate}/bindings  → bindingStore()
 *   DELETE /api/message-templates/{messageTemplate}/bindings/{binding} → bindingDestroy()
 */
class MessageTemplateController extends Controller
{
    public function __construct(
        private readonly MessageTemplateService $service,
    ) {}

    /**
     * GET /api/message-templates/context
     * Find the most specific active template for the given filter context.
     * Returns 404 if no template matches.
     */
    public function context(ContextQueryRequest $request): JsonResponse|JsonResource
    {
        $this->authorize('viewAny', MessageTemplate::class);

        $filter = $request->validated();

        $template = $this->service->findForContext($filter);

        if ($template === null) {
            return response()->json(['message' => 'Нет подходящего шаблона для данного контекста.'], 404);
        }

        $template->load('bindings');

        return MessageTemplateResource::make($template);
    }

    /**
     * GET /api/message-templates
     * Optional query filters: ?is_active= ?channel_kind=
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', MessageTemplate::class);

        $query = MessageTemplate::with('bindings');

        // Filter by is_active (default: active only, pass is_active=false for soft-deleted)
        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        } else {
            $query->where('is_active', true);
        }

        // Filter by channel_kind (if given, only templates with at least one matching binding)
        if ($request->filled('channel_kind')) {
            $ck = $request->query('channel_kind');
            $query->whereHas('bindings', static function ($q) use ($ck): void {
                $q->where('channel_kind', $ck);
            });
        }

        $templates = $query->orderBy('id')->get();

        return MessageTemplateResource::collection($templates);
    }

    /**
     * POST /api/message-templates
     */
    public function store(StoreMessageTemplateRequest $request): JsonResponse
    {
        $this->authorize('create', MessageTemplate::class);

        $template = $this->service->create(
            $request->validated(),
            $request->user()->id,
        );

        $template->load('bindings');

        return MessageTemplateResource::make($template)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/message-templates/{messageTemplate}
     */
    public function show(MessageTemplate $messageTemplate): JsonResource
    {
        $this->authorize('view', $messageTemplate);

        $messageTemplate->load('bindings');

        return MessageTemplateResource::make($messageTemplate);
    }

    /**
     * PATCH /api/message-templates/{messageTemplate}
     */
    public function update(UpdateMessageTemplateRequest $request, MessageTemplate $messageTemplate): JsonResource
    {
        $this->authorize('update', $messageTemplate);

        $updated = $this->service->update($messageTemplate, $request->validated(), $request->user()->id);

        return MessageTemplateResource::make($updated);
    }

    /**
     * DELETE /api/message-templates/{messageTemplate}
     * Soft-delete: sets is_active=false.
     */
    public function destroy(MessageTemplate $messageTemplate): JsonResponse
    {
        $this->authorize('delete', $messageTemplate);

        $this->service->deactivate($messageTemplate);

        return response()->json(null, 204);
    }

    /**
     * POST /api/message-templates/{messageTemplate}/preview
     * Render template with provided test vars; returns {subject, body, unresolved_keys}.
     */
    public function preview(PreviewMessageTemplateRequest $request, MessageTemplate $messageTemplate): JsonResource
    {
        $this->authorize('view', $messageTemplate);

        $vars = array_map(
            static fn (mixed $v): string => $v !== null ? (string) $v : '',
            $request->validated()['vars'] ?? [],
        );

        $result = $this->service->render($messageTemplate, $vars);

        return MessageTemplatePreviewResource::make($result);
    }

    /**
     * GET /api/message-templates/{messageTemplate}/bindings
     */
    public function bindingIndex(MessageTemplate $messageTemplate): AnonymousResourceCollection
    {
        $this->authorize('view', $messageTemplate);

        return MessageTemplateBindingResource::collection(
            $messageTemplate->bindings()->orderBy('id')->get()
        );
    }

    /**
     * POST /api/message-templates/{messageTemplate}/bindings
     */
    public function bindingStore(
        StoreMessageTemplateBindingRequest $request,
        MessageTemplate $messageTemplate,
    ): JsonResponse {
        $this->authorize('update', $messageTemplate);

        $binding = $this->service->addBinding($messageTemplate, $request->validated());

        return MessageTemplateBindingResource::make($binding)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * DELETE /api/message-templates/{messageTemplate}/bindings/{binding}
     */
    public function bindingDestroy(MessageTemplate $messageTemplate, MessageTemplateBinding $binding): JsonResponse
    {
        $this->authorize('update', $messageTemplate);

        // Ensure the binding belongs to this template
        abort_if($binding->message_template_id !== $messageTemplate->id, 404);

        $binding->delete();

        return response()->json(null, 204);
    }
}
