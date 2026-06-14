<?php

declare(strict_types=1);

namespace App\Http\Resources\Onboarding;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * AiTutorAnswerResource — wraps the plain array returned by AiTutorService::ask().
 *
 * Usage: new AiTutorAnswerResource(['answer' => '...', 'session_id' => 42])
 */
class AiTutorAnswerResource extends JsonResource
{
    /**
     * @param  array{answer: string, session_id: int}  $resource
     */
    public function __construct(array $resource)
    {
        // JsonResource expects a model or array-access. We pass a plain array.
        parent::__construct((object) $resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'answer' => $this->resource->answer,
            'session_id' => $this->resource->session_id,
        ];
    }
}
