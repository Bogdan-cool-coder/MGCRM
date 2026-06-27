<?php

declare(strict_types=1);

namespace App\Http\Resources\Contracts;

use App\Domain\Contracts\Models\Approval;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Approval
 */
class ApprovalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_id' => $this->document_id,
            'attempt' => $this->attempt,
            'stage_order' => $this->stage_order,
            'user_id' => $this->user_id,
            // Flat user_name for ApprovalVoteDto (FE expects string, not nested object).
            'user_name' => $this->whenLoaded(
                'user',
                fn () => (string) ($this->user?->full_name ?? ''),
                '',
            ),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'full_name' => $this->user->full_name,
            ]),
            'decision' => $this->decision->value,
            'comment' => $this->comment,
            'decided_at' => $this->decided_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
