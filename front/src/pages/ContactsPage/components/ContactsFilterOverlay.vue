<template>
  <Teleport to="body">
    <div v-if="visible" class="filter-overlay-backdrop" @click="emit('close')" />
    <div v-if="visible" class="contacts-filter-overlay">
      <!-- Preset chips row -->
      <div class="contacts-filter-overlay__presets">
        <span class="contacts-filter-overlay__section-label">{{ t('sales.deals.page.filters.presets') }}</span>
        <div class="contacts-filter-overlay__preset-chips">
          <ToggleButton
            v-model="localPresets.mine"
            :on-label="t('contacts_filter.presets.mine', 'Мои')"
            :off-label="t('contacts_filter.presets.mine', 'Мои')"
            class="contacts-filter-overlay__preset-chip"
          />
          <ToggleButton
            v-model="localPresets.active"
            :on-label="t('contacts_filter.presets.active', 'Активные')"
            :off-label="t('contacts_filter.presets.active', 'Активные')"
            class="contacts-filter-overlay__preset-chip contacts-filter-overlay__preset-chip--success"
          />
          <ToggleButton
            v-model="localPresets.withDeals"
            :on-label="t('contacts_filter.presets.withDeals', 'С открытыми сделками')"
            :off-label="t('contacts_filter.presets.withDeals', 'С открытыми сделками')"
            class="contacts-filter-overlay__preset-chip"
          />
          <ToggleButton
            v-model="localPresets.noTask"
            :on-label="t('contacts_filter.presets.noTask', 'Без задач')"
            :off-label="t('contacts_filter.presets.noTask', 'Без задач')"
            class="contacts-filter-overlay__preset-chip contacts-filter-overlay__preset-chip--warning"
          />
          <ToggleButton
            v-model="localPresets.duplicates"
            :on-label="t('crm.contacts_page.savedViews.duplicates')"
            :off-label="t('crm.contacts_page.savedViews.duplicates')"
            class="contacts-filter-overlay__preset-chip contacts-filter-overlay__preset-chip--danger"
          />
        </div>
        <Button
          icon="pi pi-times"
          text
          severity="secondary"
          size="small"
          class="contacts-filter-overlay__close-btn"
          @click="emit('close')"
        />
      </div>

      <div class="contacts-filter-overlay__divider" />

      <!-- 3 columns -->
      <div class="row g-4 contacts-filter-overlay__body">
        <!-- Col 1: Who -->
        <div class="col-md-4">
          <p class="contacts-filter-overlay__col-title">{{ t('contacts_filter.col.who', 'Кто') }}</p>

          <div class="contacts-filter-overlay__field">
            <label class="contacts-filter-overlay__label">{{ t('sales.deals.page.filters.owner') }}</label>
            <MultiSelect
              v-model="localFilters.owner_ids"
              :options="users"
              option-label="full_name"
              option-value="id"
              filter
              class="w-100"
              :placeholder="t('sales.deals.page.filters.owner')"
            />
          </div>

          <div class="contacts-filter-overlay__field">
            <label class="contacts-filter-overlay__label">{{ t('crm.entity.author') }}</label>
            <MultiSelect
              v-model="localFilters.author_ids"
              :options="users"
              option-label="full_name"
              option-value="id"
              filter
              class="w-100"
              :placeholder="t('crm.entity.author')"
            />
          </div>

          <div class="contacts-filter-overlay__field">
            <label class="contacts-filter-overlay__label">{{ t('contacts.page.columns.tags') }}</label>
            <MultiSelect
              v-model="localFilters.tags"
              :options="availableTags"
              filter
              class="w-100"
              :placeholder="t('contacts.page.columns.tags')"
            />
          </div>

          <div class="contacts-filter-overlay__field">
            <label class="contacts-filter-overlay__label">{{ t('contacts.page.filters.source') }}</label>
            <MultiSelect
              v-model="localFilters.sources"
              :options="sources"
              option-label="name"
              option-value="code"
              filter
              class="w-100"
              :placeholder="t('contacts.page.filters.source')"
            />
          </div>

          <div class="contacts-filter-overlay__field">
            <label class="contacts-filter-overlay__label">{{ t('crm.contacts_page.filters.engagement') }}</label>
            <Select
              v-model="localFilters.engagement_tier"
              :options="engagementOptions"
              option-label="label"
              option-value="value"
              show-clear
              class="w-100"
              :placeholder="t('crm.contacts_page.filters.engagement')"
            />
          </div>
        </div>

        <!-- Col 2: What -->
        <div class="col-md-4">
          <p class="contacts-filter-overlay__col-title">{{ t('contacts_filter.col.what', 'Что') }}</p>

          <div v-if="entityType === 'company'" class="contacts-filter-overlay__field">
            <label class="contacts-filter-overlay__label">{{ t('contacts.page.filters.companyType') }}</label>
            <MultiSelect
              v-model="localFilters.company_type_ids"
              :options="companyTypes"
              option-label="name"
              option-value="id"
              filter
              class="w-100"
              :placeholder="t('contacts.page.filters.companyType')"
            />
          </div>

          <div v-if="entityType === 'company'" class="contacts-filter-overlay__field">
            <label class="contacts-filter-overlay__label">{{ t('company.page.fields.category', 'Категория') }}</label>
            <MultiSelect
              v-model="localFilters.categories"
              :options="categoryOptions"
              option-label="label"
              option-value="value"
              class="w-100"
              :placeholder="t('company.page.fields.category', 'Категория')"
            />
          </div>

          <div class="contacts-filter-overlay__field">
            <label class="contacts-filter-overlay__label">{{ t('contacts.page.filters.country') }}</label>
            <Select
              v-model="localFilters.country_code"
              :options="countries"
              option-label="name"
              option-value="code"
              filter
              show-clear
              class="w-100"
              :placeholder="t('contacts.page.filters.country')"
            />
          </div>

          <div class="contacts-filter-overlay__field">
            <label class="contacts-filter-overlay__label">{{ t('contacts_filter.city', 'Город') }}</label>
            <InputText v-model="localFilters.city" class="w-100" />
          </div>

          <div class="contacts-filter-overlay__field">
            <label class="contacts-filter-overlay__label">{{ t('crm.contacts_page.filters.openDealsMin') }}</label>
            <div class="d-flex gap-2 align-items-center">
              <InputNumber v-model="localFilters.open_deals_min" :min="0" class="flex-1" />
              <span class="contacts-filter-overlay__range-sep">—</span>
              <InputNumber v-model="localFilters.open_deals_max" :min="0" class="flex-1" />
            </div>
          </div>
        </div>

        <!-- Col 3: When -->
        <div class="col-md-4">
          <p class="contacts-filter-overlay__col-title">{{ t('contacts_filter.col.when', 'Когда') }}</p>

          <div class="contacts-filter-overlay__field">
            <label class="contacts-filter-overlay__label">{{ t('contacts_filter.createdAt', 'Дата создания') }}</label>
            <DatePicker
              v-model="localFilters.created_range"
              selection-mode="range"
              show-icon
              class="w-100"
            />
          </div>

          <div class="contacts-filter-overlay__field">
            <label class="contacts-filter-overlay__label">{{ t('crm.contacts_page.filters.lastTouchFrom') }}</label>
            <DatePicker
              v-model="localFilters.last_touch_range"
              selection-mode="range"
              show-icon
              class="w-100"
            />
          </div>
        </div>
      </div>

      <!-- Footer -->
      <div class="contacts-filter-overlay__footer">
        <Button
          :label="t('sales.deals.page.filters.reset')"
          severity="secondary"
          text
          @click="onReset"
        />
        <Button
          :label="t('sales.deals.page.filters.apply')"
          @click="onApply"
        />
      </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { reactive, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import MultiSelect from 'primevue/multiselect'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
import ToggleButton from 'primevue/togglebutton'
import type { EntityType } from '../composables/useContactsPageData'
import type { ContactsOverlayFilters } from '../composables/useContactsFilters'
import { DEFAULT_OVERLAY_FILTERS } from '../composables/useContactsFilters'

const props = defineProps<{
  visible: boolean
  entityType: EntityType
  filters: ContactsOverlayFilters
  users: { id: number; full_name: string }[]
  sources: { code: string; name: string }[]
  companyTypes: { id: number; name: string }[]
  countries: { code: string; name: string }[]
  availableTags: string[]
}>()

const emit = defineEmits<{
  close: []
  apply: [filters: ContactsOverlayFilters]
  reset: []
}>()

const { t } = useI18n()

const localFilters = reactive<ContactsOverlayFilters>({ ...props.filters })
const localPresets = reactive({
  mine: props.filters.only_mine,
  active: props.filters.only_active,
  withDeals: props.filters.only_with_deals,
  noTask: props.filters.only_no_task,
  duplicates: props.filters.only_duplicates,
})

watch(
  () => props.filters,
  (next) => {
    Object.assign(localFilters, { ...next })
    localPresets.mine = next.only_mine
    localPresets.active = next.only_active
    localPresets.withDeals = next.only_with_deals
    localPresets.noTask = next.only_no_task
    localPresets.duplicates = next.only_duplicates
  },
  { deep: true },
)

const engagementOptions = computed(() => [
  { label: t('crm.entity.engagement.fresh'), value: 'fresh' },
  { label: t('crm.entity.engagement.cooling'), value: 'cooling' },
  { label: t('crm.entity.engagement.cold'), value: 'cold' },
])

const categoryOptions = [
  { label: 'L', value: 'L' },
  { label: 'M', value: 'M' },
  { label: 'S1', value: 'S1' },
  { label: 'S2', value: 'S2' },
]

function onApply() {
  emit('apply', {
    ...localFilters,
    only_mine: localPresets.mine,
    only_active: localPresets.active,
    only_with_deals: localPresets.withDeals,
    only_no_task: localPresets.noTask,
    only_duplicates: localPresets.duplicates,
  })
}

function onReset() {
  Object.assign(localFilters, { ...DEFAULT_OVERLAY_FILTERS })
  localPresets.mine = false
  localPresets.active = false
  localPresets.withDeals = false
  localPresets.noTask = false
  localPresets.duplicates = false
  emit('reset')
}
</script>

<style lang="scss" scoped>
.filter-overlay-backdrop {
  position: fixed;
  inset: 0;
  z-index: 999;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  background: rgba(0, 0, 0, 0.3); // modal backdrop scrim — no token for translucent black overlay
}

.contacts-filter-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  z-index: 1000;
  background: $surface-card;
  border-bottom: 1px solid $surface-200;
  box-shadow: $shadow-overlay-sm;
  padding: $space-4;

  :global(.app-dark) & {
    background: var(--p-surface-900);
    border-bottom-color: var(--p-surface-700);
  }
}

.contacts-filter-overlay__presets {
  display: flex;
  align-items: center;
  gap: $space-3;
  flex-wrap: wrap;
}

.contacts-filter-overlay__section-label {
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  color: $surface-500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  white-space: nowrap;
}

.contacts-filter-overlay__preset-chips {
  display: flex;
  flex-wrap: wrap;
  gap: $space-2;
  flex: 1;
}

.contacts-filter-overlay__preset-chip {
  font-size: $font-size-xs;
}

.contacts-filter-overlay__close-btn {
  margin-left: auto;
  flex-shrink: 0;
}

.contacts-filter-overlay__divider {
  height: 1px;
  background: $surface-100;
  margin: $space-3 0;

  :global(.app-dark) & {
    background: var(--p-surface-700);
  }
}

.contacts-filter-overlay__body {
  margin-bottom: $space-3;
}

.contacts-filter-overlay__col-title {
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  color: $surface-500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  margin-bottom: $space-3;
}

.contacts-filter-overlay__field {
  margin-bottom: $space-3;
}

.contacts-filter-overlay__label {
  display: block;
  font-size: $font-size-xs;
  color: $surface-500;
  margin-bottom: $space-1;
}

.contacts-filter-overlay__range-sep {
  font-size: $font-size-sm;
  color: $surface-400;
  flex-shrink: 0;
}

.contacts-filter-overlay__footer {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: $space-2;
  padding-top: $space-3;
  border-top: 1px solid $surface-100;

  :global(.app-dark) & {
    border-top-color: var(--p-surface-700);
  }
}
</style>
