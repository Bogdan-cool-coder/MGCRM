<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Generic read-access ACL for "config entities" — Report, Widget, Dashboard —
 * which all share the same visibility shape: a company-scoped jsonb-config
 * resource that may be a system resource (visible to everyone), published to
 * the whole company, or personal to its author.
 *
 * The rules (kept identical to the original ReportController ACL):
 *  - A resource is reachable only if it is a system resource (is_system) OR its
 *    company_id matches the active company. Cross-company access is permitted
 *    only for superadmins.
 *  - Viewers additionally require non-system resources to be published
 *    (is_published).
 *
 * Entities consumed here are expected to expose `is_system`, `is_published`,
 * `company_id` and `user_id` attributes (Report / Widget / Dashboard all do).
 *
 * Denials throw HttpResponseException(403) so callers don't have to unwrap a
 * return value. A missing entity (when looked up by id) is treated as
 * forbidden, not 404, so the endpoint never leaks whether a given id exists in
 * another company.
 */
trait AssertsConfigEntityReadAccess
{
    /**
     * Apply the shared visibility rules to a resolved entity.
     *
     * @param  \App\Models\User  $user
     *
     * @throws HttpResponseException 403 when the entity is not readable.
     */
    protected function guardReadable(Model $entity, $user, int $activeCompanyId): void
    {
        if ((int) $entity->company_id !== $activeCompanyId && !$entity->is_system) {
            if ($user->role !== 'superadmin') {
                $this->denyConfigEntityAccess();
            }
        }

        if (!$entity->is_system && $user->role === 'viewer') {
            if (!$entity->is_published) {
                $this->denyConfigEntityAccess();
            }
        }
    }

    /**
     * Variant for callers that hold an entity *id* rather than a resolved
     * model. Loads the entity via the given class, applies the same ACL, and
     * hands back the resolved model.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @param  \App\Models\User       $user
     * @return TModel
     *
     * @throws HttpResponseException 403 when the entity is missing or not readable.
     */
    protected function assertEntityIdReadable(string $modelClass, int $entityId, $user, int $activeCompanyId): Model
    {
        /** @var TModel|null $entity */
        $entity = $modelClass::find($entityId);

        if ($entity === null) {
            $this->denyConfigEntityAccess();
        }

        $this->guardReadable($entity, $user, $activeCompanyId);

        return $entity;
    }

    /**
     * Single throw-site so the 403 shape lives in exactly one place.
     *
     * @throws HttpResponseException
     */
    private function denyConfigEntityAccess(): never
    {
        throw new HttpResponseException(
            response()->json(['message' => __('auth.forbidden')], 403)
        );
    }
}
