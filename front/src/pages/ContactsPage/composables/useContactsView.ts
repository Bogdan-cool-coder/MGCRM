/**
 * Manages DataTable view state: visible columns, density, sort.
 * Each config is persisted per entity-type in localStorage.
 */
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { EntityType } from './useContactsPageData'

export type ContactsDensity = 'compact' | 'normal' | 'comfortable'

export interface ContactColumnDef {
  field: string
  header: string
  required?: boolean
  sortable?: boolean
  frozen?: boolean
  /** width in px */
  width?: number
}

const DENSITY_STORAGE_KEY = 'mgcrm_contacts_density_v1'
const COLUMNS_STORAGE_KEY = 'mgcrm_contacts_columns_v1'

function loadDensity(): ContactsDensity {
  return (localStorage.getItem(DENSITY_STORAGE_KEY) as ContactsDensity | null) ?? 'normal'
}

function loadVisibleFields(entityType: EntityType): string[] | null {
  try {
    const raw = localStorage.getItem(`${COLUMNS_STORAGE_KEY}_${entityType}`)
    if (!raw) return null
    return JSON.parse(raw) as string[]
  } catch {
    return null
  }
}

const DEFAULT_CONTACT_FIELDS = ['id', 'full_name', 'engagement_tier', 'position', 'company', 'last_activity_at', 'open_deals_count', 'owner', 'tags']
const DEFAULT_COMPANY_FIELDS = ['id', 'name', 'engagement_tier', 'company_type', 'category_code', 'country_code', 'open_deals_count', 'owner', 'tags']

export function useContactsView(entityType: { value: EntityType }) {
  const { t } = useI18n()

  const density = ref<ContactsDensity>(loadDensity())

  // Translated column defs — explicit field to keep TS happy
  const contactColumnDefs = computed<ContactColumnDef[]>(() => [
    { field: 'id', header: '#', sortable: false, width: 60 },
    { field: 'full_name', header: t('contacts.page.columns.name'), required: true, sortable: true, frozen: true },
    { field: 'engagement_tier', header: t('crm.contacts_page.columns.engagement'), sortable: false, width: 100 },
    { field: 'position', header: t('contact.page.fields.position', 'Должность'), sortable: true },
    { field: 'company', header: t('contacts.page.columns.company'), sortable: false },
    { field: 'last_activity_at', header: t('crm.contacts_page.columns.lastTouch'), sortable: true },
    { field: 'open_deals_count', header: t('crm.contacts_page.columns.openDeals'), sortable: true, width: 120 },
    { field: 'owner', header: t('crm.entity.author'), sortable: false },
    { field: 'tags', header: t('contacts.page.columns.tags'), sortable: false },
  ])

  const companyColumnDefs = computed<ContactColumnDef[]>(() => [
    { field: 'id', header: '#', sortable: false, width: 60 },
    { field: 'name', header: t('company.page.fields.name'), required: true, sortable: true, frozen: true },
    { field: 'engagement_tier', header: t('crm.contacts_page.columns.engagement'), sortable: false, width: 100 },
    { field: 'company_type', header: t('contacts.page.filters.companyType'), sortable: false },
    { field: 'category_code', header: t('company.page.fields.category', 'Категория'), sortable: false, width: 100 },
    { field: 'country_code', header: t('contacts.page.filters.country'), sortable: true },
    { field: 'employees_count', header: t('contacts_company.employees', 'Сотрудников'), sortable: true, width: 110 },
    { field: 'open_deals_count', header: t('crm.contacts_page.columns.openDeals'), sortable: true, width: 120 },
    { field: 'owner', header: t('crm.entity.author'), sortable: false },
    { field: 'tags', header: t('contacts.page.columns.tags'), sortable: false },
  ])

  const allColumns = computed(() =>
    entityType.value === 'contact' ? contactColumnDefs.value : companyColumnDefs.value,
  )

  const visibleFields = ref<string[]>(
    loadVisibleFields(entityType.value) ??
      (entityType.value === 'contact' ? [...DEFAULT_CONTACT_FIELDS] : [...DEFAULT_COMPANY_FIELDS]),
  )

  // Reload when entity type changes
  watch(
    () => entityType.value,
    (type) => {
      visibleFields.value =
        loadVisibleFields(type) ??
        (type === 'contact' ? [...DEFAULT_CONTACT_FIELDS] : [...DEFAULT_COMPANY_FIELDS])
    },
  )

  watch(density, (d) => {
    localStorage.setItem(DENSITY_STORAGE_KEY, d)
  })

  watch(
    visibleFields,
    (fields) => {
      localStorage.setItem(`${COLUMNS_STORAGE_KEY}_${entityType.value}`, JSON.stringify(fields))
    },
    { deep: true },
  )

  const rowHeight = computed((): number => {
    switch (density.value) {
      case 'compact': return 32
      case 'comfortable': return 64
      default: return 48
    }
  })

  function setDensity(d: ContactsDensity) {
    density.value = d
  }

  function setVisibleFields(fields: string[]) {
    // Always keep required columns
    const requiredFields = allColumns.value.filter((c) => c.required).map((c) => c.field)
    const merged = [...new Set([...requiredFields, ...fields])]
    visibleFields.value = merged
  }

  return {
    density,
    rowHeight,
    allColumns,
    visibleFields,
    setDensity,
    setVisibleFields,
  }
}

