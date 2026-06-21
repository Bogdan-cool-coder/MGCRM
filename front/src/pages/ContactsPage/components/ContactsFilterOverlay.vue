<template>
  <div class="contacts-filter-panel">
    <!-- Preset chips row -->
    <div class="contacts-filter-panel__presets">
      <span class="contacts-filter-panel__section-label">{{ t('contacts.filter.segmentsLabel') }}</span>
      <div class="contacts-filter-panel__preset-chips">
        <ToggleButton
          v-model="localPresets.mine"
          :on-label="t('contacts.filter.preset.mine')"
          :off-label="t('contacts.filter.preset.mine')"
          class="contacts-filter-panel__preset-chip"
        />
        <ToggleButton
          v-model="localPresets.active"
          :on-label="t('contacts.filter.preset.active')"
          :off-label="t('contacts.filter.preset.active')"
          class="contacts-filter-panel__preset-chip contacts-filter-panel__preset-chip--success"
        />
        <ToggleButton
          v-model="localPresets.withDeals"
          :on-label="t('contacts.filter.preset.withDeals')"
          :off-label="t('contacts.filter.preset.withDeals')"
          class="contacts-filter-panel__preset-chip"
        />
        <ToggleButton
          v-model="localPresets.noTask"
          :on-label="t('contacts.filter.preset.noTask')"
          :off-label="t('contacts.filter.preset.noTask')"
          class="contacts-filter-panel__preset-chip contacts-filter-panel__preset-chip--warning"
        />
        <ToggleButton
          v-model="localPresets.duplicates"
          :on-label="t('contacts.filter.preset.duplicates')"
          :off-label="t('contacts.filter.preset.duplicates')"
          class="contacts-filter-panel__preset-chip contacts-filter-panel__preset-chip--danger"
        />
      </div>
      <Button
        icon="pi pi-times"
        text
        severity="secondary"
        size="small"
        class="contacts-filter-panel__close-btn"
        @click="emit('close')"
      />
    </div>

    <div class="contacts-filter-panel__divider" />

    <!-- 3 columns -->
    <div class="row g-4 contacts-filter-panel__body">
      <!-- Col 1: Who -->
      <div class="col-md-4">
        <p class="contacts-filter-panel__col-title">{{ t('contacts.filter.col.who') }}</p>

        <div class="contacts-filter-panel__field">
          <label class="contacts-filter-panel__label">{{ t('contacts.filter.field.owner') }}</label>
          <MultiSelect
            v-model="localFilters.owner_ids"
            :options="users"
            option-label="full_name"
            option-value="id"
            filter
            class="w-100"
            :placeholder="t('contacts.filter.field.owner')"
          />
        </div>

        <div class="contacts-filter-panel__field">
          <label class="contacts-filter-panel__label">{{ t('contacts.filter.field.author') }}</label>
          <MultiSelect
            v-model="localFilters.author_ids"
            :options="users"
            option-label="full_name"
            option-value="id"
            filter
            class="w-100"
            :placeholder="t('contacts.filter.field.author')"
          />
        </div>

        <div class="contacts-filter-panel__field">
          <label class="contacts-filter-panel__label">{{ t('contacts.filter.field.engagement') }}</label>
          <Select
            v-model="localFilters.engagement_tier"
            :options="engagementOptions"
            option-label="label"
            option-value="value"
            show-clear
            class="w-100"
            :placeholder="t('contacts.filter.field.engagement')"
          />
        </div>
      </div>

      <!-- Col 2: What (entity-dependent) -->
      <div class="col-md-4">
        <p class="contacts-filter-panel__col-title">{{ t('contacts.filter.col.what') }}</p>

        <!-- Company-specific fields -->
        <template v-if="entityType === 'company'">
          <div class="contacts-filter-panel__field">
            <label class="contacts-filter-panel__label">{{ t('contacts.filter.field.companyType') }}</label>
            <MultiSelect
              v-model="localFilters.company_type_ids"
              :options="companyTypes"
              option-label="name"
              option-value="id"
              filter
              class="w-100"
              :placeholder="t('contacts.filter.field.companyType')"
            />
          </div>

          <div class="contacts-filter-panel__field">
            <label class="contacts-filter-panel__label">{{ t('contacts.filter.field.category') }}</label>
            <MultiSelect
              v-model="localFilters.categories"
              :options="categoryOptions"
              option-label="label"
              option-value="value"
              class="w-100"
              :placeholder="t('contacts.filter.field.category')"
            />
          </div>

          <div class="contacts-filter-panel__field">
            <label class="contacts-filter-panel__label">{{ t('contacts.filter.field.country') }}</label>
            <Select
              v-model="localFilters.country_code"
              :options="countries"
              option-label="name"
              option-value="code"
              filter
              show-clear
              class="w-100"
              :placeholder="t('contacts.filter.field.country')"
            />
          </div>

          <div class="contacts-filter-panel__field">
            <label class="contacts-filter-panel__label">{{ t('contacts.filter.field.tags') }}</label>
            <MultiSelect
              v-model="localFilters.tags"
              :options="availableTags"
              filter
              class="w-100"
              :placeholder="t('contacts.filter.field.tags')"
            />
          </div>
        </template>

        <!-- Contact-specific fields -->
        <template v-else>
          <div class="contacts-filter-panel__field">
            <label class="contacts-filter-panel__label">{{ t('contacts.filter.field.company') }}</label>
            <InputText v-model="localFilters.city" class="w-100" :placeholder="t('contacts.filter.field.company')" />
          </div>

          <div class="contacts-filter-panel__field">
            <label class="contacts-filter-panel__label">{{ t('contacts.filter.field.position') }}</label>
            <InputText v-model="localFilters.position" class="w-100" :placeholder="t('contacts.filter.field.position')" />
          </div>

          <div class="contacts-filter-panel__field">
            <label class="contacts-filter-panel__label">{{ t('contacts.filter.field.source') }}</label>
            <MultiSelect
              v-model="localFilters.sources"
              :options="sources"
              option-label="name"
              option-value="code"
              filter
              class="w-100"
              :placeholder="t('contacts.filter.field.source')"
            />
          </div>

          <div class="contacts-filter-panel__field">
            <label class="contacts-filter-panel__label">{{ t('contacts.filter.field.tags') }}</label>
            <MultiSelect
              v-model="localFilters.tags"
              :options="availableTags"
              filter
              class="w-100"
              :placeholder="t('contacts.filter.field.tags')"
            />
          </div>
        </template>
      </div>

      <!-- Col 3: When -->
      <div class="col-md-4">
        <p class="contacts-filter-panel__col-title">{{ t('contacts.filter.col.when') }}</p>

        <div class="contacts-filter-panel__field">
          <label class="contacts-filter-panel__label">{{ t('contacts.filter.field.createdAt') }}</label>
          <DatePicker
            v-model="localFilters.created_range"
            selection-mode="range"
            show-icon
            class="w-100"
          />
        </div>

        <div class="contacts-filter-panel__field">
          <label class="contacts-filter-panel__label">{{ t('contacts.filter.field.lastActivity') }}</label>
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
    <div class="contacts-filter-panel__footer">
      <Button
        :label="t('contacts.filter.reset')"
        severity="secondary"
        text
        @click="onReset"
      />
      <Button
        icon="pi pi-check"
        :label="t('contacts.filter.apply')"
        @click="onApply"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { reactive, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
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

// Extended local filter to include position for contacts
interface LocalFilters extends ContactsOverlayFilters {
  position: string
}

const localFilters = reactive<LocalFilters>({
  ...props.filters,
  position: (props.filters as unknown as Record<string, unknown>)['position'] as string ?? '',
})

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
    Object.assign(localFilters, {
      ...next,
      position: (next as unknown as Record<string, unknown>)['position'] as string ?? '',
    })
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
  const result: ContactsOverlayFilters & { position?: string } = {
    ...localFilters,
    only_mine: localPresets.mine,
    only_active: localPresets.active,
    only_with_deals: localPresets.withDeals,
    only_no_task: localPresets.noTask,
    only_duplicates: localPresets.duplicates,
  }
  emit('apply', result)
}

function onReset() {
  Object.assign(localFilters, { ...DEFAULT_OVERLAY_FILTERS, position: '' })
  localPresets.mine = false
  localPresets.active = false
  localPresets.withDeals = false
  localPresets.noTask = false
  localPresets.duplicates = false
  emit('reset')
}
</script>

<style lang="scss" scoped>
.contacts-filter-panel {
  border-bottom: 1px solid $surface-200;
  background: $surface-50;
  padding: $space-4 $space-5;
  flex-shrink: 0;

  .app-dark & {
    background: var(--p-surface-50);
    border-bottom-color: var(--p-surface-700);
  }
}

.contacts-filter-panel__presets {
  display: flex;
  align-items: center;
  gap: $space-3;
  flex-wrap: wrap;
}

.contacts-filter-panel__section-label {
  font-size: $font-size-2xs;
  font-weight: $font-weight-bold;
  color: $surface-400;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  white-space: nowrap;
}

.contacts-filter-panel__preset-chips {
  display: flex;
  flex-wrap: wrap;
  gap: $space-2;
  flex: 1;
}

.contacts-filter-panel__preset-chip {
  font-size: $font-size-xs;
}

.contacts-filter-panel__close-btn {
  margin-left: auto;
  flex-shrink: 0;
}

.contacts-filter-panel__divider {
  height: 1px;
  background: $surface-200;
  margin: $space-3 0;

  .app-dark & {
    background: var(--p-surface-700);
  }
}

.contacts-filter-panel__body {
  margin-bottom: $space-3;
}

.contacts-filter-panel__col-title {
  font-size: $font-size-2xs;
  font-weight: $font-weight-bold;
  color: $surface-400;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  margin-bottom: $space-3;
}

.contacts-filter-panel__field {
  margin-bottom: $space-3;
}

.contacts-filter-panel__label {
  display: block;
  font-size: $font-size-xs;
  font-weight: $font-weight-medium;
  color: $surface-600;
  margin-bottom: $space-1;

  .app-dark & {
    color: var(--p-surface-300);
  }
}

.contacts-filter-panel__footer {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: $space-2;
  padding-top: $space-3;
  border-top: 1px solid $surface-200;

  .app-dark & {
    border-top-color: var(--p-surface-700);
  }
}
</style>
