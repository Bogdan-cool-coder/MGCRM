<?php

declare(strict_types=1);

namespace App\Http\Resources\Catalog;

use App\Domain\Catalog\Models\ExchangeRate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ExchangeRate */
class ExchangeRateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from_code' => $this->from_code,
            'to_code' => $this->to_code,
            // Cast to float so the FE receives a JSON number, not a string.
            // The decimal:6 Eloquent cast produces a string ('1.234560'); the
            // FE types it as number — this cast bridges that drift.
            'rate' => (float) $this->rate,
            'date' => $this->date, // stored as Y-m-d string, no Carbon cast
            'source' => $this->source,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
