<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Services\CrmFeedService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin read-only controller for a Company or Contact unified event feed.
 *
 * Mirrors DealFeedController (Sales domain). Auth uses the existing
 * CompanyPolicy::view / ContactPolicy::view gates.
 *
 * GET /api/companies/{company}/feed
 * GET /api/contacts/{contact}/feed
 *
 * Query params: page (int), per_page (int), types[] (array of strings)
 */
class CrmFeedController extends Controller
{
    public function __construct(
        private readonly CrmFeedService $feed,
    ) {}

    public function companyFeed(Request $request, Company $company): JsonResponse
    {
        $this->authorize('view', $company);

        $types = $request->query('types');

        $result = $this->feed->feed(
            $company,
            ['types' => is_array($types) ? $types : []],
            (int) $request->query('page', '1'),
            $this->perPage($request),
        );

        return response()->json($result);
    }

    public function contactFeed(Request $request, Contact $contact): JsonResponse
    {
        $this->authorize('view', $contact);

        $types = $request->query('types');

        $result = $this->feed->feed(
            $contact,
            ['types' => is_array($types) ? $types : []],
            (int) $request->query('page', '1'),
            $this->perPage($request),
        );

        return response()->json($result);
    }

    /**
     * Clamp per_page to a sane window (1..100), mirroring EntityLogController.
     */
    private function perPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', '30');

        return max(1, min($perPage, 100));
    }
}
