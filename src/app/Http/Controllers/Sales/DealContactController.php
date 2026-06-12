<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealContact;
use App\Domain\Sales\Services\DealContactService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreDealContactRequest;
use App\Http\Resources\Sales\DealContactResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Thin nested deal-contact controller. Adding a contact also links it to the
 * deal's company (cross-domain via the service). Links not on the route deal → 404.
 */
class DealContactController extends Controller
{
    public function __construct(
        private readonly DealContactService $service,
    ) {}

    public function index(Request $request, Deal $deal): AnonymousResourceCollection
    {
        $this->authorize('view', $deal);

        return DealContactResource::collection($this->service->list($deal));
    }

    public function store(StoreDealContactRequest $request, Deal $deal): JsonResource
    {
        $dealContact = $this->service->addContact(
            $deal,
            (int) $request->validated('contact_id'),
            (bool) $request->validated('is_primary', false),
        );

        return DealContactResource::make($dealContact);
    }

    public function destroy(Request $request, Deal $deal, DealContact $dealContact): JsonResponse
    {
        $this->authorize('update', $deal);
        abort_unless((int) $dealContact->deal_id === (int) $deal->id, 404);

        $this->service->removeContact($dealContact);

        return response()->json(['message' => 'Contact unlinked.'], 200);
    }
}
