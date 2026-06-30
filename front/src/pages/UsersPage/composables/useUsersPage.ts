import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { useMutation } from '@/composables/async/useMutation'
import { adminUsersApi } from '@/api/adminUsers'
import { usersApi, type UserOptionDto } from '@/api/users'
import { useUserStore } from '@/stores/user'
import type {
  AdminUserDto,
  CreateAdminUserPayload,
  DepartmentOption,
  GetAdminUsersParams,
  UpdateAdminUserPayload,
} from '@/entities/adminUser'
import type { UserRole } from '@/entities/user'

export const useUsersPage = () => {
  const { t } = useI18n()
  const toast = useToast()
  const confirm = useConfirm()
  const userStore = useUserStore()

  // ─── Gate ────────────────────────────────────────────────────────────────────
  const canManage = computed(() => {
    const role = userStore.getUserRole
    return role === 'admin' || role === 'director'
  })

  // ─── Filters ─────────────────────────────────────────────────────────────────
  const searchFilter = ref('')
  const roleFilter = ref<UserRole | null>(null)
  const departmentFilter = ref<number | null>(null)
  const isActiveFilter = ref<boolean | null>(null)
  const currentPage = ref(1)
  const perPage = 25

  // ─── Data ─────────────────────────────────────────────────────────────────────
  const users = ref<AdminUserDto[]>([])
  const total = ref(0)
  const lastPage = ref(1)
  const loading = ref(false)

  // ─── Departments (for Select dropdown) ───────────────────────────────────────
  const departments = ref<DepartmentOption[]>([])
  const departmentsLoading = ref(false)

  async function fetchDepartments() {
    if (departments.value.length > 0) return
    departmentsLoading.value = true
    try {
      departments.value = await adminUsersApi.getDepartments()
    } catch {
      // non-critical, fallback to no department filter
    } finally {
      departmentsLoading.value = false
    }
  }

  void fetchDepartments()

  // ─── Manager options (for the line-manager dropdown) ──────────────────────────
  const managerCandidates = ref<UserOptionDto[]>([])
  const managersLoading = ref(false)

  async function fetchManagers() {
    if (managerCandidates.value.length > 0) return
    managersLoading.value = true
    try {
      managerCandidates.value = await usersApi.getUsers()
    } catch {
      // non-critical
    } finally {
      managersLoading.value = false
    }
  }

  void fetchManagers()

  // ─── Fetch users ──────────────────────────────────────────────────────────────
  async function fetchUsers() {
    loading.value = true
    try {
      const params: GetAdminUsersParams = {
        page: currentPage.value,
        per_page: perPage,
      }
      if (searchFilter.value.trim()) params.search = searchFilter.value.trim()
      if (roleFilter.value) params.role = roleFilter.value
      if (departmentFilter.value !== null) params.department_id = departmentFilter.value
      if (isActiveFilter.value !== null) params.is_active = isActiveFilter.value

      const result = await adminUsersApi.getUsers(params)
      users.value = result.data
      total.value = result.meta.total
      lastPage.value = result.meta.last_page
    } catch {
      toast.add({ severity: 'error', summary: t('common.loadError'), life: 3000 })
    } finally {
      loading.value = false
    }
  }

  watch([searchFilter, roleFilter, departmentFilter, isActiveFilter], () => {
    currentPage.value = 1
    void fetchUsers()
  })
  watch(currentPage, () => void fetchUsers())

  void fetchUsers()

  // ─── Dialog (create + edit share one dialog) ──────────────────────────────────
  const dialogVisible = ref(false)
  const editingUser = ref<AdminUserDto | null>(null)

  function openCreate() {
    editingUser.value = null
    dialogVisible.value = true
  }

  function openEdit(user: AdminUserDto) {
    editingUser.value = user
    dialogVisible.value = true
  }

  const createMutation = useMutation<AdminUserDto>()

  async function createUser(payload: CreateAdminUserPayload) {
    await createMutation.run(
      () => adminUsersApi.createUser(payload),
      {
        onSuccess: () => {
          dialogVisible.value = false
          void fetchUsers()
          toast.add({ severity: 'success', summary: t('admin.users.created'), life: 2500 })
        },
        onError: (err) => {
          // 422 validation errors are shown in the dialog
          const status = (err as { response?: { status?: number } })?.response?.status
          if (status !== 422) {
            toast.add({ severity: 'error', summary: t('errors.unknown'), life: 3000 })
          }
        },
      },
    )
  }

  async function updateUser(payload: UpdateAdminUserPayload) {
    const id = editingUser.value?.id
    if (id === undefined) return
    await createMutation.run(
      () => adminUsersApi.updateUser(id, payload),
      {
        onSuccess: () => {
          dialogVisible.value = false
          editingUser.value = null
          void fetchUsers()
          toast.add({ severity: 'success', summary: t('admin.users.updated'), life: 2500 })
        },
        onError: (err) => {
          const status = (err as { response?: { status?: number } })?.response?.status
          if (status !== 422) {
            toast.add({ severity: 'error', summary: t('errors.unknown'), life: 3000 })
          }
        },
      },
    )
  }

  // ─── Reset password ───────────────────────────────────────────────────────────
  const resetPasswordDialogVisible = ref(false)
  const resetPasswordValue = ref('')

  function confirmResetPassword(user: AdminUserDto) {
    confirm.require({
      message: t('admin.users.resetPassword.confirm', { name: user.full_name }),
      header: t('admin.users.resetPassword.title'),
      icon: 'pi pi-key',
      acceptProps: { severity: 'warn', label: t('admin.users.resetPassword.action') },
      rejectProps: { severity: 'secondary', outlined: true, label: t('common.cancel') },
      accept: async () => {
        try {
          const result = await adminUsersApi.resetUserPassword(user.id)
          // Store generated password locally — shown once, never logged, not persisted
          resetPasswordValue.value = result.password
          resetPasswordDialogVisible.value = true
        } catch (err) {
          const status = (err as { response?: { status?: number } })?.response?.status
          const summary =
            status === 422
              ? t('admin.users.resetPassword.cannotReset')
              : t('errors.unknown')
          toast.add({ severity: 'error', summary, life: 4000 })
        }
      },
    })
  }

  function onResetPasswordDialogHide() {
    // Wipe password from memory when dialog closes
    resetPasswordValue.value = ''
  }

  // ─── Deactivate / reactivate ───────────────────────────────────────────────────
  function confirmDeactivate(user: AdminUserDto) {
    confirm.require({
      message: t('admin.users.deactivateConfirm', { name: user.full_name }),
      header: t('admin.users.deactivateTitle'),
      icon: 'pi pi-exclamation-triangle',
      acceptProps: { severity: 'danger', label: t('admin.users.deactivate') },
      rejectProps: { severity: 'secondary', outlined: true, label: t('common.cancel') },
      accept: async () => {
        try {
          await adminUsersApi.deactivateUser(user.id)
          void fetchUsers()
          toast.add({ severity: 'success', summary: t('admin.users.deactivated'), life: 2500 })
        } catch (err) {
          const status = (err as { response?: { status?: number } })?.response?.status
          const summary = status === 422 ? t('admin.users.cannotDeactivateSelf') : t('errors.unknown')
          toast.add({ severity: 'error', summary, life: 3000 })
        }
      },
    })
  }

  async function reactivate(user: AdminUserDto) {
    try {
      await adminUsersApi.updateUser(user.id, { is_active: true })
      void fetchUsers()
      toast.add({ severity: 'success', summary: t('admin.users.activated'), life: 2500 })
    } catch {
      toast.add({ severity: 'error', summary: t('errors.unknown'), life: 3000 })
    }
  }

  // ─── Options ──────────────────────────────────────────────────────────────────
  const roleOptions = computed(() => [
    { label: t('roles.admin'), value: 'admin' as UserRole },
    { label: t('roles.director'), value: 'director' as UserRole },
    { label: t('roles.manager'), value: 'manager' as UserRole },
    { label: t('roles.lawyer'), value: 'lawyer' as UserRole },
    { label: t('roles.accountant'), value: 'accountant' as UserRole },
    { label: t('roles.cfo'), value: 'cfo' as UserRole },
  ])

  const isActiveOptions = computed(() => [
    { label: t('admin.users.filters.active'), value: true },
    { label: t('admin.users.filters.inactive'), value: false },
  ])

  return {
    // State
    users,
    total,
    lastPage,
    loading,
    currentPage,
    perPage,
    canManage,
    // Filters
    searchFilter,
    roleFilter,
    departmentFilter,
    isActiveFilter,
    // Departments
    departments,
    departmentsLoading,
    // Managers
    managerCandidates,
    managersLoading,
    // Dialog
    dialogVisible,
    editingUser,
    openCreate,
    openEdit,
    createUser,
    updateUser,
    createMutation,
    // Row actions
    confirmDeactivate,
    reactivate,
    confirmResetPassword,
    // Reset password result dialog
    resetPasswordDialogVisible,
    resetPasswordValue,
    onResetPasswordDialogHide,
    // Options
    roleOptions,
    isActiveOptions,
  }
}
