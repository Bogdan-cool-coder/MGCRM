<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Services;

use App\Domain\Contracts\Models\ApprovalRoute;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Models\TemplateVersion;
use Illuminate\Validation\ValidationException;

/**
 * ApprovalRouteService — route matching and CRUD helpers.
 *
 * Match priority:
 *   1. Exact: document_kind + template_id (from document's template_version)
 *   2. Fallback: document_kind + is_default=true
 *   3. No match → ValidationException 422
 *
 * No whereJsonContains — PHP parsing for SQLite compat.
 */
class ApprovalRouteService
{
    /**
     * Resolve the best-matching active ApprovalRoute for a document.
     *
     * @throws ValidationException 422 when no route is configured
     */
    public function matchForDocument(Document $doc): ApprovalRoute
    {
        $templateId = null;
        if ($doc->template_version !== null) {
            $tv = TemplateVersion::find($doc->template_version);
            $templateId = $tv?->template_id;
        }

        return $this->match($doc->kind->value, $templateId);
    }

    /**
     * Core match logic — exposed for direct use in tests.
     *
     * @throws ValidationException 422 when no route is configured
     */
    public function match(string $documentKind, ?int $templateId): ApprovalRoute
    {
        // 1. Exact match: kind + template_id
        if ($templateId !== null) {
            $exact = ApprovalRoute::where('document_kind', $documentKind)
                ->where('template_id', $templateId)
                ->where('is_active', true)
                ->first();

            if ($exact !== null) {
                return $exact;
            }
        }

        // 2. Fallback: kind + is_default=true
        $default = ApprovalRoute::where('document_kind', $documentKind)
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();

        if ($default !== null) {
            return $default;
        }

        // 3. No route → 422
        throw ValidationException::withMessages([
            'approval_route' => 'Не настроен маршрут согласования для этого документа.',
        ])->status(422);
    }

    /**
     * Create a new ApprovalRoute. Normalises stage ordering.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, int $userId): ApprovalRoute
    {
        $data['stages'] = $this->normalizeStages($data['stages']);
        $data['created_by_user_id'] = $userId;
        $data['updated_by_user_id'] = $userId;

        return ApprovalRoute::create($data);
    }

    /**
     * Update an existing ApprovalRoute.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(ApprovalRoute $route, array $data, int $userId): ApprovalRoute
    {
        if (isset($data['stages'])) {
            $data['stages'] = $this->normalizeStages($data['stages']);
        }
        $data['updated_by_user_id'] = $userId;

        $route->update($data);

        return $route->fresh();
    }

    /**
     * Soft-delete (deactivate) an ApprovalRoute.
     */
    public function deactivate(ApprovalRoute $route): ApprovalRoute
    {
        $route->update(['is_active' => false]);

        return $route->fresh();
    }

    // ---- Private helpers ----

    /**
     * Sort stages by order ascending.
     *
     * @param  list<array<string, mixed>>  $stages
     * @return list<array<string, mixed>>
     */
    private function normalizeStages(array $stages): array
    {
        usort($stages, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return array_values($stages);
    }
}
