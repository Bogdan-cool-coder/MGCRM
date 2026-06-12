<?php

declare(strict_types=1);

namespace App\Http\Resources\Contracts;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ApprovalSummaryResource — wraps the progress array returned by ApprovalService::getProgress().
 *
 * The $resource here is an array (not a Model), so we access fields via array syntax.
 *
 * @mixin \ArrayObject
 */
class ApprovalSummaryResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $resource
     */
    public function __construct(array $resource)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->resource;

        $stages = array_map(static function (array $stage): array {
            // Convert nested Approval models to arrays for the response
            $approvals = array_map(
                static fn ($a): array => (new ApprovalResource($a))->resolve(),
                is_array($stage['approvals']) ? $stage['approvals'] : []
            );

            return [
                'order' => $stage['order'],
                'name' => $stage['name'],
                'user_ids' => $stage['user_ids'],
                'min_required' => $stage['min_required'],
                'approved_count' => $stage['approved_count'],
                'rejected_count' => $stage['rejected_count'],
                'needs_rework_count' => $stage['needs_rework_count'],
                'pending_count' => $stage['pending_count'],
                'is_active' => $stage['is_active'],
                'approvals' => $approvals,
            ];
        }, $data['stages'] ?? []);

        return [
            'current_stage_order' => $data['current_stage_order'],
            'total_stages' => $data['total_stages'],
            'attempt' => $data['attempt'],
            'can_resubmit' => $data['can_resubmit'],
            'stages' => $stages,
        ];
    }
}
