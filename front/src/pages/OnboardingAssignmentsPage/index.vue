<template>
  <div class="assignments-page">
    <ListToolbar
      icon="pi-users"
      :title="t('onboarding.assignments.title')"
      :subtitle="t('onboarding.assignments.subtitle', { count: totalRecords })"
    >
      <template #filter>
        <AssignmentsFilterPanel
          :filters="filters"
          @change="applyFilters"
          @reset="resetFilters"
        />
      </template>
      <template #actions>
        <Button
          :label="t('onboarding.assignments.assign')"
          icon="pi pi-plus"
          @click="showAssignDrawer = true"
        />
      </template>
    </ListToolbar>

    <div class="assignments-page__body">
      <div class="assignments-page__card">
        <DataTable
          :value="assignments"
          :loading="loading"
          row-hover
          striped-rows
          :rows="filters.per_page"
          :total-records="totalRecords"
          lazy
          paginator
          :rows-per-page-options="[25, 50, 100]"
          class="assignments-page__table"
          @page="onPage"
        >
          <template #empty>
            <div class="assignments-page__empty">
              <i
                :class="filters.status ? 'pi pi-filter-slash' : 'pi pi-users'"
                class="assignments-page__empty-icon"
              />
              <p class="assignments-page__empty-text">
                {{ filters.status ? t('onboarding.assignments.emptyFiltered') : t('onboarding.assignments.empty') }}
              </p>
              <p v-if="!filters.status" class="assignments-page__empty-hint">
                {{ t('onboarding.assignments.emptyHint') }}
              </p>
              <Button
                v-if="!filters.status"
                :label="t('onboarding.assignments.assign')"
                icon="pi pi-plus"
                size="small"
                @click="showAssignDrawer = true"
              />
              <Button
                v-else
                :label="t('onboarding.assignments.filter.reset')"
                severity="secondary"
                icon="pi pi-filter-slash"
                size="small"
                @click="resetFilters"
              />
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
              <div class="assignments-page__progress d-flex align-items-center">
                <ProgressBar :value="a.progress_pct" style="height: 8px; flex: 1;" />
                <span class="assignments-page__progress-pct">{{ a.progress_pct }}%</span>
              </div>
            </template>
          </Column>

          <Column :header="t('onboarding.assignments.columns.deadline')" style="width: 110px">
            <template #body="{ data: a }">
              <span
                v-if="a.due_date"
                :class="{ 'assignments-page__deadline--overdue': a.status === 'overdue' }"
              >
                {{ formatDate(a.due_date) }}
              </span>
              <span v-else class="assignments-page__muted">—</span>
            </template>
          </Column>

          <!-- Actions (kebab) -->
          <Column style="width: 60px">
            <template #body="{ data: a }">
              <Button
                icon="pi pi-ellipsis-v"
                size="small"
                text
                severity="secondary"
                :aria-label="t('common.actions')"
                @click="toggleRowMenu($event, a.id)"
              />
              <Menu
                :ref="(el) => setRowMenuRef(a.id, el)"
                :model="getRowMenuItems(a)"
                popup
              />
            </template>
          </Column>
        </DataTable>

        <PaginatorCaption
          :page="filters.page"
          :per-page="filters.per_page"
          :total="totalRecords"
        />
      </div>
    </div>

    <!-- Edit deadline dialog -->
    <EditDeadlineDialog
      v-model:visible="deadlineDialogVisible"
      :assignment="editingAssignment"
      @save="saveDeadline"
    />

    <!-- Assign drawer — global mode (no courseId: shows course picker first) -->
    <AssignCourseDrawer
      v-model:visible="showAssignDrawer"
      @assigned="onAssigned"
    />
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Menu from 'primevue/menu'
import ProgressBar from 'primevue/progressbar'
import ListToolbar from '@/components/shared/ListToolbar.vue'
import PaginatorCaption from '@/components/shared/PaginatorCaption.vue'
import AssignmentStatusTag from '@/components/shared/AssignmentStatusTag.vue'
import AssignmentsFilterPanel from './components/AssignmentsFilterPanel.vue'
import EditDeadlineDialog from './components/EditDeadlineDialog.vue'
import AssignCourseDrawer from '@/pages/CourseBuilderPage/components/AssignCourseDrawer.vue'
import { useAssignmentsPage } from './composables/useAssignmentsPage'
import type { CourseAssignment } from '@/entities/assignment'

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

// ─── Row kebab menus ─────────────────────────────────────────────────────────
const rowMenuRefs = ref<Map<number, InstanceType<typeof Menu>>>(new Map())

function setRowMenuRef(id: number, el: unknown): void {
  if (el) rowMenuRefs.value.set(id, el as InstanceType<typeof Menu>)
}

function toggleRowMenu(event: Event, id: number): void {
  rowMenuRefs.value.get(id)?.toggle(event)
}

function getRowMenuItems(a: CourseAssignment): import('primevue/menuitem').MenuItem[] {
  const items: import('primevue/menuitem').MenuItem[] = [
    {
      label: t('onboarding.assignments.editDeadline'),
      icon: 'pi pi-calendar',
      command: () => openEditDeadline(a),
    },
    {
      label: t('onboarding.assignments.archive'),
      icon: 'pi pi-box',
      disabled: a.status === 'archived',
      command: () => archiveAssignment(a),
    },
  ]
  if (a.progress_pct === 0) {
    items.push({
      label: t('onboarding.assignments.delete'),
      icon: 'pi pi-trash',
      class: 'p-menuitem-danger',
      command: () => deleteAssignmentConfirm(a),
    })
  }
  return items
}

// Drawer emits 'assigned' after successful bulk-assign (toasts handled inside drawer).
// Here we just refresh the list.
function onAssigned(): void {
  void loadAssignments()
}

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
  display: flex;
  flex-direction: column;
  height: 100%;
}

.assignments-page__body {
  padding: $space-4 $space-6;
  flex: 1;
  display: flex;
  flex-direction: column;
  min-height: 0;
}

.assignments-page__card {
  background: $surface-card;
  border-radius: $radius-lg;
  border: 1px solid $surface-200;
  box-shadow: $shadow-card;
  overflow: hidden;
  flex: 1;
  display: flex;
  flex-direction: column;
}

.assignments-page__table {
  flex: 1;

  :deep(.p-datatable-thead > tr > th) {
    text-transform: uppercase;
    font-size: $font-size-2xs;
    letter-spacing: 0.03em;
    color: var(--p-text-muted-color);
  }
}

.assignments-page__link {
  color: var(--p-primary-color);
  text-decoration: none;
  font-weight: $font-weight-medium;

  &:hover {
    text-decoration: underline;
  }
}

.assignments-page__progress {
  gap: $space-2;
}

// Theme-reactive muted placeholder — replaces dead full-Bootstrap .text-muted.
.assignments-page__muted {
  color: var(--p-text-muted-color);
}

// .text-danger is a dead full-Bootstrap class → overdue tint via token here.
.assignments-page__deadline--overdue {
  color: var(--p-red-500);
}

.assignments-page__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-3;
  padding: $space-8;
  text-align: center;
}

.assignments-page__empty-icon {
  font-size: $font-size-icon-xl;
  color: var(--p-surface-400);
}

.assignments-page__empty-text {
  font-size: $font-size-md;
  font-weight: $font-weight-semibold;
  color: var(--p-surface-700);
  margin: 0;
}

.assignments-page__empty-hint {
  font-size: $font-size-sm;
  color: var(--p-surface-500);
  margin: 0;
}

.assignments-page__progress-pct {
  font-size: $font-size-2xs; // snap from 0.8rem (12.8px)
  white-space: nowrap;
}
</style>
