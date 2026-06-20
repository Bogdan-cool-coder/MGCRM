<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Domain\Crm\Models\AcquisitionChannelHistory;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Http\Controllers\Controller;
use App\Http\Resources\Crm\AcquisitionChannelHistoryResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only history of acquisition channel changes.
 * GET /companies/{company}/channel-history
 * GET /contacts/{contact}/channel-history
 */
class AcquisitionChannelHistoryController extends Controller
{
    public function forCompany(Request $request, Company $company): AnonymousResourceCollection
    {
        $this->authorize('view', $company);

        $history = AcquisitionChannelHistory::query()
            ->where('entity_type', 'company')
            ->where('entity_id', $company->id)
            ->with(['oldChannel', 'newChannel', 'changedByUser'])
            ->orderByDesc('changed_at')
            ->get();

        return AcquisitionChannelHistoryResource::collection($history);
    }

    public function forContact(Request $request, Contact $contact): AnonymousResourceCollection
    {
        $this->authorize('view', $contact);

        $history = AcquisitionChannelHistory::query()
            ->where('entity_type', 'contact')
            ->where('entity_id', $contact->id)
            ->with(['oldChannel', 'newChannel', 'changedByUser'])
            ->orderByDesc('changed_at')
            ->get();

        return AcquisitionChannelHistoryResource::collection($history);
    }
}
