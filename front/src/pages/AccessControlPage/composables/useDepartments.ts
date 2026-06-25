/**
 * useDepartments — CRUD composable for the Departments tab.
 * Builds DeptTreeNode[] from flat GET /api/admin/departments list.
 */
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { useMutation } from '@/composables/async/useMutation'
import { accessControlApi } from '@/api/accessControl'
import { usersApi, type UserOptionDto } from '@/api/users'
import {
  buildDeptTree,
  maxTreeDepth,
  type CreateDepartmentPayload,
  type DepartmentDto,
  type DepartmentMemberDto,
  type DeptTreeNode,
  type UpdateDepartmentPayload,
} from '@/entities/accessControl'

export type PanelMode = 'create' | 'edit'

export interface DeptPanelState {
  visible: boolean
  mode: PanelMode
  dept: DepartmentDto | null
  members: DepartmentMemberDto[]
}

export function useDepartments() {
  const { t } = useI18n()
  const toast = useToast()

  // ─── data ──────────────────────────────────────────────────────────────────
  const depts = useAsyncResource<DepartmentDto[]>([])
  const usersResource = useAsyncResource<UserOptionDto[]>([])
  const membersResource = useAsyncResource<DepartmentMemberDto[]>([])

  const searchQuery = ref('')
  const viewMode = ref<'tree' | 'chart'>('tree')

  const panel = ref<DeptPanelState>({
    visible: false,
    mode: 'create',
    dept: null,
    members: [],
  })

  // form state inside panel
  const formName = ref('')
  const formParentId = ref<number | null>(null)
  const formManagerId = ref<number | null>(null)

  // pending member selections (MultiSelect)
  const memberPickerVisible = ref(false)
  const selectedMemberIds = ref<number[]>([])

  // ─── computed ──────────────────────────────────────────────────────────────
  const treeNodes = computed<DeptTreeNode[]>(() => buildDeptTree(depts.data.value))

  const filteredTreeNodes = computed<DeptTreeNode[]>(() => {
    if (!searchQuery.value.trim()) return treeNodes.value
    const q = searchQuery.value.toLowerCase()
    function filterNodes(nodes: DeptTreeNode[]): DeptTreeNode[] {
      return nodes.reduce<DeptTreeNode[]>((acc, n) => {
        const childMatches = filterNodes(n.children)
        if (n.label.toLowerCase().includes(q) || childMatches.length > 0) {
          acc.push({ ...n, children: childMatches })
        }
        return acc
      }, [])
    }
    return filterNodes(treeNodes.value)
  })

  const treeDepth = computed(() => maxTreeDepth(treeNodes.value))
  const depthWarning = computed(() => treeDepth.value > 4)

  const parentOptions = computed(() => {
    const options: { id: number | null; name: string }[] = [
      { id: null, name: t('accessControl.departments.parentRoot') },
    ]
    for (const d of depts.data.value) {
      if (d.id !== panel.value.dept?.id) {
        options.push({ id: d.id, name: d.name })
      }
    }
    return options
  })

  const userOptions = computed(() => usersResource.data.value)

  const availableMembersToAdd = computed(() =>
    usersResource.data.value.filter(
      (u) => !membersResource.data.value.some((m) => m.id === u.id),
    ),
  )

  // ─── mutations ─────────────────────────────────────────────────────────────
  const saveMutation = useMutation<DepartmentDto>()
  const deleteMutation = useMutation<void>()
  const membersMutation = useMutation<void>()

  // ─── load ──────────────────────────────────────────────────────────────────
  async function loadDepartments() {
    await depts.run(() => accessControlApi.getDepartments())
  }

  async function loadUsers() {
    await usersResource.run(() => usersApi.getUsers())
  }

  async function loadMembers(deptId: number) {
    await membersResource.run(() => accessControlApi.getDepartmentMembers(deptId))
  }

  // ─── panel open ────────────────────────────────────────────────────────────
  function openCreate() {
    panel.value = { visible: true, mode: 'create', dept: null, members: [] }
    formName.value = ''
    formParentId.value = null
    formManagerId.value = null
    membersResource.reset([])
    if (usersResource.data.value.length === 0) loadUsers()
  }

  async function openEdit(dept: DepartmentDto) {
    panel.value = { visible: true, mode: 'edit', dept, members: [] }
    formName.value = dept.name
    formParentId.value = dept.parent_id
    formManagerId.value = dept.manager_id
    if (usersResource.data.value.length === 0) await loadUsers()
    await loadMembers(dept.id)
    panel.value.members = membersResource.data.value
  }

  function closePanel() {
    panel.value.visible = false
    memberPickerVisible.value = false
    selectedMemberIds.value = []
  }

  // ─── save ──────────────────────────────────────────────────────────────────
  async function saveDept() {
    if (!formName.value.trim()) return

    if (panel.value.mode === 'create') {
      const payload: CreateDepartmentPayload = {
        name: formName.value.trim(),
        parent_id: formParentId.value,
        manager_id: formManagerId.value,
      }
      await saveMutation.run(() => accessControlApi.createDepartment(payload), {
        onSuccess: () => {
          toast.add({ severity: 'success', summary: t('accessControl.departments.saveSuccess'), life: 3000 })
          closePanel()
          loadDepartments()
        },
        onError: () => {
          toast.add({ severity: 'error', summary: t('accessControl.departments.errorSave'), life: 4000 })
        },
      })
    } else if (panel.value.dept) {
      const payload: UpdateDepartmentPayload = {
        name: formName.value.trim(),
        parent_id: formParentId.value,
        manager_id: formManagerId.value,
      }
      await saveMutation.run(() => accessControlApi.updateDepartment(panel.value.dept!.id, payload), {
        onSuccess: () => {
          toast.add({ severity: 'success', summary: t('accessControl.departments.saveSuccess'), life: 3000 })
          closePanel()
          loadDepartments()
        },
        onError: () => {
          toast.add({ severity: 'error', summary: t('accessControl.departments.errorSave'), life: 4000 })
        },
      })
    }
  }

  // ─── delete ────────────────────────────────────────────────────────────────
  async function deleteDept(dept: DepartmentDto) {
    await deleteMutation.run(() => accessControlApi.deleteDepartment(dept.id), {
      onSuccess: () => {
        toast.add({ severity: 'success', summary: t('accessControl.departments.deleteSuccess'), life: 3000 })
        if (panel.value.dept?.id === dept.id) closePanel()
        loadDepartments()
      },
      onError: () => {
        toast.add({ severity: 'error', summary: t('accessControl.departments.errorDelete'), life: 4000 })
      },
    })
  }

  // ─── members ───────────────────────────────────────────────────────────────
  async function addMembers() {
    if (!panel.value.dept || selectedMemberIds.value.length === 0) return
    await membersMutation.run(
      async () => {
        await accessControlApi.addDepartmentMembers(panel.value.dept!.id, {
          user_ids: selectedMemberIds.value,
        })
      },
      {
        onSuccess: async () => {
          selectedMemberIds.value = []
          memberPickerVisible.value = false
          await loadMembers(panel.value.dept!.id)
          panel.value.members = membersResource.data.value
        },
        onError: () => {
          toast.add({ severity: 'error', summary: t('accessControl.departments.errorSave'), life: 4000 })
        },
      },
    )
  }

  async function removeMember(member: DepartmentMemberDto) {
    if (!panel.value.dept) return
    await membersMutation.run(
      () => accessControlApi.removeDepartmentMember(panel.value.dept!.id, member.id),
      {
        onSuccess: async () => {
          await loadMembers(panel.value.dept!.id)
          panel.value.members = membersResource.data.value
        },
        onError: () => {
          toast.add({ severity: 'error', summary: t('accessControl.departments.errorSave'), life: 4000 })
        },
      },
    )
  }

  return {
    // state
    depts,
    searchQuery,
    viewMode,
    panel,
    formName,
    formParentId,
    formManagerId,
    memberPickerVisible,
    selectedMemberIds,
    // computed
    filteredTreeNodes,
    depthWarning,
    parentOptions,
    userOptions,
    availableMembersToAdd,
    // mutations
    saveMutation,
    deleteMutation,
    membersMutation,
    // actions
    loadDepartments,
    loadUsers,
    openCreate,
    openEdit,
    closePanel,
    saveDept,
    deleteDept,
    addMembers,
    removeMember,
  }
}
