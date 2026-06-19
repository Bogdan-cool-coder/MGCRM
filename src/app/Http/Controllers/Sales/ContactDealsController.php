<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Domain\Crm\Models\Contact;
use App\Domain\Sales\Services\DealService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Sales\DealResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * ContactDealsController — list deals linked to a specific contact.
 * Cross-domain: Crm contact → Sales deals (ARCHITECTURE.md §2 — via public Service method).
 *
 * Route: GET /api/contacts/{contact}/deals
 */
class ContactDealsController extends Controller
{
    public function __construct(
        private readonly DealService $service,
    ) {}

    public function index(Request $request, Contact $contact): AnonymousResourceCollection
    {
        $this->authorize('view', $contact);

        $deals = $this->service->listForContact($contact, $request->query());

        return DealResource::collection($deals);
    }
}
