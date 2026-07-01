<template>
  <div class="assignments-page">
    <PageHeader :title="t('onboarding.assignments.title')" icon="pi pi-users">
      <template #actions>
        <Button
          :label="t('onboarding.assignments.assign')"
          icon="pi pi-plus"
          @click="showAssignDrawer = true"
        />
      </template>
    </PageHeader>

    <AssignmentsFilterPanel
      :filters="filters"
      @change="applyFilters"
      @reset="resetFilters"
    />

    <DataTable
      :value="assignments"
      :loading="loading"
      row-hover
      :rows="filters.per_page"
      :total-records="totalRecords"
      lazy
      paginator
      :rows-per-page-options="[25, 50, 100]"
      @page="onPage"
    >
      <template #empty>
        <div class="assignments-page__empty">
          <i class="pi pi-users assignments-page__empty-icon" />
          <p>{{ filters.status ? t('onboarding.assignments.emptyFiltered') : t('onboarding.assignments.empty') }}</p>
        </div>
      </template>

      <Column :header="t('onboarding.assignments.columns.employee')">
        <template #body="{ data: a }">
          {{ a.user_name ?? a.user?.full_name ?? '—' }}
        </template>
      </Column>

      <Column :header="t('onboarding.assignments.columns.course')">
        <template #body="{ data: a }">
          <router-link
            :to="{ name: 'CourseBuilder', params: { id: a.course_id } }"
            class="assignments-page__link"
          >
            {{ a.course?.title }}
          </router-link>
        </template>
      </Column>

      <Column :header="t('onboarding.assignments.columns.status')" style="width: 150px">
        <template #body="{ data: a }">
          <AssignmentStatusTag :status="a.status" />
        </template>
      </Column>

      <Column :header="t('onboarding.assignments.columns.progress')" style="width: 160px">
        <template #body="{ data: a }">
          <div class="d-flex align-items-center gap-2">
            <ProgressBar :value="a.progress_pct" style="height: 8px; flex: 1;" />
            <span class="assignments-page__progress-pct">{{ a.progress_pct }}%</span>
          </div>
        </template>
      </Column>

      <Column :header="t('onboarding.assignments.columns.deadline')" style="width: 110px">
        <template #body="{ data: a }">
          <span
            v-if="a.due_date"
            :class="{ 'text-danger': a.status === 'overdue' }"
          >
            {{ formatDate(a.due_date) }}
          </span>
          <span v-else class="text-muted">—</span>
        </template>
      </Column>

      <Column style="width: 80px">
        <template #body="{ data: a }">
          <div class="d-flex gap-1">
            <Button
              icon="pi pi-calendar"
              size="small"
              text
              severity="secondary"
              :title="t('onboarding.assignments.editDeadline')"
              @click="openEditDeadline(a)"
            />
            <Button
              icon="pi pi-box"
              size="small"
              text
              severity="secondary"
              :title="t('onboarding.assignments.archive')"
              :disabled="a.status === 'archived'"
              @click="archiveAssignment(a)"
            />
            <Button
              v-if="a.progress_pct === 0"
              icon="pi pi-trash"
              size="small"
              text
              severity="danger"
              :title="t('onboarding.assignments.delete')"
              @click="deleteAssignmentConfirm(a)"
            />
          </div>
        </template>
      </Column>
    </DataTable>

    <!-- Edit deadline dialog -->
    <EditDeadlineDialog
      v-model:visible="deadlineDialogVisible"
      :assignment="editingAssignment"
      @save="saveDeadline"
    />

    <!-- Assign drawer (reuse from builder) -->
    <AssignCourseDrawerStub
      v-model:visible="showAssignDrawer"
      @assigned="loadAssignments"
    />

    <ConfirmDialog />
  </div>
</template>

<script setup lang="ts">
import { onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import ProgressBar from 'primevue/progressbar'
import ConfirmDialog from 'primevue/confirmdialog'
import PageHeader from '@/components/AppShell/PageHeader.vue'
import AssignmentStatusTag from '@/components/shared/AssignmentStatusTag.vue'
import AssignmentsFilterPanel from './components/AssignmentsFilterPanel.vue'
import EditDeadlineDialog from './components/EditDeadlineDialog.vue'
import { useAssignmentsPage } from './composables/useAssignmentsPage'

// Inline stub component for the global assign (no specific course, implemented in Phase 2)
const AssignCourseDrawerStub = {
  name: 'AssignCourseDrawerStub',
  props: ['visible'],
  emits: ['update:visible', 'assigned'],
  setup() {
    return () => null
  },
}

const { t, locale } = useI18n()

const {
  assignments,
  loading,
  totalRecords,
  filters,
  showAssignDrawer,
  deadlineDialogVisible,
  editingAssignment,
  loadAssignments,
  onPage,
  applyFilters,
  resetFilters,
  openEditDeadline,
  saveDeadline,
  archiveAssignment,
  deleteAssignmentConfirm,
} = useAssignmentsPage()

function formatDate(dateStr: string): string {
  return new Date(dateStr).toLocaleDateString(locale.value, {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  })
}

onMounted(() => {
  void loadAssignments()
})
</script>

<style lang="scss" scoped>
.assignments-page {
  &__link {
    color: var(--p-primary-color);
    text-decoration: none;
    font-weight: $font-weight-medium;

    &:hover {
      text-decoration: underline;
    }
  }

  &__empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: $space-3;
    padding: $space-8;
    text-align: center;
  }

  &__empty-icon {
    font-size: $font-size-icon-2xl;
    color: var(--p-surface-400);
  }

  &__progress-pct {
    font-size: $font-size-2xs; // snap from 0.8rem (12.8px)
    white-space: nowrap;
  }
}
</style>
