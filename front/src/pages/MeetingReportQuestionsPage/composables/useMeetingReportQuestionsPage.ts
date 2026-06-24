import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { useMutation } from '@/composables/async/useMutation'
import { activityApi } from '@/api/activity'
import { salesApi } from '@/api/sales'
import { useUserStore } from '@/stores/user'
import type {
  MeetingReportQuestionDto,
  SaveMeetingReportQuestionPayload,
} from '@/entities/activity'
import type { PipelineDto } from '@/entities/sales'

export const useMeetingReportQuestionsPage = () => {
  const { t } = useI18n()
  const toast = useToast()
  const confirm = useConfirm()
  const userStore = useUserStore()

  // ─── Gate (admin/director — mirrors the BE admin-write permission) ────────────
  const canManage = (() => {
    const role = userStore.getUserRole
    return role === 'admin' || role === 'director'
  })()

  // ─── Data ─────────────────────────────────────────────────────────────────────
  const questions = ref<MeetingReportQuestionDto[]>([])
  const pipelines = ref<PipelineDto[]>([])
  const loading = ref(false)

  async function fetchQuestions() {
    loading.value = true
    try {
      // null pipeline filter → registry returns ALL questions (global + per-pipeline).
      questions.value = await activityApi.listMeetingReportQuestions(null)
    } catch {
      toast.add({ severity: 'error', summary: t('common.loadError'), life: 3000 })
    } finally {
      loading.value = false
    }
  }

  async function fetchPipelines() {
    try {
      pipelines.value = await salesApi.getPipelines()
    } catch {
      // Non-fatal — the per-pipeline picker just degrades to «Все воронки».
      pipelines.value = []
    }
  }

  function pipelineName(pipelineId: number | null | undefined): string {
    if (pipelineId == null) return t('admin.meetingReportQuestions.global')
    return pipelines.value.find((p) => p.id === pipelineId)?.name
      ?? `#${pipelineId}`
  }

  void fetchQuestions()
  void fetchPipelines()

  // ─── Dialog ───────────────────────────────────────────────────────────────────
  const dialogVisible = ref(false)
  const editingQuestion = ref<MeetingReportQuestionDto | null>(null)

  function openCreate() {
    editingQuestion.value = null
    dialogVisible.value = true
  }

  function openEdit(question: MeetingReportQuestionDto) {
    editingQuestion.value = question
    dialogVisible.value = true
  }

  const saveMutation = useMutation<MeetingReportQuestionDto>()

  async function save(payload: SaveMeetingReportQuestionPayload) {
    await saveMutation.run(
      async () => {
        if (editingQuestion.value) {
          return await activityApi.updateMeetingReportQuestion(editingQuestion.value.id, payload)
        }
        return await activityApi.createMeetingReportQuestion(payload)
      },
      {
        onSuccess: () => {
          dialogVisible.value = false
          void fetchQuestions()
          toast.add({ severity: 'success', summary: t('common.saved'), life: 2000 })
        },
        onError: () => {
          toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
        },
      },
    )
  }

  // ─── Toggle active ────────────────────────────────────────────────────────────
  async function toggleActive(question: MeetingReportQuestionDto) {
    try {
      const updated = await activityApi.updateMeetingReportQuestion(question.id, {
        text: question.text,
        kind: question.kind,
        is_active: !(question.is_active ?? true),
      })
      const idx = questions.value.findIndex((q) => q.id === question.id)
      if (idx >= 0) questions.value[idx] = updated
    } catch {
      toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
    }
  }

  // ─── Delete ───────────────────────────────────────────────────────────────────
  function deleteQuestion(question: MeetingReportQuestionDto) {
    confirm.require({
      message: t('admin.meetingReportQuestions.deleteConfirm'),
      header: t('common.delete'),
      icon: 'pi pi-exclamation-triangle',
      accept: async () => {
        try {
          await activityApi.deleteMeetingReportQuestion(question.id)
          questions.value = questions.value.filter((q) => q.id !== question.id)
          toast.add({ severity: 'success', summary: t('common.deleted'), life: 2000 })
        } catch {
          toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
        }
      },
    })
  }

  return {
    questions,
    pipelines,
    loading,
    canManage,
    dialogVisible,
    editingQuestion,
    saveMutation,
    pipelineName,
    openCreate,
    openEdit,
    save,
    toggleActive,
    deleteQuestion,
  }
}
