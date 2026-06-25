/**
 * useRolesPermissions — load matrix, track diff, save per-role.
 */
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { useMutation } from '@/composables/async/useMutation'
import { accessControlApi } from '@/api/accessControl'
import { USER_ROLES, type UserRole } from '@/entities/user'
import { PERMISSION_GROUPS, type RolePermissionsMap } from '@/entities/accessControl'

/** Row in the permissions matrix DataTable */
export interface PermissionRow {
  permission: string
  groupKey: string
  /** role → checked */
  checked: Record<UserRole, boolean>
}

const EMPTY_MAP: RolePermissionsMap = {
  admin: [],
  director: [],
  lawyer: [],
  manager: [],
  accountant: [],
  cfo: [],
}

function buildRows(map: RolePermissionsMap): PermissionRow[] {
  return PERMISSION_GROUPS.flatMap((group) =>
    group.permissions.map((perm) => ({
      permission: perm,
      groupKey: group.key,
      checked: USER_ROLES.reduce(
        (acc, role) => {
          acc[role] = map[role]?.includes(perm) ?? false
          return acc
        },
        {} as Record<UserRole, boolean>,
      ),
    })),
  )
}

function rowsToMap(rows: PermissionRow[]): RolePermissionsMap {
  const result: RolePermissionsMap = {
    admin: [],
    director: [],
    lawyer: [],
    manager: [],
    accountant: [],
    cfo: [],
  }
  for (const row of rows) {
    for (const role of USER_ROLES) {
      if (row.checked[role]) {
        result[role].push(row.permission)
      }
    }
  }
  return result
}

export function useRolesPermissions() {
  const { t } = useI18n()
  const toast = useToast()

  const resource = useAsyncResource<RolePermissionsMap>({ ...EMPTY_MAP })
  /** The snapshot at load-time — used for reset */
  const snapshot = ref<RolePermissionsMap>({ ...EMPTY_MAP })
  const rows = ref<PermissionRow[]>([])

  const saveMutation = useMutation<void>()

  const isDirty = computed(() => {
    const current = rowsToMap(rows.value)
    for (const role of USER_ROLES) {
      if (role === 'admin') continue
      const a = [...(current[role] ?? [])].sort()
      const b = [...(snapshot.value[role] ?? [])].sort()
      if (JSON.stringify(a) !== JSON.stringify(b)) return true
    }
    return false
  })

  async function loadPermissions() {
    await resource.run(() => accessControlApi.getRolesPermissions(), {
      commit: (data) => {
        resource.data.value = data
        snapshot.value = JSON.parse(JSON.stringify(data)) as RolePermissionsMap
        rows.value = buildRows(data)
      },
    })
  }

  function resetPermissions() {
    rows.value = buildRows(snapshot.value)
  }

  function togglePermission(permission: string, role: UserRole, checked: boolean) {
    const row = rows.value.find((r) => r.permission === permission)
    if (row) {
      row.checked[role] = checked
    }
  }

  async function savePermissions() {
    const current = rowsToMap(rows.value)
    // Only send roles that differ from snapshot (skip admin — immutable)
    const promises: Promise<void>[] = []
    for (const role of USER_ROLES) {
      if (role === 'admin') continue
      const a = [...(current[role] ?? [])].sort()
      const b = [...(snapshot.value[role] ?? [])].sort()
      if (JSON.stringify(a) !== JSON.stringify(b)) {
        promises.push(
          accessControlApi.updateRolePermissions(role, { permissions: current[role] ?? [] }),
        )
      }
    }
    await saveMutation.run(async () => { await Promise.all(promises) }, {
      onSuccess: () => {
        snapshot.value = JSON.parse(JSON.stringify(current)) as RolePermissionsMap
        toast.add({ severity: 'success', summary: t('accessControl.roles.saveSuccess'), life: 3000 })
      },
      onError: () => {
        toast.add({ severity: 'error', summary: t('accessControl.roles.errorSave'), life: 4000 })
      },
    })
  }

  return {
    resource,
    rows,
    isDirty,
    saveMutation,
    PERMISSION_GROUPS,
    loadPermissions,
    resetPermissions,
    togglePermission,
    savePermissions,
  }
}
