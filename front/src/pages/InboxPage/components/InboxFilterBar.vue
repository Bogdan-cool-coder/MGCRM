<template>
  <Card class="inbox-filter-bar">
    <template #content>
      <div class="row g-2 align-items-end">
        <!-- 1. Unread / All toggle -->
        <div class="col-auto">
          <SelectButton
            :model-value="filters.unreadOnly ? unreadOption : allOption"
            :options="unreadOptions"
            option-label="label"
            :allow-empty="false"
            @update:model-value="(v: UnreadOption) => emit('update:unreadOnly', v.value)"
          />
        </div>

        <!-- 2. Failed quick-filter -->
        <div class="col-auto">
          <Button
            :label="t('inbox.filters.failedQuick')"
            icon="pi pi-exclamation-triangle"
            size="small"
            severity="danger"
            :outlined="!filters.failedQuick"
            @click="emit('toggleFailed')"
          />
        </div>

        <!-- 3. Channel select -->
        <div class="col-md-2">
          <Select
            :model-value="filters.channel"
            :options="channelOptions"
            option-label="label"
            option-value="value"
            :placeholder="t('inbox.filters.allChannels')"
            show-clear
            class="w-100"
            @update:model-value="(v: ChannelKind | null) => emit('update:channel', v)"
          />
        </div>

        <!-- 4. Routing status select (hidden when failedQuick is active) -->
        <div v-if="!filters.failedQuick" class="col-md-2">
          <Select
            :model-value="filters.routingStatus"
            :options="statusOptions"
            option-label="label"
            option-value="value"
            :placeholder="t('inbox.filters.allStatuses')"
            show-clear
            class="w-100"
            @update:model-value="(v: RoutingStatus | null) => emit('update:routingStatus', v)"
          />
        </div>

        <!-- 5. Date range picker -->
        <div class="col-md-2">
          <DatePicker
            :model-value="filters.dateRange"
            selection-mode="range"
            show-clear
            date-format="dd.mm.yy"
            :placeholder="t('inbox.filters.dateRange')"
            class="w-100"
            @update:model-value="(v: Date | Date[] | (Date | null)[] | null | undefined) => emit('update:dateRange', (Array.isArray(v) && v.length === 2 && v[0] instanceof Date && v[1] instanceof Date) ? [v[0] as Date, v[1] as Date] : null)"
          />
        </div>

        <!-- 6. Search -->
        <div class="col">
          <IconField>
            <InputIcon class="pi pi-search" />
            <InputText
              :model-value="filters.q"
              :placeholder="t('inbox.filters.searchPlaceholder')"
              class="w-100"
              @input="(e: Event) => emit('search', (e.target as HTMLInputElement).value)"
            />
          </IconField>
        </div>

        <!-- 7. Reset button -->
        <div v-if="hasActiveFilters" class="col-auto">
          <Button
            :label="t('inbox.filters.reset')"
            icon="pi pi-filter-slash"
            severity="secondary"
            text
            @click="emit('reset')"
          />
        </div>
      </div>
    </template>
  </Card>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Card from 'primevue/card'
import Button from 'primevue/button'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import DatePicker from 'primevue/datepicker'
import IconField from 'primevue/iconfield'
import InputIcon from 'primevue/inputicon'
import InputText from 'primevue/inputtext'
import type { InboxFilters } from '../composables/useInboxPage'
import type { ChannelKind, RoutingStatus } from '@/api/inbox'

defineProps<{
  filters: InboxFilters
  hasActiveFilters: boolean
}>()

const emit = defineEmits<{
  'update:unreadOnly': [value: boolean]
  'update:channel': [value: ChannelKind | null]
  'update:routingStatus': [value: RoutingStatus | null]
  'update:dateRange': [value: [Date, Date] | null]
  search: [value: string]
  toggleFailed: []
  reset: []
}>()

const { t } = useI18n()

interface UnreadOption {
  label: string
  value: boolean
}

const unreadOption = computed<UnreadOption>(() => ({
  label: t('inbox.filters.unreadOnly'),
  value: true,
}))
const allOption = computed<UnreadOption>(() => ({
  label: t('inbox.filters.all'),
  value: false,
}))
const unreadOptions = computed<UnreadOption[]>(() => [unreadOption.value, allOption.value])

const channelOptions = computed(() => [
  { label: t('inbox.channelKind.tg'), value: 'tg' },
  { label: t('inbox.channelKind.wa'), value: 'wa' },
  { label: t('inbox.channelKind.email'), value: 'email' },
  { label: t('inbox.channelKind.web_form'), value: 'web_form' },
  { label: t('inbox.channelKind.api'), value: 'api' },
])

const statusOptions = computed(() => [
  { label: t('inbox.routingStatus.routed'), value: 'routed' },
  { label: t('inbox.routingStatus.dedup'), value: 'dedup' },
  { label: t('inbox.routingStatus.failed'), value: 'failed' },
])
</script>

<style lang="scss" scoped>
.inbox-filter-bar {
  margin-bottom: $space-3;

  :deep(.p-card-body) {
    padding: $space-3;
  }

  :deep(.p-card-content) {
    padding: 0;
  }
}
</style>
