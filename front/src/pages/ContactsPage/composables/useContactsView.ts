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

// Columns removed from spec that must not persist from old localStorage cache
const COMPANY_REMOVED_COLS = new Set(['id', 'company_type'])
const CONTACT_REMOVED_COLS = new Set(['id'])

function loadVisibleFields(entityType: EntityType): string[] | null {
  try {
    const raw = localStorage.getItem(`${COLUMNS_STORAGE_KEY}_${entityType}`)
    if (!raw) return null
    const fields = JSON.parse(raw) as string[]
    // Purge removed columns from cache
    const removed = entityType === 'company' ? COMPANY_REMOVED_COLS : CONTACT_REMOVED_COLS
    return fields.filter((f) => !removed.has(f))
  } catch {
    return null
  }
}

// Per §5 spec: contacts = ФИО/Компания/Телефон/Посл.контакт/Теги/Автор
// companies = Название/Категория/Страна/Сделки/Посл.контакт/Вовлечённость/Ответственный/Теги
// NOTE: 'id' (#) and 'company_type' columns are NOT in default company view (D1 fix)
const DEFAULT_CONTACT_FIELDS = ['full_name', 'company', 'phone', 'last_activity_at', 'tags', 'owner']
const DEFAULT_COMPANY_FIELDS = ['name', 'category_code', 'country_code', 'open_deals_count', 'last_activity_at', 'engagement_tier', 'owner', 'tags']

export function useContactsView(entityType: { value: EntityType }) {
  const { t } = useI18n()

  const density = ref<ContactsDensity>(loadDensity())

  // Translated column defs — §5.2 contacts: ФИО/Компания/Телефон/Посл.контакт/Теги/Автор
  // 'id' (#) removed — not in §5.2 spec; all sortable cols per CONTACT_SORT_MAP
  const contactColumnDefs = computed<ContactColumnDef[]>(() => [
    { field: 'full_name', header: t('contacts.page.columns.name'), required: true, sortable: true },
    { field: 'company', header: t('contacts.page.columns.company'), sortable: true },
    { field: 'phone', header: t('contacts.page.columns.phone'), sortable: true },
    { field: 'last_activity_at', header: t('crm.contacts_page.columns.lastTouch'), sortable: true },
    { field: 'tags', header: t('contacts.page.columns.tags'), sortable: false },
    { field: 'owner', header: t('contacts.page.columns.author'), sortable: true },
    // Available but hidden by default:
    { field: 'engagement_tier', header: t('crm.contacts_page.columns.engagement'), sortable: false, width: 100 },
    { field: 'position', header: t('contact.page.fields.position', 'Должность'), sortable: false },
    { field: 'open_deals_count', header: t('crm.contacts_page.columns.openDeals'), sortable: true, width: 120 },
  ])

  // §5.1 companies: Название/Категория/Страна/Сделки/Посл.контакт/Вовлечённость/Ответственный/Теги
  // 'id' (#) and 'company_type' (Тип компании) are removed per spec §5.1 (D1 fix)
  const companyColumnDefs = computed<ContactColumnDef[]>(() => [
    { field: 'name', header: t('company.page.fields.name'), required: true, sortable: true },
    { field: 'category_code', header: t('company.page.fields.category', 'Категория'), sortable: true },
    { field: 'country_code', header: t('contacts.page.filters.country'), sortable: true },
    { field: 'open_deals_count', header: t('crm.contacts_page.columns.openDeals'), sortable: true },
    { field: 'last_activity_at', header: t('crm.contacts_page.columns.lastTouch'), sortable: true },
    { field: 'engagement_tier', header: t('crm.contacts_page.columns.engagement'), sortable: true },
    { field: 'owner', header: t('contacts.page.columns.responsible'), sortable: true },
    { field: 'tags', header: t('contacts.page.columns.tags'), sortable: false },
    // Available but hidden by default (extra columns for column chooser):
    { field: 'employees_count', header: t('contacts_company.employees', 'Сотрудников'), sortable: false, width: 110 },
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

