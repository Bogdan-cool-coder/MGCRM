<template>
  <Drawer
    v-model:visible="visible"
    position="right"
    style="width: 420px"
    :modal="true"
    :pt="{ header: { class: 'channel-history-drawer__header' } }"
  >
    <template #header>
      <span class="channel-history-drawer__title">{{ t('crm.company.marketing.channelHistoryTitle') }}</span>
      <Button
        icon="pi pi-times"
        text
        severity="secondary"
        size="small"
        class="ms-auto"
        @click="visible = false"
      />
    </template>

    <!-- Loading -->
    <template v-if="loading">
      <div class="d-flex flex-column gap-3">
        <Skeleton height="64px" border-radius="8px" />
        <Skeleton height="64px" border-radius="8px" />
        <Skeleton height="64px" border-radius="8px" />
      </div>
    </template>

    <!-- Error -->
    <template v-else-if="error">
      <div class="channel-history-drawer__empty">
        <i class="pi pi-exclamation-triangle channel-history-drawer__empty-icon" />
        <p>{{ t('errors.server_error') }}</p>
        <Button
          icon="pi pi-refresh"
          :label="t('common.retry')"
          severity="secondary"
          size="small"
          @click="load"
        />
      </div>
    </template>

    <!-- Empty -->
    <template v-else-if="history.length === 0">
      <div class="channel-history-drawer__empty">
        <i class="pi pi-history channel-history-drawer__empty-icon" />
        <p>{{ t('crm.company.marketing.historyEmpty') }}</p>
      </div>
    </template>

    <!-- History list -->
    <template v-else>
      <div class="channel-history-drawer__list">
        <div
          v-for="entry in history"
          :key="entry.id"
          class="channel-history-drawer__item"
        >
          <div class="channel-history-drawer__row">
            <span class="channel-history-drawer__label">{{ t('crm.company.marketing.historyFrom') }}:</span>
            <span class="channel-history-drawer__value channel-history-drawer__value--from">
              {{ entry.from_channel || t('crm.company.marketing.noChannel') }}
            </span>
            <i class="pi pi-arrow-right channel-history-drawer__arrow" />
            <span class="channel-history-drawer__label">{{ t('crm.company.marketing.historyTo') }}:</span>
            <span class="channel-history-drawer__value channel-history-drawer__value--to">
              {{ entry.to_channel || t('crm.company.marketing.noChannel') }}
            </span>
          </div>
          <div class="channel-history-drawer__meta">
            <span class="channel-history-drawer__by">
              <i class="pi pi-user channel-history-drawer__meta-icon" />
              {{ entry.changed_by_name || '—' }}
            </span>
            <span class="channel-history-drawer__date">
              <i class="pi pi-calendar channel-history-drawer__meta-icon" />
              {{ formatDate(entry.changed_at) }}
            </span>
          </div>
        </div>
      </div>
    </template>
  </Drawer>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Drawer from 'primevue/drawer'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import { apiClient } from '@/api/client'
import type { ChannelHistoryEntry } from '@/entities/crm'

const props = defineProps<{
  /**
   * Full endpoint path, e.g.
   *   /api/companies/{id}/channel-history
   *   /api/contacts/{id}/channel-history
   */
  endpoint: string
}>()

const visible = defineModel<boolean>({ default: false })

const { t } = useI18n()

const history = ref<ChannelHistoryEntry[]>([])
const loading = ref(false)
const error = ref(false)

async function load() {
  loading.value = true
  error.value = false
  try {
    const res = await apiClient.get<{ data: ChannelHistoryEntry[] }>(props.endpoint)
    history.value = res.data.data ?? []
  } catch {
    error.value = true
  } finally {
    loading.value = false
  }
}

watch(visible, (val) => {
  if (val) void load()
})

function formatDate(iso: string): string {
  try {
    const d = new Date(iso)
    return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' })
      + ' ' + d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
  } catch {
    return iso
  }
}
</script>

<style lang="scss" scoped>
.channel-history-drawer__header {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.channel-history-drawer__title {
  font-weight: $font-weight-semibold;
  font-size: $font-size-base;
  color: $surface-900;

  .app-dark & {
    color: var(--p-surface-100);
  }
}

// Empty / error state
.channel-history-drawer__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-3;
  padding: $space-8 $space-4;
  text-align: center;
  color: $surface-500;
}

.channel-history-drawer__empty-icon {
  font-size: 2.5rem;
  color: $surface-300;
}

// History list
.channel-history-drawer__list {
  display: flex;
  flex-direction: column;
  gap: $space-3;
}

.channel-history-drawer__item {
  padding: $space-3 $space-4;
  border: 1px solid var(--p-surface-200);
  border-radius: $radius-md;
  display: flex;
  flex-direction: column;
  gap: $space-2;

  .app-dark & {
    border-color: var(--p-surface-700);
  }
}

.channel-history-drawer__row {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex-wrap: wrap;
}

.channel-history-drawer__label {
  font-size: $font-size-xs;
  color: $surface-400;
  white-space: nowrap;
}

.channel-history-drawer__value {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-700;

  .app-dark & {
    color: var(--p-surface-200);
  }

  &--to {
    color: var(--p-primary-color);

    .app-dark & {
      color: var(--p-primary-300);
    }
  }
}

.channel-history-drawer__arrow {
  font-size: 10px;
  color: $surface-400;
}

.channel-history-drawer__meta {
  display: flex;
  align-items: center;
  gap: $space-4;
}

.channel-history-drawer__by,
.channel-history-drawer__date {
  display: flex;
  align-items: center;
  gap: $space-1;
  font-size: $font-size-xs;
  color: $surface-500;
}

.channel-history-drawer__meta-icon {
  font-size: 10px;
}
</style>
