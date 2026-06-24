<template>
  <Card class="widget-card h-100">
    <template #title>{{ t('dashboard.dealsWithoutTasks.title') }}</template>
    <template #content>
      <!-- Loading skeleton -->
      <template v-if="loading">
        <Skeleton height="160px" border-radius="8px" />
      </template>

      <!-- Error / no-data state -->
      <template v-else-if="data === null">
        <div class="deals-no-tasks deals-no-tasks--empty d-flex flex-column align-items-center justify-content-center text-center h-100">
          <i class="pi pi-minus-circle deals-no-tasks__icon deals-no-tasks__icon--neutral" />
          <p class="deals-no-tasks__subtitle">
            {{ t('dashboard.dealsWithoutTasks.noData') }}
          </p>
        </div>
      </template>

      <!-- Content -->
      <template v-else>
        <div class="deals-no-tasks d-flex flex-column align-items-center justify-content-center text-center h-100">
          <i
            class="pi deals-no-tasks__icon"
            :class="iconClass"
            :style="iconStyle"
          />

          <span class="deals-no-tasks__count" :class="countClass">
            {{ data.count }}
          </span>

          <p class="deals-no-tasks__subtitle">
            <template v-if="data.count === 0">
              {{ t('dashboard.dealsWithoutTasks.allHaveTasks') }}
            </template>
            <template v-else>
              {{ t('dashboard.dealsWithoutTasks.subtitle') }}
            </template>
          </p>

          <Button
            icon="pi pi-arrow-right"
            icon-pos="right"
            :label="t('dashboard.dealsWithoutTasks.openList')"
            :severity="data.count > 0 ? 'warning' : 'secondary'"
            :outlined="data.count > 0"
            :text="data.count === 0"
            class="mt-3"
            @click="openList()"
          />
        </div>
      </template>
    </template>
  </Card>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import Card from 'primevue/card'
import Skeleton from 'primevue/skeleton'
import Button from 'primevue/button'
import type { DealsWithoutTasksData } from '@/entities/salesDashboard'

const { t } = useI18n()
const router = useRouter()

const props = defineProps<{
  data: DealsWithoutTasksData | null
  loading: boolean
}>()

/**
 * SPA-navigate to the deals list using the backend deep-link (filter_url), parsed
 * into a typed router location so the DealsPage can read pipeline_id + only_no_task
 * from route.query. A full-page <a href> would lose the Bearer/SPA state and the
 * DealsPage reads its filters from route.query, not from a reload.
 */
function openList(): void {
  const filterUrl = props.data?.filter_url
  if (!filterUrl) return

  const [path = '/deals', queryString = ''] = filterUrl.split('?')
  const query: Record<string, string> = {}
  for (const pair of queryString.split('&')) {
    if (!pair) continue
    const [rawKey = '', rawValue = ''] = pair.split('=')
    const key = decodeURIComponent(rawKey)
    if (key) query[key] = decodeURIComponent(rawValue)
  }

  void router.push({ path, query })
}

const iconClass = computed(() =>
  (props.data?.count ?? 0) > 0 ? 'pi-exclamation-triangle' : 'pi-check-circle',
)

const iconStyle = computed(() => ({
  color: (props.data?.count ?? 0) > 0 ? 'var(--p-orange-500)' : 'var(--p-green-500)',
}))

const countClass = computed(() => ({
  'deals-no-tasks__count--danger': (props.data?.count ?? 0) > 0,
  'deals-no-tasks__count--success': (props.data?.count ?? 0) === 0,
}))
</script>

<style lang="scss" scoped>
.widget-card {
  :deep(.p-card-title) {
    font-size: $font-size-md;
    font-weight: $font-weight-semibold;
    color: $surface-800;
  }

  :deep(.p-card-content) {
    height: 100%;
  }
}

.deals-no-tasks {
  min-height: 160px;
  gap: $space-2;
  padding: $space-4 0;
}

.deals-no-tasks--empty {
  opacity: 0.5;
}

.deals-no-tasks__icon {
  font-size: $font-size-icon-lg;
  margin-bottom: $space-2;

  &--neutral {
    color: $surface-400;
  }
}

.deals-no-tasks__count {
  font-size: $font-size-icon-lg;
  font-weight: $font-weight-bold;
  line-height: 1;

  &--danger { color: $status-danger-text; }
  &--success { color: $status-success-text; }
}

.deals-no-tasks__subtitle {
  font-size: $font-size-sm;
  color: $surface-600;
  margin: 0;
  max-width: 200px;
}
</style>
