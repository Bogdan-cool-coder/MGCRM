<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\ContactChannel;
use App\Domain\Crm\Services\ContactChannelService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreContactChannelRequest;
use App\Http\Requests\Crm\UpdateContactChannelRequest;
use App\Http\Resources\Crm\ContactChannelResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Thin controller for contact channels (ARCHITECTURE.md §1).
 * Routes: GET/POST /contacts/{contact}/channels
 *         PATCH/DELETE /contacts/{contact}/channels/{channel}
 */
class ContactChannelController extends Controller
{
    public function __construct(
        private readonly ContactChannelService $service,
    ) {}

    public function index(Request $request, Contact $contact): AnonymousResourceCollection
    {
        $this->authorize('view', $contact);

        $channels = $this->service->list($contact);

        return ContactChannelResource::collection($channels);
    }

    public function store(StoreContactChannelRequest $request, Contact $contact): JsonResource
    {
        $channel = $this->service->create($contact, $request->validated());

        return ContactChannelResource::make($channel);
    }

    public function update(UpdateContactChannelRequest $request, Contact $contact, ContactChannel $channel): JsonResource
    {
        // Ensure the channel belongs to the route-bound contact (prevent IDOR).
        abort_if((int) $channel->contact_id !== (int) $contact->id, 404);

        $updated = $this->service->update($channel, $request->validated());

        return ContactChannelResource::make($updated);
    }

    public function destroy(Request $request, Contact $contact, ContactChannel $channel): JsonResponse
    {
        $this->authorize('update', $contact);

        // Ensure the channel belongs to the route-bound contact (prevent IDOR).
        abort_if((int) $channel->contact_id !== (int) $contact->id, 404);

        $this->service->delete($channel);

        return response()->json(['message' => 'Channel deleted.']);
    }
}
