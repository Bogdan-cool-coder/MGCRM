<?php

declare(strict_types=1);

namespace App\Http\Resources\Contracts;

use App\Domain\Contracts\Models\Approval;
use App\Domain\Contracts\Models\ApprovalRoute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * MyApprovalResource — enriched view for the My Approvals page.
 *
 * Extends base approval fields with document-level and company-level data
 * expected by MyApprovalsPage FE:
 *   - document_number  (from document.number)
 *   - document_kind    (from document.kind.value)
 *   - company_name     (from document.sourceCompany.name)
 *   - stage_name       (resolved from approval route stages JSON by stage_order)
 *   - status           ('pending' | 'decided') derived from decision value
 *
 * Requires eager-loads on the Approval query:
 *   'user:id,full_name', 'document:id,title,status,kind,number,source_company_id',
 *   'document.sourceCompany:id,name'
 *
 * @mixin Approval
 */
class MyApprovalResource extends JsonResource
{
    /** Cached stage-name map shared across the collection: [stage_order => name]. */
    private static ?Collection $routeStagesCache = null;

    public static function collection($resource): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        // Pre-warm the route stage cache once per collection render.
        static::$routeStagesCache = null;

        return parent::collection($resource);
    }

    public function toArray(Request $request): array
    {
        /** @var Approval $approval */
        $approval = $this->resource;

        $doc = $this->whenLoaded('document');
        $docModel = $approval->document ?? null;

        return [
            'id' => $this->id,
            'document_id' => $this->document_id,
            'document_number' => $docModel?->number,
            'document_kind' => $docModel?->kind?->value,
            'company_name' => $docModel?->sourceCompany?->name,
            'stage_name' => $this->resolveStage((int) $this->stage_order),
            'stage_order' => $this->stage_order,
            'attempt' => $this->attempt,
            // 'status' for FE: 'pending' when decision=pending, else 'decided'
            'status' => $approval->decision->value === 'pending' ? 'pending' : 'decided',
            'decision' => $approval->decision->value,
            'comment' => $this->comment,
            'created_at' => $this->created_at?->toISOString(),
            'decided_at' => $this->decided_at?->toISOString(),
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'full_name' => $this->user->full_name,
            ]),
        ];
    }

    /**
     * Return the stage name for a given order, loading all active routes once.
     * Searches through stages of all active routes to find the matching stage name.
     */
    private function resolveStage(int $stageOrder): ?string
    {
        if (static::$routeStagesCache === null) {
            // Build a flat [stage_order => name] map from all active routes.
            // Multiple routes may share stage orders — last one wins (acceptable for cosmetics).
            $map = collect();
            ApprovalRoute::where('is_active', true)
                ->select(['id', 'stages'])
                ->get()
                ->each(function (ApprovalRoute $route) use ($map): void {
                    foreach ($route->stages ?? [] as $stage) {
                        $order = (int) ($stage['order'] ?? -1);
                        if ($order >= 0 && isset($stage['name'])) {
                            $map->put($order, $stage['name']);
                        }
                    }
                });
            static::$routeStagesCache = $map;
        }

        return static::$routeStagesCache->get($stageOrder);
    }
}
