<template>
  <DataTable
    :value="rows"
    :loading="loading"
    row-hover
    :rows="25"
    :total-records="totalRows"
    lazy
    paginator
    :rows-per-page-options="[25, 50, 100]"
    @page="emit('page', $event)"
  >
    <template #empty>
      <div class="hr-progress-table__empty">
        <i class="pi pi-graduation-cap hr-progress-table__empty-icon" />
        <p class="hr-progress-table__muted mt-2">{{ t('onboarding.hrProgress.empty') }}</p>
      </div>
    </template>

    <Column :header="t('onboarding.hrProgress.columns.employee')">
      <template #body="{ data: r }">
        {{ r.user_name }}
      </template>
    </Column>

    <Column :header="t('onboarding.hrProgress.columns.course')">
      <template #body="{ data: r }">
        <router-link
          :to="{ name: 'CourseBuilder', params: { id: r.course_id } }"
          class="hr-progress-table__link"
        >
          {{ r.course_title }}
        </router-link>
      </template>
    </Column>

    <Column :header="t('onboarding.hrProgress.columns.progress')" style="width: 160px">
      <template #body="{ data: r }">
        <div class="hr-progress-table__progress d-flex align-items-center">
          <ProgressBar :value="r.progress_pct" style="height: 8px; flex: 1;" />
          <span class="hr-progress-table__pct">{{ r.progress_pct }}%</span>
        </div>
      </template>
    </Column>

    <Column :header="t('onboarding.hrProgress.columns.status')" style="width: 150px">
      <template #body="{ data: r }">
        <AssignmentStatusTag :status="r.status" />
      </template>
    </Column>

    <Column :header="t('onboarding.hrProgress.columns.deadline')" style="width: 110px">
      <template #body="{ data: r }">
        <span
          v-if="r.due_date"
          :class="{ 'hr-progress-table__deadline--overdue': r.status === 'overdue' }"
        >
          {{ formatDate(r.due_date) }}
        </span>
        <span v-else class="hr-progress-table__muted">—</span>
      </template>
    </Column>

    <Column :header="t('onboarding.hrProgress.columns.avgScore')" style="width: 110px; text-align: center">
      <template #body="{ data: r }">
        <span v-if="r.avg_quiz_score != null">{{ r.avg_quiz_score }}%</span>
        <span v-else class="hr-progress-table__muted">—</span>
      </template>
    </Column>
  </DataTable>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import ProgressBar from 'primevue/progressbar'
import AssignmentStatusTag from '@/components/shared/AssignmentStatusTag.vue'
import type { HrProgressRow } from '@/api/onboardingAdmin'

defineProps<{
  rows: HrProgressRow[]
  loading: boolean
  totalRows: number
}>()

const emit = defineEmits<{
  page: [event: { page: number; rows: number }]
}>()

const { t, locale } = useI18n()

function formatDate(dateStr: string): string {
  return new Date(dateStr).toLocaleDateString(locale.value, {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  })
}
</script>

<style lang="scss" scoped>
.hr-progress-table {
  &__empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: $space-8; // 2.5rem = 40px, closest token $space-8 = 32px; snap 8px — padding not guarded by lint:ds
  }

  &__empty-icon {
    font-size: $font-size-icon-lg; // 2rem
    color: var(--p-surface-400);
  }

  &__pct {
    font-size: $font-size-2xs; // snap from 0.8rem (12.8px→11px); alt $font-size-xs (12px) snap -0.8px
    white-space: nowrap;
  }

  &__link {
    color: var(--p-primary-color);
    text-decoration: none;

    &:hover {
      text-decoration: underline;
    }
  }

  &__progress {
    gap: $space-2;
  }

  // Theme-reactive muted placeholder — replaces dead full-Bootstrap .text-muted.
  &__muted {
    color: var(--p-text-muted-color);
  }

  // .text-danger is a dead full-Bootstrap class → overdue tint via token here.
  &__deadline--overdue {
    color: var(--p-red-500);
  }
}
</style>
