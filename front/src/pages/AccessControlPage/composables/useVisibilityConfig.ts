/**
 * useVisibilityConfig — load and save role visibility scopes.
 */
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { useMutation } from '@/composables/async/useMutation'
import { accessControlApi } from '@/api/accessControl'
import { USER_ROLES, type UserRole } from '@/entities/user'
import type {
  VisibilityConfigMap,
  VisibilityConfigRow,
  VisibilityScope,
} from '@/entities/accessControl'

const DEFAULT_CONFIG: VisibilityConfigMap = {
  admin: 'all',
  director: 'all',
  lawyer: 'all',
  manager: 'own',
  accountant: 'own',
  cfo: 'own',
}

export function useVisibilityConfig() {
  const { t } = useI18n()
  const toast = useToast()

  const resource = useAsyncResource<VisibilityConfigMap>({ ...DEFAULT_CONFIG })
  const snapshot = ref<VisibilityConfigMap>({ ...DEFAULT_CONFIG })
  const rows = ref<VisibilityConfigRow[]>(buildRows(DEFAULT_CONFIG))

  const saveMutation = useMutation<VisibilityConfigMap>()

  const isDirty = computed(() => {
    for (const row of rows.value) {
      if (row.scope !== snapshot.value[row.role]) return true
    }
    return false
  })

  function buildRows(config: VisibilityConfigMap): VisibilityConfigRow[] {
    return USER_ROLES.map((role) => ({
      role,
      scope: config[role] ?? 'own',
    }))
  }

  async function loadConfig() {
    await resource.run(() => accessControlApi.getVisibilityConfig(), {
      commit: (data) => {
        resource.data.value = data
        snapshot.value = { ...data }
        rows.value = buildRows(data)
      },
    })
  }

  function resetConfig() {
    rows.value = buildRows(snapshot.value)
  }

  function setScope(role: UserRole, scope: VisibilityScope) {
    const row = rows.value.find((r) => r.role === role)
    if (row) row.scope = scope
  }

  async function saveConfig() {
    const payload = rows.value.reduce<Partial<VisibilityConfigMap>>((acc, row) => {
      acc[row.role] = row.scope
      return acc
    }, {})

    await saveMutation.run(() => accessControlApi.updateVisibilityConfig(payload), {
      onSuccess: (data) => {
        snapshot.value = { ...data }
        rows.value = buildRows(data)
        toast.add({ severity: 'success', summary: t('accessControl.visibility.saveSuccess'), life: 3000 })
      },
      onError: () => {
        toast.add({ severity: 'error', summary: t('accessControl.visibility.errorSave'), life: 4000 })
      },
    })
  }

  return {
    resource,
    rows,
    isDirty,
    saveMutation,
    loadConfig,
    resetConfig,
    setScope,
    saveConfig,
  }
}
