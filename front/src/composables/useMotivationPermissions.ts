/**
 * Motivation-card permission gates.
 *
 * The backend contract (`motivation-cards-api-contract.md` §3.3–3.4) exposes two
 * granular permissions, mapped to roles on the backend:
 *   - `motivation.manage`     → admin, director            (constructor / plan authoring)
 *   - `motivation.status`     → accountant, director, admin (finalize / mark-paid)
 *
 * ⚠️ Integration note (flag for reviewer): the current frontend has NO
 * permissions array on the User entity and no `can()` helper — authorization is
 * role-based everywhere (`useSettings`, `SettingsSidebar`). Until `/api/me`
 * returns granular permissions, this composable is the SINGLE place the
 * permission→role mapping lives, so switching to a real `can('motivation.*')`
 * later touches one file. It is NOT scattered role-string comparisons in
 * components (which ARCHITECTURE.md forbids) — components call `canManage` /
 * `canTransition`.
 */
import { computed, type ComputedRef } from 'vue'
import { useUserStore } from '@/stores/user'
import type { UserRole } from '@/entities/user'

const MANAGE_ROLES: readonly UserRole[] = ['admin', 'director']
const STATUS_ROLES: readonly UserRole[] = ['accountant', 'director', 'admin']

export interface MotivationPermissions {
  /** `motivation.manage` — may open the constructor and save plans. */
  canManage: ComputedRef<boolean>
  /** `motivation.status` — may finalize / mark-paid a card. */
  canTransition: ComputedRef<boolean>
  /** May inspect other managers' cards (director/admin) in the cabinet. */
  canViewOthers: ComputedRef<boolean>
}

export function useMotivationPermissions(): MotivationPermissions {
  const userStore = useUserStore()

  const role = computed<UserRole | null>(() => userStore.getUserRole)

  const canManage = computed<boolean>(
    () => role.value != null && MANAGE_ROLES.includes(role.value),
  )

  const canTransition = computed<boolean>(
    () => role.value != null && STATUS_ROLES.includes(role.value),
  )

  const canViewOthers = computed<boolean>(
    () => role.value === 'admin' || role.value === 'director',
  )

  return { canManage, canTransition, canViewOthers }
}
