<template>
  <Card class="widget-card h-100">
    <template #title>
      <div class="notask-header">
        <span class="notask-header__title">{{ t('dashboard.dealsWithoutTasks.title') }}</span>
        <button
          v-if="(data?.count ?? 0) > 0"
          type="button"
          class="notask-header__badge"
          :title="t('dashboard.dealsWithoutTasks.needAttention', { count: data!.count })"
          @click="openList()"
        >
          <i class="pi pi-exclamation-triangle notask-header__badge-icon" />
          {{ t('dashboard.dealsWithoutTasks.needAttention', { count: data!.count }) }}
          <i class="pi pi-arrow-right notask-header__badge-arrow" />
        </button>
      </div>
    </template>

    <template #content>
      <!-- Loading (main dashboard OR preview fetch) -->
      <template v-if="loading || previewLoading">
        <div class="notask-skeleton">
          <Skeleton v-for="n in 4" :key="n" height="44px" border-radius="8px" class="mb-2" />
        </div>
      </template>

      <!-- Main dashboard has no data at all -->
      <template v-else-if="data === null">
        <div class="notask-empty">
          <i class="pi pi-minus-circle notask-empty__icon notask-empty__icon--neutral" />
          <p class="notask-empty__text">{{ t('dashboard.dealsWithoutTasks.noData') }}</p>
        </div>
      </template>

      <!-- Empty: all deals have tasks -->
      <template v-else-if="data.count === 0">
        <div class="notask-empty">
          <i class="pi pi-check-circle notask-empty__icon notask-empty__icon--success" />
          <p class="notask-empty__text">{{ t('dashboard.dealsWithoutTasks.allHaveTasks') }}</p>
        </div>
      </template>

      <!-- Preview request failed → fall back to the plain counter + open-list CTA -->
      <template v-else-if="previewFailed">
        <div class="notask-fallback">
          <span class="notask-fallback__count">{{ data.count }}</span>
          <p class="notask-fallback__text">{{ t('dashboard.dealsWithoutTasks.subtitle') }}</p>
          <Button
            icon="pi pi-arrow-right"
            icon-pos="right"
            :label="t('dashboard.dealsWithoutTasks.openList')"
            severity="warning"
            outlined
            size="small"
            class="mt-2"
            @click="openList()"
          />
        </div>
      </template>

      <!-- Preview list -->
      <template v-else>
        <ul class="notask-list">
          <li v-for="deal in deals" :key="deal.id" class="notask-item">
            <div class="notask-row">
              <button
                type="button"
                class="notask-row__main"
                :title="deal.title"
                @click="goToDeal(deal.id)"
              >
                <span class="notask-row__title">{{ deal.title }}</span>
                <span class="notask-row__meta">
                  <span class="notask-row__amount">{{ formatCurrency(deal.amount, deal.currency) }}</span>
                  <span class="notask-row__sep">·</span>
                  <span class="notask-row__stage">{{ deal.stage.name }}</span>
                  <span
                    class="notask-row__days"
                    :class="{ 'notask-row__days--late': isLate(deal) }"
                  >
                    · {{ t('dashboard.dealsWithoutTasks.daysInStage', { days: deal.days_in_stage ?? 0 }) }}
                  </span>
                </span>
              </button>

              <Button
                v-if="openFormFor !== deal.id"
                icon="pi pi-plus"
                :label="t('dashboard.dealsWithoutTasks.addTask')"
                severity="secondary"
                outlined
                size="small"
                class="notask-row__add"
                @click="openForm(deal.id)"
              />
            </div>

            <!-- Inline task quick-form (reuse) -->
            <div v-if="openFormFor === deal.id" class="notask-item__form">
              <TaskQuickForm
                mode="create"
                target-type="deal"
                :target-id="deal.id"
                :auto-focus="true"
                :closable="true"
                :require-due-date="true"
                @created="onTaskCreated(deal.id)"
                @cancel="closeForm()"
              />
            </div>
          </li>
        </ul>
      </template>
    </template>
  </Card>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import Card from 'primevue/card'
import Skeleton from 'primevue/skeleton'
import Button from 'primevue/button'
import TaskQuickForm from '@/components/tasks/TaskQuickForm.vue'
import { formatCurrency } from '@/utils/currency'
import type { DealsWithoutTasksData } from '@/entities/salesDashboard'
import type { DealDto } from '@/entities/sales'

const { t } = useI18n()
const router = useRouter()
const toast = useToast()

const props = defineProps<{
  data: DealsWithoutTasksData | null
  loading: boolean
  deals: DealDto[]
  previewLoading: boolean
  previewFailed: boolean
}>()

const emit = defineEmits<{
  /** A task was created for a deal — parent drops it + decrements the badge. */
  'task-created': [dealId: number]
}>()

const LATE_THRESHOLD_DAYS = 14

/** Red state when the deal has sat in its stage ≥14 days (ГЭП-1 proxy). */
const isLate = (deal: DealDto): boolean => (deal.days_in_stage ?? 0) >= LATE_THRESHOLD_DAYS

// ─── Inline task form ─────────────────────────────────────────────────────────
const openFormFor = ref<number | null>(null)

const openForm = (dealId: number): void => {
  openFormFor.value = dealId
}

const closeForm = (): void => {
  openFormFor.value = null
}

const onTaskCreated = (dealId: number): void => {
  closeForm()
  toast.add({
    severity: 'success',
    summary: t('dashboard.dealsWithoutTasks.taskCreated'),
    life: 3000,
  })
  emit('task-created', dealId)
}

// ─── Navigation ───────────────────────────────────────────────────────────────
const goToDeal = (id: number): void => {
  void router.push(`/deals/${id}`)
}

/**
 * SPA-navigate to the filtered deals list via the backend deep-link (filter_url),
 * parsed into a router location so DealsPage reads pipeline_id + only_no_task from
 * route.query. A full-page href would lose the Bearer/SPA state.
 */
const openList = (): void => {
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

// ─── Header ──────────────────────────────────────────────────────────────────
.notask-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $space-3;
  flex-wrap: wrap;
}

.notask-header__title {
  font-size: $font-size-md;
  font-weight: $font-weight-semibold;
  color: $surface-800;
}

.notask-header__badge {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  font-size: $font-size-xs;
  font-weight: $font-weight-bold;
  color: $status-warning-text;
  background: $status-warning-bg;
  border: 1px solid transparent;
  border-radius: $radius-pill;
  padding: $space-1 $space-3;
  cursor: pointer;
  transition: border-color var(--app-transition-fast);

  &:hover {
    border-color: $status-warning-text;
  }
}

.notask-header__badge-icon,
.notask-header__badge-arrow {
  font-size: $font-size-2xs;
}

// ─── Empty / neutral states ──────────────────────────────────────────────────
.notask-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: $space-2;
  min-height: 160px;
  text-align: center;
}

.notask-empty__icon {
  font-size: $font-size-icon-lg;

  &--neutral {
    color: $surface-400;
  }

  &--success {
    color: $status-success-text;
  }
}

.notask-empty__text {
  font-size: $font-size-sm;
  color: $surface-600;
  margin: 0;
  max-width: 220px;
}

// ─── Failure fallback (counter only) ─────────────────────────────────────────
.notask-fallback {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: $space-2;
  min-height: 160px;
  text-align: center;
}

.notask-fallback__count {
  font-size: $font-size-icon-lg;
  font-weight: $font-weight-bold;
  color: $status-danger-text;
  line-height: 1;
}

.notask-fallback__text {
  font-size: $font-size-sm;
  color: $surface-600;
  margin: 0;
  max-width: 220px;
}

// ─── Preview list ────────────────────────────────────────────────────────────
.notask-list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.notask-item {
  border-top: 1px solid $surface-200;

  &:first-child {
    border-top: none;
  }
}

.notask-row {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-2 $space-1;
}

.notask-row__main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 3px;
  text-align: left;
  background: transparent;
  border: none;
  padding: 0;
  cursor: pointer;
}

.notask-row__title {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-800;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.notask-row__meta {
  display: flex;
  align-items: center;
  gap: $space-1;
  flex-wrap: wrap;
  font-size: $font-size-xs;
  color: $surface-500;
}

.notask-row__amount {
  font-weight: $font-weight-semibold;
  color: $surface-700;
}

.notask-row__stage {
  color: $surface-600;
}

.notask-row__days {
  color: $status-warning-text;
  font-weight: $font-weight-semibold;

  &--late {
    color: $status-danger-text;
  }
}

.notask-row__add {
  flex-shrink: 0;
}

.notask-item__form {
  padding: 0 $space-1 $space-2;
}
</style>
