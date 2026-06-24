<template>
  <Card class="widget-card">
    <template #title>
      {{ t('managerCabinet.feed.title') }}
    </template>
    <template #content>
      <!-- Filter toolbar -->
      <div class="activity-feed__filter d-flex flex-wrap align-items-center gap-2 mb-3">
        <Button
          v-for="k in kindOptions"
          :key="k.value"
          :label="t(k.labelKey)"
          :severity="feedKind === k.value ? 'primary' : 'secondary'"
          :outlined="feedKind !== k.value"
          size="small"
          @click="emit('update:feedKind', k.value)"
        />
        <ToggleButton
          :model-value="feedFtmOnly"
          :on-label="t('managerCabinet.feed.ftmOnly')"
          :off-label="t('managerCabinet.feed.ftmOnly')"
          size="small"
          class="ms-auto"
          @update:model-value="(v) => emit('update:feedFtmOnly', v as boolean)"
        />
      </div>

      <!-- Loading skeleton -->
      <template v-if="feedLoading && feed.length === 0">
        <Skeleton height="220px" />
      </template>

      <!-- Empty state -->
      <template v-else-if="!feedLoading && feed.length === 0">
        <div class="activity-feed__empty">
          <i class="pi pi-list activity-feed__empty-icon" />
          <p class="activity-feed__empty-text">{{ t('managerCabinet.feed.noActivity') }}</p>
          <Button
            v-if="feedKind !== 'all' || feedFtmOnly"
            :label="t('managerCabinet.feed.resetFilters')"
            severity="secondary"
            text
            size="small"
            @click="emit('reset-filters')"
          />
        </div>
      </template>

      <!-- Data table -->
      <template v-else>
        <DataTable
          :value="feed"
          size="small"
          row-hover
          :loading="feedLoading"
          class="activity-feed__table"
        >
          <!-- Тип + Название -->
          <Column :header="t('managerCabinet.feed.title')" style="min-width: 180px">
            <template #body="{ data: row }">
              <div class="d-flex align-items-start gap-2">
                <i
                  :class="['pi', kindIcon(row.kind), 'activity-feed__kind-icon', 'flex-shrink-0']"
                  :style="{ color: kindColor(row.kind) }"
                />
                <span class="activity-feed__title-text">{{ row.title }}</span>
              </div>
            </template>
          </Column>

          <!-- Дата -->
          <Column :header="t('managerCabinet.feed.date')" style="width: 140px">
            <template #body="{ data: row }">
              <span class="activity-feed__date">{{ formatDate(row.due_at ?? row.created_at) }}</span>
            </template>
          </Column>

          <!-- Объект -->
          <Column :header="t('managerCabinet.feed.target')" style="width: 130px">
            <template #body="{ data: row }">
              <router-link
                v-if="targetRoute(row)"
                :to="targetRoute(row)!"
                class="activity-feed__target activity-feed__target--link"
              >
                {{ targetLabel(row) }}
              </router-link>
              <span
                v-else-if="row.target_type && row.target_id"
                class="activity-feed__target"
              >
                {{ targetLabel(row) }}
              </span>
              <span v-else class="activity-feed__target-none">&mdash;</span>
            </template>
          </Column>

          <!-- Бейджи -->
          <Column header="" style="width: 80px">
            <template #body="{ data: row }">
              <Tag
                v-if="row.ftm_counted"
                severity="success"
                value="FTM"
                size="small"
              />
            </template>
          </Column>
        </DataTable>

        <!-- Paginator -->
        <Paginator
          v-if="feedMeta && feedMeta.total > feedMeta.per_page"
          :rows="feedMeta.per_page"
          :total-records="feedMeta.total"
          :first="(feedMeta.current_page - 1) * feedMeta.per_page"
          class="mt-2"
          @page="(e) => emit('update:feedPage', e.page + 1)"
        />
      </template>
    </template>
  </Card>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { RouteLocationRaw } from 'vue-router'
import Card from 'primevue/card'
import Button from 'primevue/button'
import ToggleButton from 'primevue/togglebutton'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Skeleton from 'primevue/skeleton'
import Tag from 'primevue/tag'
import Paginator from 'primevue/paginator'
import type { ActivityFeedItem, ActivityFeedMeta } from '@/entities/managerCabinet'

defineProps<{
  feed: ActivityFeedItem[]
  feedLoading: boolean
  feedMeta: ActivityFeedMeta | null
  feedKind: 'all' | 'call' | 'meeting' | 'task' | 'note'
  feedFtmOnly: boolean
}>()

const emit = defineEmits<{
  'update:feedKind': ['all' | 'call' | 'meeting' | 'task' | 'note']
  'update:feedFtmOnly': [boolean]
  'update:feedPage': [number]
  'reset-filters': []
}>()

const { t, locale } = useI18n()

const kindOptions: { value: 'all' | 'call' | 'meeting' | 'task' | 'note'; labelKey: string }[] = [
  { value: 'all', labelKey: 'managerCabinet.feed.filterAll' },
  { value: 'call', labelKey: 'managerCabinet.feed.filterCall' },
  { value: 'meeting', labelKey: 'managerCabinet.feed.filterMeeting' },
  { value: 'task', labelKey: 'managerCabinet.feed.filterTask' },
  { value: 'note', labelKey: 'managerCabinet.feed.filterNote' },
]

const kindIcon = (kind: ActivityFeedItem['kind']): string => {
  const map: Record<ActivityFeedItem['kind'], string> = {
    call: 'pi-phone',
    meeting: 'pi-users',
    task: 'pi-check-square',
    note: 'pi-pencil',
  }
  return map[kind] ?? 'pi-circle'
}

const kindColor = (kind: ActivityFeedItem['kind']): string => {
  const map: Record<ActivityFeedItem['kind'], string> = {
    call: 'var(--p-blue-500)',
    meeting: 'var(--p-primary-color)',
    task: 'var(--p-orange-500)',
    note: 'var(--p-surface-500, #7E7F82)',
  }
  return map[kind] ?? ''
}

const targetLabel = (row: ActivityFeedItem): string => {
  if (!row.target_type || row.target_id == null) return '—'
  const map: Record<string, string> = {
    deal: t('managerCabinet.feed.targetDeal'),
    contact: t('managerCabinet.feed.targetContact'),
    company: t('managerCabinet.feed.targetCompany'),
  }
  const label = map[row.target_type] ?? row.target_type
  return `${label} #${row.target_id}`
}

const targetRoute = (row: ActivityFeedItem): RouteLocationRaw | null => {
  if (!row.target_type || row.target_id == null) return null
  const map: Record<string, string> = {
    deal: 'DealDetail',
    contact: 'ContactDetail',
    company: 'CompanyDetail',
  }
  const name = map[row.target_type]
  if (!name) return null
  return { name, params: { id: row.target_id } }
}

const formatDate = (dateStr: string | null): string => {
  if (!dateStr) return '—'
  try {
    const d = new Date(dateStr)
    return d.toLocaleString(locale.value, {
      day: 'numeric',
      month: 'short',
      hour: '2-digit',
      minute: '2-digit',
    })
  } catch {
    return dateStr
  }
}
</script>

<style lang="scss" scoped>
.widget-card {
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
}

.activity-feed__filter {
  border-bottom: 1px solid $surface-200;
  padding-bottom: $space-3;
}

.activity-feed__kind-icon {
  font-size: $font-size-sm;
  margin-top: 2px;
}

.activity-feed__title-text {
  font-size: $font-size-sm;
  color: $surface-900;
  word-break: break-word;
}

.activity-feed__date {
  font-size: $font-size-sm;
  color: $surface-600;
  white-space: nowrap;
}

.activity-feed__target {
  font-size: $font-size-sm;
  color: $surface-600;
}

.activity-feed__target--link {
  color: var(--p-primary-color);
  text-decoration: none;

  &:hover {
    text-decoration: underline;
  }
}

.activity-feed__target-none {
  font-size: $font-size-sm;
  color: $surface-400;
}

.activity-feed__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: $space-8;
  gap: $space-3;
  min-height: 140px;
}

.activity-feed__empty-icon {
  font-size: $font-size-icon-xl;
  color: $surface-400;
}

.activity-feed__empty-text {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0;
}
</style>
