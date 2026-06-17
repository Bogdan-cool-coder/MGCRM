import { ref } from 'vue'
import { useToast } from 'primevue/usetoast'
import { onboardingStudentApi } from '@/api/onboardingStudent'
import type { AiTutorMessage } from '@/api/onboardingStudent'
import { useConfirm } from 'primevue/useconfirm'

export interface ChatMessage {
  id: string
  role: 'user' | 'assistant'
  content: string
  created_at: string
}

export function useAiTutor(lessonId: number) {
  const toast = useToast()
  const confirm = useConfirm()

  const messages = ref<ChatMessage[]>([])
  const sessionId = ref<string | undefined>(undefined)
  const isLoadingHistory = ref(false)
  const isSending = ref(false)
  const question = ref('')

  function mapMessage(m: AiTutorMessage, idx: number): ChatMessage {
    return { id: `hist-${idx}`, role: m.role, content: m.content, created_at: m.created_at }
  }

  async function loadHistory(): Promise<void> {
    isLoadingHistory.value = true
    try {
      const history = await onboardingStudentApi.getAiTutorHistory(lessonId)
      messages.value = history.map(mapMessage)
    } catch {
      // non-critical
    } finally {
      isLoadingHistory.value = false
    }
  }

  async function sendQuestion(): Promise<void> {
    const q = question.value.trim()
    if (!q) return

    const userMsg: ChatMessage = {
      id: `u-${Date.now()}`,
      role: 'user',
      content: q,
      created_at: new Date().toISOString(),
    }
    messages.value.push(userMsg)
    question.value = ''
    isSending.value = true

    try {
      const res = await onboardingStudentApi.askAiTutor(lessonId, q, sessionId.value)
      sessionId.value = res.session_id
      messages.value.push({
        id: `a-${Date.now()}`,
        role: 'assistant',
        content: res.answer,
        created_at: new Date().toISOString(),
      })
    } catch (err: unknown) {
      // Remove user message on error
      messages.value.pop()
      question.value = q
      const status = (err as { response?: { status?: number } }).response?.status
      if (status === 503) {
        toast.add({
          severity: 'warn',
          summary: 'AI временно недоступен',
          detail: 'Попробуйте через несколько секунд.',
          life: 5000,
        })
      } else {
        toast.add({ severity: 'error', summary: 'Ошибка', detail: 'Не удалось получить ответ', life: 4000 })
      }
    } finally {
      isSending.value = false
    }
  }

  function requestClearHistory() {
    confirm.require({
      message: 'Очистить историю чата?',
      header: 'Подтверждение',
      icon: 'pi pi-exclamation-triangle',
      acceptLabel: 'Очистить',
      rejectLabel: 'Отмена',
      accept: () => void clearHistory(),
    })
  }

  async function clearHistory(): Promise<void> {
    try {
      await onboardingStudentApi.clearAiTutorHistory(lessonId)
      messages.value = []
      sessionId.value = undefined
      toast.add({ severity: 'info', summary: 'История очищена', life: 3000 })
    } catch {
      toast.add({ severity: 'error', summary: 'Ошибка', detail: 'Не удалось очистить историю', life: 3000 })
    }
  }

  return {
    messages,
    question,
    isLoadingHistory,
    isSending,
    loadHistory,
    sendQuestion,
    requestClearHistory,
  }
}
