<?php

declare(strict_types=1);

namespace App\Http\Resources\Crm;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * DedupCandidateResource — wraps a Contact or Company model when returned
 * as a dedup scan candidate. Surfaces only the fields useful for the merge UI.
 */
class DedupCandidateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Works for both Contact and Company
        $base = [
            'id' => $this->id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];

        if (isset($this->full_name)) {
            // Contact
            return $base + [
                'type' => 'contact',
                'full_name' => $this->full_name,
                'email' => $this->email,
                'phone' => $this->phone,
                'source' => $this->source,
                'status' => $this->status?->value ?? $this->status,
            ];
        }

        // Company
        return $base + [
            'type' => 'company',
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'tax_id' => $this->tax_id,
            'email' => $this->email,
            'phone' => $this->phone,
            'source' => $this->source,
        ];
    }
}
