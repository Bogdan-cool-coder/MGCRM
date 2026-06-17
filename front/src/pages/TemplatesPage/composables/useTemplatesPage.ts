import { ref, computed, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { templatesApi } from '@/api/templates'
import type { TemplateListItemDto } from '@/entities/template'
import type { DocumentKind } from '@/entities/document'

export const useTemplatesPage = () => {
  const { t } = useI18n()
  const router = useRouter()

  const kindFilter = ref<DocumentKind | null>(null)
  const searchFilter = ref('')

  const resource = useAsyncResource<TemplateListItemDto[]>(() => [])
  const templates = computed(() => resource.data.value)
  const loading = computed(() => resource.loading.value)

  async function fetchTemplates() {
    await resource.run(() =>
      templatesApi.getTemplates({
        kind: kindFilter.value ?? undefined,
        search: searchFilter.value || undefined,
      }),
    )
  }

  watch([kindFilter, searchFilter], () => void fetchTemplates(), { immediate: true })

  function goToTemplate(id: number) {
    void router.push({ name: 'TemplateDetail', params: { id } })
  }

  const kindOptions = computed(() => [
    { label: t('documents.kinds.contract'), value: 'contract' as DocumentKind },
    { label: t('documents.kinds.invoice'), value: 'invoice' as DocumentKind },
    { label: t('documents.kinds.act'), value: 'act' as DocumentKind },
    { label: t('documents.kinds.reconciliation'), value: 'reconciliation' as DocumentKind },
  ])

  return {
    t,
    kindFilter,
    searchFilter,
    templates,
    loading,
    goToTemplate,
    kindOptions,
  }
}
