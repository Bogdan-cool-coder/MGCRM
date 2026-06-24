import { ref, computed, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { templatesApi } from '@/api/templates'
import type { TemplateListItemDto, TemplateKind } from '@/entities/template'

export const useTemplatesPage = () => {
  const { t } = useI18n()
  const router = useRouter()

  const kindFilter = ref<TemplateKind | null>(null)
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

  // Real template kind values matching templates.kind column (docx/yaml/text).
  const kindOptions = computed(() => [
    { label: t('documents.kinds.docx', 'DOCX'), value: 'docx' as TemplateKind },
    { label: t('documents.kinds.yaml', 'YAML'), value: 'yaml' as TemplateKind },
    { label: 'Text', value: 'text' as TemplateKind },
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
