/**
 * Course modules composable — CRUD + reorder.
 */
import { ref } from 'vue'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { useI18n } from 'vue-i18n'
import { onboardingAdminApi } from '@/api/onboardingAdmin'
import type { CourseModule } from '@/entities/course'

export function useCourseModules(courseId: number) {
  const { t } = useI18n()
  const toast = useToast()
  const confirm = useConfirm()

  const modules = ref<CourseModule[]>([])
  const loadingModules = ref(false)

  // Module edit dialog
  const moduleDialogVisible = ref(false)
  const editingModule = ref<CourseModule | null>(null)

  async function loadModules(): Promise<void> {
    loadingModules.value = true
    try {
      modules.value = await onboardingAdminApi.getModules(courseId)
    } catch {
      toast.add({ severity: 'error', summary: t('common.loadError'), life: 4000 })
    } finally {
      loadingModules.value = false
    }
  }

  function openCreateModule(): void {
    editingModule.value = null
    moduleDialogVisible.value = true
  }

  function openEditModule(mod: CourseModule): void {
    editingModule.value = mod
    moduleDialogVisible.value = true
  }

  async function saveModule(title: string): Promise<void> {
    try {
      if (editingModule.value) {
        const updated = await onboardingAdminApi.patchModule(courseId, editingModule.value.id, { title })
        const idx = modules.value.findIndex((m) => m.id === updated.id)
        if (idx !== -1) modules.value[idx] = { ...modules.value[idx], ...updated }
      } else {
        const created = await onboardingAdminApi.createModule(courseId, { title })
        created.lessons = []
        modules.value.push(created)
      }
      moduleDialogVisible.value = false
      toast.add({ severity: 'success', summary: t('common.saved'), life: 3000 })
    } catch {
      toast.add({ severity: 'error', summary: t('common.error'), life: 4000 })
    }
  }

  function deleteModule(mod: CourseModule): void {
    confirm.require({
      message: mod.lessons?.length
        ? t('onboarding.builder.module.deleteConfirm')
        : t('onboarding.builder.module.deleteConfirm'),
      header: t('common.delete'),
      icon: 'pi pi-trash',
      accept: async () => {
        try {
          await onboardingAdminApi.deleteModule(courseId, mod.id)
          modules.value = modules.value.filter((m) => m.id !== mod.id)
          toast.add({ severity: 'success', summary: t('common.deleted'), life: 3000 })
        } catch {
          toast.add({ severity: 'error', summary: t('common.error'), life: 4000 })
        }
      },
    })
  }

  async function moveModule(index: number, direction: 'up' | 'down'): Promise<void> {
    const mods = [...modules.value]
    const targetIdx = direction === 'up' ? index - 1 : index + 1
    if (targetIdx < 0 || targetIdx >= mods.length) return
    const tmpMod = mods[index]!
    mods[index] = mods[targetIdx]!
    mods[targetIdx] = tmpMod
    modules.value = mods.map((m, i) => ({ ...m, sort_order: i + 1 }))
    try {
      await onboardingAdminApi.reorderModules(
        courseId,
        modules.value.map((m) => ({ id: m.id, sort_order: m.sort_order })),
      )
    } catch {
      toast.add({ severity: 'error', summary: t('common.error'), life: 4000 })
      void loadModules()
    }
  }

  return {
    modules,
    loadingModules,
    moduleDialogVisible,
    editingModule,
    loadModules,
    openCreateModule,
    openEditModule,
    saveModule,
    deleteModule,
    moveModule,
  }
}
