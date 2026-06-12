<template>
  <Card class="widget-card h-100">
    <template #title>
      {{ t('managerCabinet.team.title') }}
    </template>
    <template #content>
      <!-- Loading skeleton -->
      <template v-if="loading">
        <Skeleton height="200px" />
      </template>

      <!-- Content -->
      <template v-else-if="kpi">
        <DataTable
          :value="kpi.team.members"
          size="small"
          :striped-rows="true"
          row-hover
          :row-class="rowClass"
          class="team-table"
        >
          <!-- Сотрудник -->
          <Column :header="t('managerCabinet.team.name')">
            <template #body="{ data: row }">
              <span :class="{ 'team-table__viewer-name': row.is_viewer }">
                <i v-if="row.is_viewer" class="pi pi-user me-1" style="font-size: 12px" />
                {{ row.full_name }}
              </span>
            </template>
          </Column>

          <!-- МК% -->
          <Column
            field="score_pct"
            :header="t('managerCabinet.team.scorePct')"
            style="width: 80px"
          >
            <template #body="{ data: row }">
              {{ row.score_pct }}%
            </template>
          </Column>

          <!-- Статус -->
          <Column :header="t('managerCabinet.team.status')" style="width: 130px">
            <template #body="{ data: row }">
              <Tag
                :severity="badgeFor(row.score_pct)"
                :value="labelFor(row.score_pct)"
                size="small"
              />
            </template>
          </Column>
        </DataTable>

        <!-- Footer: Среднее -->
        <div
          v-if="kpi.team.size > 1"
          class="team-table__footer d-flex align-items-center gap-2 mt-3 pt-3"
        >
          <i class="pi pi-chart-bar surface-600" />
          <span class="font-size-sm surface-600">
            {{ t('managerCabinet.team.average') }}:
            <strong>{{ kpi.team.avg_pct }}%</strong>
          </span>
        </div>
      </template>

      <!-- Empty state: alone -->
      <template v-else>
        <div class="team-table__empty">
          <i class="pi pi-users team-table__empty-icon" />
          <p class="team-table__empty-text">{{ t('managerCabinet.team.alone') }}</p>
        </div>
      </template>
    </template>
  </Card>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Card from 'primevue/card'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Skeleton from 'primevue/skeleton'
import Tag from 'primevue/tag'
import type { KpiResponse, TeamMember } from '@/entities/managerCabinet'

defineProps<{
  kpi: KpiResponse | null
  loading: boolean
}>()

const { t } = useI18n()

const rowClass = (row: TeamMember): Record<string, boolean> => ({
  'team-table__row--viewer': row.is_viewer,
})

const badgeFor = (pct: number): 'success' | 'warning' | 'danger' => {
  if (pct >= 100) return 'success'
  if (pct >= 80) return 'warning'
  return 'danger'
}

const labelFor = (pct: number): string => {
  if (pct >= 100) return t('managerCabinet.kpi.excellent')
  if (pct >= 80) return t('managerCabinet.kpi.good')
  return t('managerCabinet.kpi.needsWork')
}
</script>

<style lang="scss" scoped>
.widget-card {
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
}

.team-table {
  :deep(.team-table__row--viewer) {
    td {
      font-weight: $font-weight-semibold;
      color: var(--p-primary-color);
    }
  }
}

.team-table__viewer-name {
  font-weight: $font-weight-semibold;
  color: var(--p-primary-color);
}

.team-table__footer {
  border-top: 1px solid $surface-200;
}

.team-table__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: $space-8;
  gap: $space-3;
  min-height: 120px;
}

.team-table__empty-icon {
  font-size: 2.5rem;
  color: $surface-400;
}

.team-table__empty-text {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0;
}
</style>
