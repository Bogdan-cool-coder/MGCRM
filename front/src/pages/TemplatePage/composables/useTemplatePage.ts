/**
 * TemplatePage composable — template card with AI check polling.
 */
import { ref, computed, watch, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { useMutation } from '@/composables/async/useMutation'
import { templatesApi } from '@/api/templates'
import type { TemplateDto, TemplateVersionDto } from '@/entities/template'

const POLL_INTERVAL_MS = 3000

export const useTemplatePage = () => {
  const route = useRoute()
  const router = useRouter()
  const { t } = useI18n()
  const toast = useToast()
  const confirm = useConfirm()

  const templateId = computed(() => Number(route.params.id))

  // ─── Template ─────────────────────────────────────────────────────────────
  const templateResource = useAsyncResource<TemplateDto | null>(() => null)
  const template = computed(() => templateResource.data.value)
  const loading = computed(() => templateResource.loading.value)
  const loadError = computed(() => templateResource.error.value)

  async function fetchTemplate() {
    await templateResource.run(() => templatesApi.getTemplate(templateId.value))
  }

  // ─── Versions ─────────────────────────────────────────────────────────────
  const versionsResource = useAsyncResource<TemplateVersionDto[]>(() => [])
  const versions = computed(() => versionsResource.data.value)
  const loadingVersions = computed(() => versionsResource.loading.value)

  async function fetchVersions() {
    await versionsResource.run(() => templatesApi.getTemplateVersions(templateId.value))
  }

  // ─── Current version & AI polling ────────────────────────────────────────
  const latestVersion = computed<TemplateVersionDto | null>(
    () => template.value?.current_version ?? null,
  )

  let pollTimer: ReturnType<typeof setInterval> | null = null

  function stopPolling() {
    if (pollTimer) {
      clearInterval(pollTimer)
      pollTimer = null
    }
  }

  async function pollVersion() {
    if (!latestVersion.value) return
    try {
      const updated = await templatesApi.getTemplateVersion(
        templateId.value,
        latestVersion.value.id,
      )
      if (templateResource.data.value) {
        templateResource.data.value.current_version = updated
      }
      // Update in versions list too
      const idx = versionsResource.data.value.findIndex((v) => v.id === updated.id)
      if (idx >= 0) versionsResource.data.value[idx] = updated

      if (updated.ai_check_status !== 'checking') {
        stopPolling()
        if (updated.ai_check_status === 'checked') {
          toast.add({ severity: 'success', summary: t('templates.card.aiCheck.statuses.checked'), life: 3000 })
        }
      }
    } catch {
      stopPolling()
    }
  }

  function startPolling() {
    stopPolling()
    pollTimer = setInterval(() => void pollVersion(), POLL_INTERVAL_MS)
  }

  watch(latestVersion, (v) => {
    if (v?.ai_check_status === 'checking' || v?.ai_check_status === 'pending') {
      startPolling()
    } else {
      stopPolling()
    }
  }, { immediate: true })

  onUnmounted(() => stopPolling())

  // ─── Initial load ─────────────────────────────────────────────────────────
  watch(templateId, () => {
    stopPolling()
    void fetchTemplate()
    void fetchVersions()
  }, { immediate: true })

  // ─── Upload new version ───────────────────────────────────────────────────
  const uploading = ref(false)

  async function uploadVersion(file: File) {
    uploading.value = true
    try {
      const newVersion = await templatesApi.uploadTemplateVersion(templateId.value, file)
      // Refresh template to get updated current_version
      await fetchTemplate()
      await fetchVersions()
      toast.add({ severity: 'success', summary: t('templates.card.upload.btn'), life: 3000 })
      if (newVersion.ai_check_status === 'checking' || newVersion.ai_check_status === 'pending') {
        startPolling()
      }
    } catch {
      toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
    } finally {
      uploading.value = false
    }
  }

  // ─── Recheck ──────────────────────────────────────────────────────────────
  const rechecking = ref(false)

  async function recheckVersion() {
    if (!latestVersion.value) return
    rechecking.value = true
    try {
      const updated = await templatesApi.recheckTemplateVersion(templateId.value, latestVersion.value.id)
      if (templateResource.data.value) {
        templateResource.data.value.current_version = updated
      }
      startPolling()
    } catch {
      toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
    } finally {
      rechecking.value = false
    }
  }

  // ─── Override ─────────────────────────────────────────────────────────────
  const overrideMutation = useMutation<void>()

  function confirmOverride() {
    if (!latestVersion.value) return
    confirm.require({
      message: t('templates.card.aiCheck.overrideConfirm'),
      header: t('common.confirm'),
      icon: 'pi pi-exclamation-triangle',
      accept: async () => {
        await overrideMutation.run(async () => {
          const updated = await templatesApi.overrideTemplateVersion(
            templateId.value,
            latestVersion.value!.id,
          )
          if (templateResource.data.value) {
            templateResource.data.value.current_version = updated
          }
          toast.add({ severity: 'success', summary: t('templates.card.aiCheck.override'), life: 3000 })
        })
      },
    })
  }

  // ─── Edit dialog ──────────────────────────────────────────────────────────
  const editDialogVisible = ref(false)

  return {
    t,
    router,
    templateId,
    template,
    loading,
    loadError,
    versions,
    loadingVersions,
    latestVersion,
    uploading,
    uploadVersion,
    rechecking,
    recheckVersion,
    overrideMutation,
    confirmOverride,
    editDialogVisible,
    fetchTemplate,
  }
}
