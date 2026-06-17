<?php

declare(strict_types=1);

namespace App\Http\Resources\Onboarding;

use App\Domain\Onboarding\Models\Certificate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Certificate
 */
class CertificateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'certificate_number' => $this->certificate_number,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'download_url' => "/api/onboarding/certificates/{$this->assignment_id}/download",
            'assignment_id' => $this->assignment_id,
        ];
    }
}
