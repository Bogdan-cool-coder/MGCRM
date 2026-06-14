/**
 * Course lessons composable — CRUD + reorder + publish.
 */
import { ref } from 'vue'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { useI18n } from 'vue-i18n'
import { onboardingAdminApi } from '@/api/onboardingAdmin'
import type { Lesson, LessonCreatePayload, LessonPatchPayload, LessonKind, CourseModule } from '@/entities/course'

export function useCourseLessons() {
  const { t } = useI18n()
  const toast = useToast()
  const confirm = useConfirm()

  // Lesson edit drawer
  const lessonDrawerVisible = ref(false)
  const editingLesson = ref<Lesson | null>(null)
  const editingModuleId = ref<number | null>(null)
  const newLessonKind = ref<LessonKind>('text')

  function openCreateLesson(moduleId: number, kind: LessonKind): void {
    editingModuleId.value = moduleId
    editingLesson.value = null
    newLessonKind.value = kind
    lessonDrawerVisible.value = true
  }

  function openEditLesson(moduleId: number, lesson: Lesson): void {
    editingModuleId.value = moduleId
    editingLesson.value = lesson
    newLessonKind.value = lesson.kind
    lessonDrawerVisible.value = true
  }

  async function saveLesson(
    modules: CourseModule[],
    payload: LessonCreatePayload | LessonPatchPayload,
  ): Promise<CourseModule[]> {
    const moduleId = editingModuleId.value
    if (!moduleId) return modules

    try {
      if (editingLesson.value) {
        const updated = await onboardingAdminApi.patchLesson(moduleId, editingLesson.value.id, payload as LessonPatchPayload)
        lessonDrawerVisible.value = false
        toast.add({ severity: 'success', summary: t('common.saved'), life: 3000 })
        return modules.map((m) => {
          if (m.id !== moduleId) return m
          return {
            ...m,
            lessons: (m.lessons ?? []).map((l) => (l.id === updated.id ? updated : l)),
          }
        })
      } else {
        const created = await onboardingAdminApi.createLesson(moduleId, payload as LessonCreatePayload)
        lessonDrawerVisible.value = false
        toast.add({ severity: 'success', summary: t('common.saved'), life: 3000 })
        return modules.map((m) => {
          if (m.id !== moduleId) return m
          return { ...m, lessons: [...(m.lessons ?? []), created] }
        })
      }
    } catch {
      toast.add({ severity: 'error', summary: t('common.error'), life: 4000 })
      return modules
    }
  }

  async function uploadPdf(lessonId: number, file: File): Promise<Lesson | null> {
    try {
      const updated = await onboardingAdminApi.uploadLessonPdf(lessonId, file)
      toast.add({ severity: 'success', summary: t('common.saved'), life: 3000 })
      return updated
    } catch {
      toast.add({ severity: 'error', summary: t('common.error'), life: 4000 })
      return null
    }
  }

  function deleteLesson(moduleId: number, lesson: Lesson, modules: CourseModule[]): Promise<CourseModule[]> {
    return new Promise((resolve) => {
      confirm.require({
        message: t('onboarding.builder.lesson.deleteConfirm'),
        header: t('common.delete'),
        icon: 'pi pi-trash',
        accept: async () => {
          try {
            await onboardingAdminApi.deleteLesson(moduleId, lesson.id)
            toast.add({ severity: 'success', summary: t('common.deleted'), life: 3000 })
            resolve(
              modules.map((m) => {
                if (m.id !== moduleId) return m
                return { ...m, lessons: (m.lessons ?? []).filter((l) => l.id !== lesson.id) }
              }),
            )
          } catch {
            toast.add({ severity: 'error', summary: t('common.error'), life: 4000 })
            resolve(modules)
          }
        },
        reject: () => resolve(modules),
      })
    })
  }

  async function moveLesson(
    moduleId: number,
    index: number,
    direction: 'up' | 'down',
    modules: CourseModule[],
  ): Promise<CourseModule[]> {
    const mod = modules.find((m) => m.id === moduleId)
    if (!mod) return modules

    const lessons = [...(mod.lessons ?? [])]
    const targetIdx = direction === 'up' ? index - 1 : index + 1
    if (targetIdx < 0 || targetIdx >= lessons.length) return modules
    const tmpLesson = lessons[index]!
    lessons[index] = lessons[targetIdx]!
    lessons[targetIdx] = tmpLesson
    const reordered = lessons.map((l, i) => ({ ...l, sort_order: i + 1 }))

    const newModules = modules.map((m) =>
      m.id === moduleId ? { ...m, lessons: reordered } : m,
    )

    try {
      await onboardingAdminApi.reorderLessons(
        moduleId,
        reordered.map((l) => ({ id: l.id, sort_order: l.sort_order })),
      )
    } catch {
      toast.add({ severity: 'error', summary: t('common.error'), life: 4000 })
      return modules
    }

    return newModules
  }

  async function publishLesson(moduleId: number, lesson: Lesson, modules: CourseModule[]): Promise<CourseModule[]> {
    try {
      const updated = await onboardingAdminApi.publishLesson(moduleId, lesson.id)
      // Sync editingLesson so the drawer button reflects the new state immediately
      if (editingLesson.value?.id === updated.id) {
        editingLesson.value = updated
      }
      toast.add({ severity: 'success', summary: t('onboarding.builder.lesson.published'), life: 3000 })
      return modules.map((m) => {
        if (m.id !== moduleId) return m
        return { ...m, lessons: (m.lessons ?? []).map((l) => (l.id === updated.id ? updated : l)) }
      })
    } catch {
      toast.add({ severity: 'error', summary: t('common.error'), life: 4000 })
      return modules
    }
  }

  async function unpublishLesson(moduleId: number, lesson: Lesson, modules: CourseModule[]): Promise<CourseModule[]> {
    try {
      const updated = await onboardingAdminApi.unpublishLesson(moduleId, lesson.id)
      // Sync editingLesson so the drawer button reflects the new state immediately
      if (editingLesson.value?.id === updated.id) {
        editingLesson.value = updated
      }
      toast.add({ severity: 'success', summary: t('onboarding.builder.lesson.unpublished'), life: 3000 })
      return modules.map((m) => {
        if (m.id !== moduleId) return m
        return { ...m, lessons: (m.lessons ?? []).map((l) => (l.id === updated.id ? updated : l)) }
      })
    } catch {
      toast.add({ severity: 'error', summary: t('common.error'), life: 4000 })
      return modules
    }
  }

  return {
    lessonDrawerVisible,
    editingLesson,
    editingModuleId,
    newLessonKind,
    openCreateLesson,
    openEditLesson,
    saveLesson,
    uploadPdf,
    deleteLesson,
    moveLesson,
    publishLesson,
    unpublishLesson,
  }
}
