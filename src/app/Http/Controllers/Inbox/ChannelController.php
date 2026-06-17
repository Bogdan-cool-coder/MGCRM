<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inbox;

use App\Domain\Inbox\Models\Channel;
use App\Domain\Inbox\Services\ChannelService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inbox\StoreChannelRequest;
use App\Http\Requests\Inbox\UpdateChannelRequest;
use App\Http\Resources\Inbox\ChannelResource;
use App\Http\Resources\Inbox\ChannelSecretResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;

/**
 * Thin Channel controller. Reads are open (assign dropdowns); writes/reveal/
 * regenerate are admin/director (policy). The token is masked everywhere except
 * the three secret-bearing endpoints.
 */
class ChannelController extends Controller
{
    public function __construct(
        private readonly ChannelService $service,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Channel::class);

        $channels = $this->service->list(
            $request->only(['kind', 'is_active']),
            (int) $request->query('per_page', 25),
        );

        return ChannelResource::collection($channels);
    }

    public function store(StoreChannelRequest $request): JsonResource
    {
        $channel = $this->service->create($request->validated());

        // Full token returned ONCE on create.
        return ChannelSecretResource::make($channel);
    }

    public function show(Request $request, Channel $channel): JsonResource
    {
        $this->authorize('view', $channel);

        return ChannelResource::make($channel);
    }

    public function update(UpdateChannelRequest $request, Channel $channel): JsonResource
    {
        $updated = $this->service->update($channel, $request->validated());

        return ChannelResource::make($updated);
    }

    public function destroy(Request $request, Channel $channel): Response
    {
        $this->authorize('delete', $channel);

        $this->service->delete($channel, $request->boolean('force'));

        return response()->noContent();
    }

    public function reveal(Request $request, Channel $channel): JsonResource
    {
        $this->authorize('manageToken', $channel);

        return ChannelSecretResource::make($channel);
    }

    public function regenerate(Request $request, Channel $channel): JsonResource
    {
        $this->authorize('manageToken', $channel);

        $regenerated = $this->service->regenerateToken($channel);

        return ChannelSecretResource::make($regenerated);
    }
}
