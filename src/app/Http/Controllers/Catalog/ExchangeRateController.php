<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Domain\Catalog\Jobs\UpdateExchangeRatesJob;
use App\Domain\Catalog\Models\ExchangeRate;
use App\Domain\Catalog\Services\ExchangeRateService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreExchangeRateRequest;
use App\Http\Requests\Catalog\UpdateExchangeRateRequest;
use App\Http\Resources\Catalog\ExchangeRateResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ExchangeRateController extends Controller
{
    public function __construct(
        private readonly ExchangeRateService $service,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ExchangeRate::class);

        $perPage = (int) $request->query('per_page', 50);
        $perPage = max(1, min(200, $perPage));

        $rates = ExchangeRate::query()
            ->when($request->query('from_code'), fn ($q) => $q->where('from_code', strtoupper((string) $request->query('from_code'))))
            ->when($request->query('to_code'), fn ($q) => $q->where('to_code', strtoupper((string) $request->query('to_code'))))
            ->when($request->query('date'), fn ($q) => $q->where('date', (string) $request->query('date')))
            ->orderByDesc('date')
            ->orderBy('from_code')
            ->paginate($perPage);

        return ExchangeRateResource::collection($rates);
    }

    public function store(StoreExchangeRateRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Check if the row exists before upserting so we can return proper 201 vs 200.
        $existsBefore = ExchangeRate::where('from_code', strtoupper($validated['from_code']))
            ->where('to_code', strtoupper($validated['to_code']))
            ->where('date', Carbon::parse($validated['date'])->toDateString())
            ->exists();

        $rate = $this->service->upsertRate(
            fromCode: $validated['from_code'],
            toCode: $validated['to_code'],
            rate: (string) $validated['rate'],
            date: $validated['date'],
            source: $validated['source'] ?? 'manual',
        );

        $statusCode = $existsBefore ? 200 : 201;

        return ExchangeRateResource::make($rate)->response()->setStatusCode($statusCode);
    }

    public function show(Request $request, ExchangeRate $exchangeRate): JsonResource
    {
        $this->authorize('view', $exchangeRate);

        return ExchangeRateResource::make($exchangeRate);
    }

    public function update(UpdateExchangeRateRequest $request, ExchangeRate $exchangeRate): JsonResource
    {
        $validated = $request->validated();

        // Guard: if new rate+date conflicts with another row (UNIQUE constraint), return 409.
        if (! empty($validated['date'])) {
            $newDate = Carbon::parse($validated['date'])->toDateString();
            $conflict = ExchangeRate::where('from_code', $exchangeRate->from_code)
                ->where('to_code', $exchangeRate->to_code)
                ->where('date', $newDate)
                ->where('id', '!=', $exchangeRate->id)
                ->exists();

            if ($conflict) {
                abort(409, 'Exchange rate for this pair and date already exists.');
            }
        }

        $exchangeRate->update($validated);

        return ExchangeRateResource::make($exchangeRate->fresh());
    }

    public function destroy(Request $request, ExchangeRate $exchangeRate): JsonResponse
    {
        $this->authorize('delete', $exchangeRate);

        $exchangeRate->delete();

        return response()->json(null, 204);
    }

    /**
     * POST /api/catalog/exchange-rates/refresh
     * Dispatches UpdateExchangeRatesJob on-demand. Admin/director only.
     * Returns 202 Accepted immediately; job runs async.
     */
    public function refresh(Request $request): JsonResponse
    {
        $this->authorize('create', ExchangeRate::class);

        UpdateExchangeRatesJob::dispatch();

        return response()->json(['message' => 'Exchange rate refresh queued.'], 202);
    }

    /**
     * GET /api/catalog/exchange-rates/convert?from=KZT&to=RUB&amount=100000&date=2026-06-12
     * amount is in kopecks.
     */
    public function convert(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ExchangeRate::class);

        $validated = $request->validate([
            'from' => ['required', 'string', 'size:3'],
            'to' => ['required', 'string', 'size:3'],
            'amount' => ['required', 'integer', 'min:0'],
            'date' => ['nullable', 'date'],
        ]);

        $from = strtoupper($validated['from']);
        $to = strtoupper($validated['to']);
        $amount = (int) $validated['amount'];
        $date = $validated['date'] ?? Carbon::today()->toDateString();

        $rate = $this->service->getRate($from, $to, $date);

        if ($rate === null) {
            throw ValidationException::withMessages([
                'from' => ["No exchange rate found for {$from}/{$to} on or before {$date}."],
            ]);
        }

        $converted = $this->service->convertAmount($amount, $from, $to, $date);

        return response()->json([
            'data' => [
                'from_code' => $from,
                'to_code' => $to,
                'from_amount' => $amount,
                'to_amount' => $converted,
                'rate' => $rate,
                'date' => $date,
            ],
        ]);
    }
}
