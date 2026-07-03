/**
 * Plan-targets permission gate.
 *
 * The backend contract (`plan-targets-api-contract.md` §8) adds one granular
 * permission mapped to roles server-side:
 *   - `plans.manage` → admin, director  (plan authoring / matrix edit)
 *
 * ⚠️ Integration note (flag for reviewer): the frontend still has no permissions
 * array on the User entity and no real `can()` helper — authorization is
 * role-based everywhere (same situation as `useMotivationPermissions`). Until
 * `/api/me` returns granular permissions, this composable is the SINGLE place
 * the `plans.manage` → role mapping lives, so switching to a real
 * `can('plans.manage')` later touches one file. Components call `canManagePlans`,
 * never scattered role-string comparisons (which ARCHITECTURE.md forbids).
 *
 * Note: read access to the matrix/reports is visibility-scoped server-side, NOT
 * gated — the «Планы» tab visibility uses `canManagePlans`; the matrix itself
 * additionally honours `meta.can_edit` from the API so a role change on the
 * backend can't be bypassed by the FE mapping.
 */
import { computed, type ComputedRef } from 'vue'
import { useUserStore } from '@/stores/user'
import type { UserRole } from '@/entities/user'

const MANAGE_ROLES: readonly UserRole[] = ['admin', 'director']

export interface PlanPermissions {
  /** `plans.manage` — may see & edit the «Планы» tab (matrix authoring). */
  canManagePlans: ComputedRef<boolean>
}

export function usePlanPermissions(): PlanPermissions {
  const userStore = useUserStore()

  const role = computed<UserRole | null>(() => userStore.getUserRole)

  const canManagePlans = computed<boolean>(
    () => role.value != null && MANAGE_ROLES.includes(role.value),
  )

  return { canManagePlans }
}
