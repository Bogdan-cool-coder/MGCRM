<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Data;

use App\Http\Requests\Onboarding\HrProgressRequest;

/**
 * Immutable value object for HR-dashboard query filters.
 *
 * Mirrors the DashboardFilters pattern from S1.7 (DDD Data layer).
 */
final readonly class HrDashboardFilters
{
    public function __construct(
        public readonly ?int $userId,
        public readonly ?int $courseId,
        public readonly ?string $status,
        public readonly bool $includeArchived,
        public readonly string $sortBy,
        public readonly string $sortDir,
    ) {}

    public static function fromRequest(HrProgressRequest $request): self
    {
        return new self(
            userId: $request->filled('user_id') ? (int) $request->input('user_id') : null,
            courseId: $request->filled('course_id') ? (int) $request->input('course_id') : null,
            status: $request->filled('status') ? $request->string('status')->toString() : null,
            includeArchived: (bool) $request->boolean('include_archived', false),
            sortBy: $request->string('sort_by', 'updated_at')->toString(),
            sortDir: $request->string('sort_dir', 'desc')->toString(),
        );
    }

    /**
     * Build from a plain array (used by ProgressService::getHrDashboard stub delegate).
     *
     * @param  array<string, mixed>  $filters
     */
    public static function fromArray(array $filters): self
    {
        return new self(
            userId: isset($filters['user_id']) ? (int) $filters['user_id'] : null,
            courseId: isset($filters['course_id']) ? (int) $filters['course_id'] : null,
            status: $filters['status'] ?? null,
            includeArchived: (bool) ($filters['include_archived'] ?? false),
            sortBy: $filters['sort_by'] ?? 'updated_at',
            sortDir: $filters['sort_dir'] ?? 'desc',
        );
    }
}
