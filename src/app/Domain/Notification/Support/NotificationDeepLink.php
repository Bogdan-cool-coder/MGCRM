<?php

declare(strict_types=1);

namespace App\Domain\Notification\Support;

/**
 * NotificationDeepLink — the single, safe source of truth for the in-app
 * notification `deep_link` (the path the flyout navigates to on click).
 *
 * Every deep link MUST resolve to a route that actually exists in the SPA
 * (front/src/router/routes/base.ts). A link into a dead/old route (e.g. the
 * legacy `/tasks`, `/admin/custom-fields`, `/profile`) is worse than no link:
 * the frontend guard silently drops it, so the user gets a click target that
 * does nothing. This class centralises the target_type→path mapping so a
 * notification can never be created with a stale or malformed path again.
 *
 * Contract:
 *   - a KNOWN target with an id  → the canonical front route for that entity;
 *   - an unknown/target-less item → `null` (the flyout shows no navigation,
 *     rather than a broken link).
 *
 * This is a leaf value object (no state, no I/O): pure string mapping only.
 */
final class NotificationDeepLink
{
    /**
     * Canonical front route for a polymorphic entity target.
     *
     * `$type` is the same string vocabulary used by Activity targets
     * (deal/company/contact) plus the entities that own notifications
     * (document). Anything outside the whitelist → null, so an unrecognised
     * target never produces a guessed/broken path.
     */
    public static function forTarget(?string $type, int|string|null $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $id = (string) $id;

        if ($id === '' || $id === '0') {
            return null;
        }

        return match ($type) {
            'deal' => '/deals/'.$id,
            'company' => '/companies/'.$id,
            'contact' => '/contacts/'.$id,
            'document' => '/documents/'.$id,
            default => null,
        };
    }

    /**
     * Route for a task/activity notification. Targeted activities
     * (deal/company/contact) deep-link straight to the target entity; a
     * standalone task (no target, or an unrecognised target) falls back to the
     * user's task board — which is `/my-tasks` (NOT the legacy `/tasks`).
     */
    public static function forActivity(?string $targetType, int|string|null $targetId): string
    {
        return self::forTarget($targetType, $targetId) ?? self::tasksBoard();
    }

    /** The canonical "my tasks" board route. */
    public static function tasksBoard(): string
    {
        return '/my-tasks';
    }

    /** The canonical document workspace route for a single document. */
    public static function document(int|string $documentId): string
    {
        // Non-null id guaranteed by the caller (a document notification always
        // has a document); forTarget still guards against 0/'' defensively.
        return self::forTarget('document', $documentId) ?? '/documents';
    }

    /** The canonical "my approvals" queue route. */
    public static function approvalsQueue(): string
    {
        return '/my-approvals';
    }
}
