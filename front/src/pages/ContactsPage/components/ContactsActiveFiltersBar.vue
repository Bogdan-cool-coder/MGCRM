<template>
  <div v-if="chips.length > 0" class="active-filters-bar">
    <Tag
      v-for="chip in chips"
      :key="chip.key"
      :value="chip.label"
      severity="secondary"
      class="active-filters-bar__chip"
    >
      <template #default>
        <span class="active-filters-bar__chip-label">{{ chip.label }}</span>
        <button
          class="active-filters-bar__chip-remove"
          :aria-label="t('common.remove')"
          @click="emit('remove', chip.key)"
        >
          <i class="pi pi-times" />
        </button>
      </template>
    </Tag>
    <Button
      :label="t('sales.deals.page.filters.reset')"
      text
      size="small"
      severity="secondary"
      class="active-filters-bar__reset"
      @click="emit('reset')"
    />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Tag from 'primevue/tag'
import Button from 'primevue/button'
import type { ContactsOverlayFilters } from '../composables/useContactsFilters'
import type { EntityType } from '../composables/useContactsPageData'

interface FilterChip {
  key: string
  label: string
}

const props = defineProps<{
  filters: ContactsOverlayFilters
  entityType: EntityType
  search: string
  users: { id: number; full_name: string }[]
  sources: { code: string; name: string }[]
  companyTypes: { id: number; name: string }[]
  countries: { code: string; name: string }[]
}>()

const emit = defineEmits<{
  remove: [key: string]
  reset: []
}>()

const { t } = useI18n()

const chips = computed((): FilterChip[] => {
  const result: FilterChip[] = []

  if (props.search) {
    result.push({ key: 'search', label: `"${props.search}"` })
  }

  if (props.filters.only_mine) {
    result.push({ key: 'only_mine', label: t('contacts_filter.presets.mine', 'Мои') })
  }
  if (props.filters.only_active) {
    result.push({ key: 'only_active', label: t('contacts_filter.presets.active', 'Активные') })
  }
  if (props.filters.only_with_deals) {
    result.push({ key: 'only_with_deals', label: t('contacts_filter.presets.withDeals', 'С открытыми сделками') })
  }
  if (props.filters.only_no_task) {
    result.push({ key: 'only_no_task', label: t('contacts_filter.presets.noTask', 'Без задач') })
  }

  if (props.filters.engagement_tier) {
    result.push({
      key: 'engagement_tier',
      label: t(`crm.entity.engagement.${props.filters.engagement_tier}`),
    })
  }

  for (const ownerId of props.filters.owner_ids) {
    const user = props.users.find((u) => u.id === ownerId)
    if (user) {
      result.push({ key: `owner_${ownerId}`, label: user.full_name })
    }
  }

  for (const tag of props.filters.tags) {
    result.push({ key: `tag_${tag}`, label: tag })
  }

  for (const code of props.filters.sources) {
    const source = props.sources.find((s) => s.code === code)
    result.push({ key: `source_${code}`, label: source?.name ?? code })
  }

  if (props.filters.country_code) {
    const country = props.countries.find((c) => c.code === props.filters.country_code)
    result.push({ key: 'country', label: country?.name ?? props.filters.country_code! })
  }

  // city chip — only for companies (contacts have no city column)
  if (props.entityType === 'company' && props.filters.city) {
    result.push({ key: 'city', label: props.filters.city })
  }

  // position chip — only for contacts
  if (props.entityType === 'contact' && props.filters.position) {
    result.push({ key: 'position', label: props.filters.position })
  }

  if (props.filters.open_deals_min !== null || props.filters.open_deals_max !== null) {
    const min = props.filters.open_deals_min ?? '?'
    const max = props.filters.open_deals_max ?? '?'
    result.push({ key: 'open_deals', label: `${t('crm.contacts_page.columns.openDeals')}: ${min}–${max}` })
  }

  for (const typeId of props.filters.company_type_ids) {
    const ct = props.companyTypes.find((c) => c.id === typeId)
    result.push({ key: `ctype_${typeId}`, label: ct?.name ?? String(typeId) })
  }

  return result
})
</script>

<style lang="scss" scoped>
.active-filters-bar {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex-wrap: wrap;
  padding: $space-2 $space-4;
  border-bottom: 1px solid $surface-100;

  .app-dark & {
    border-bottom-color: var(--p-surface-700);
  }
}

.active-filters-bar__chip {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  padding: 2px $space-2;
}

.active-filters-bar__chip-label {
  font-size: $font-size-xs;
}

.active-filters-bar__chip-remove {
  background: transparent;
  border: none;
  cursor: pointer;
  padding: 0;
  color: $surface-400;
  display: inline-flex;
  align-items: center;
  margin-left: 2px;

  i {
    font-size: $font-size-3xs;
  }

  &:hover {
    color: $surface-600;
  }
}

.active-filters-bar__reset {
  margin-left: $space-1;
}
</style>
