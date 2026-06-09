import { computed, ref } from 'vue'
import { useServices } from '@/services'
import { useNotifications } from '@/composables/useNotifications'
import { useLocalI18n } from '@/composables/useLocalI18n'
import { useScopedResource } from '@/composables/async/useScopedResource'
import { getLocalizedText } from '@/utils/localization'
import { useCompanySelection } from '@/pages/shared/useCompanySelection'
import { useCompaniesStore } from '@/stores/companies'
import type {
  DocumentTemplateListItem,
  DocumentTemplateType,
} from '@/entities/document'
import en from '../locale/en.json'
import ru from '../locale/ru.json'

export interface LocalizedDocumentItem extends DocumentTemplateListItem {
  localizedName: string
  localizedDescription: string
}

/** `'all'` clears the type filter; otherwise it pins one template flavour. */
export type DocumentTypeFilter = 'all' | DocumentTemplateType

export const useDocumentsPageData = () => {
  const { t, locale } = useLocalI18n({ en, ru })
  const { notifyApiError } = useNotifications()
  const companiesStore = useCompaniesStore()
  const { documentService } = useServices()
  const activeCompanyId = computed(() => companiesStore.getActiveCompanyId)

  const templatesResource = useScopedResource<number, DocumentTemplateListItem[]>({
    scope: activeCompanyId,
    initialValue: () => [],
    load: () => documentService.fetchAllTemplates(),
  })
  const loading = templatesResource.loading
  const templates = templatesResource.data

  const fetchTemplates = async (companyId: number) => {
    try {
      await templatesResource.sync(companyId)
    } catch (error: unknown) {
      notifyApiError(error, t('errors.loadFailed'))
    }
  }

  const refresh = async () => {
    if (activeCompanyId.value !== null) {
      await fetchTemplates(activeCompanyId.value)
    }
  }

  const clearTemplates = () => {
    templatesResource.clear([])
  }

  useCompanySelection({
    onEnterCompanyScope: fetchTemplates,
    onLeaveCompanyScope: clearTemplates,
  })

  // ─── Type filter (html / docx) ──────────────────────────────────────────
  const typeFilter = ref<DocumentTypeFilter>('all')
  const setTypeFilter = (next: DocumentTypeFilter) => {
    typeFilter.value = next
  }

  const filteredTemplates = computed(() =>
    typeFilter.value === 'all'
      ? templates.value
      : templates.value.filter((tpl) => tpl.type === typeFilter.value),
  )

  const localize = (items: DocumentTemplateListItem[]): LocalizedDocumentItem[] =>
    items.map((item) => ({
      ...item,
      localizedName: getLocalizedText(item.name, locale.value),
      localizedDescription: item.description
        ? getLocalizedText(item.description, locale.value)
        : '',
    }))

  // Library split into three sections, mirroring the report / dashboard
  // library: system (company-wide), published (shared by colleagues), personal.
  const systemTemplates = computed(() =>
    localize(filteredTemplates.value.filter((d) => d.isSystem)),
  )
  const publishedTemplates = computed(() =>
    localize(filteredTemplates.value.filter((d) => !d.isSystem && d.isPublished)),
  )
  const personalTemplates = computed(() =>
    localize(filteredTemplates.value.filter((d) => !d.isSystem && !d.isPublished)),
  )

  const hasAny = computed(() => filteredTemplates.value.length > 0)

  return {
    t,
    locale,
    loading,
    templates,
    hasAny,
    typeFilter,
    setTypeFilter,
    systemTemplates,
    publishedTemplates,
    personalTemplates,
    refresh,
  }
}
