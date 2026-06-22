import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useMutation } from '@/composables/async/useMutation'
import { adminUsersApi } from '@/api/adminUsers'
import { useUserStore } from '@/stores/user'
import type { AdminUserDto, CreateAdminUserPayload, DepartmentOption, GetAdminUsersParams } from '@/entities/adminUser'
import type { UserRole } from '@/entities/user'

export const useUsersPage = () => {
  const { t } = useI18n()
  const toast = useToast()
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

  // ─── Dialog ───────────────────────────────────────────────────────────────────
  const dialogVisible = ref(false)

  function openCreate() {
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
    // Dialog
    dialogVisible,
    openCreate,
    createUser,
    createMutation,
    // Options
    roleOptions,
    isActiveOptions,
  }
}
