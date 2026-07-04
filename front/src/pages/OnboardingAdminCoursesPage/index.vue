<template>
  <div class="admin-courses-page">
    <ListToolbar
      icon="pi-graduation-cap"
      :title="t('onboarding.courses.title')"
      :subtitle="t('onboarding.courses.subtitle', { count: totalRecords })"
    >
      <template #filter>
        <FilterTriggerButton
          :label="t('onboarding.courses.openFilters')"
          :count="activeFilterCount"
          @click="toggleFilterPanel"
        />
        <Popover ref="filterPanelRef">
          <CoursesFilterPanel
            :filters="filters"
            @change="applyFilters"
            @reset="resetFilters"
          />
        </Popover>
      </template>
      <template #actions>
        <Button
          :label="t('onboarding.courses.create')"
          icon="pi pi-plus"
          @click="showCreateDialog = true"
        />
      </template>
    </ListToolbar>

    <div class="admin-courses-page__body">
      <!-- Table card -->
      <div class="admin-courses-page__card">
        <DataTable
          :value="courses"
          :loading="loading"
          row-hover
          striped-rows
          :rows="filters.per_page"
          :total-records="totalRecords"
          lazy
          paginator
          :rows-per-page-options="[25, 50, 100]"
          class="admin-courses-page__table"
          @page="onPage"
        >
          <!-- Empty state -->
          <template #empty>
            <div class="admin-courses-page__empty">
              <i
                :class="isFiltered ? 'pi pi-filter-slash' : 'pi pi-graduation-cap'"
                class="admin-courses-page__empty-icon"
              />
              <p class="admin-courses-page__empty-text">
                {{ isFiltered
                  ? t('onboarding.courses.emptyFiltered')
                  : t('onboarding.courses.empty') }}
              </p>
              <p v-if="!isFiltered" class="admin-courses-page__empty-hint">
                {{ t('onboarding.courses.emptyCreate') }}
              </p>
              <Button
                v-if="!isFiltered"
                :label="t('onboarding.courses.create')"
                icon="pi pi-plus"
                size="small"
                @click="showCreateDialog = true"
              />
              <Button
                v-else
                :label="t('onboarding.courses.filter.reset')"
                severity="secondary"
                icon="pi pi-filter-slash"
                size="small"
                @click="resetFilters"
              />
            </div>
          </template>

          <!-- Columns -->
          <Column :header="t('onboarding.courses.columns.title')">
            <template #body="{ data: course }">
              <router-link
                :to="{ name: 'CourseBuilder', params: { id: course.id } }"
                class="admin-courses-page__link"
              >
                {{ course.title }}
              </router-link>
            </template>
          </Column>

          <Column
            :header="t('onboarding.courses.columns.modules')"
            style="width: 90px; text-align: center"
          >
            <template #body="{ data: course }">
              {{ course.modules_count }}
            </template>
          </Column>

          <Column
            :header="t('onboarding.courses.columns.policy')"
            style="width: 140px"
          >
            <template #body="{ data: course }">
              {{ policyLabel(course.completion_policy) }}
            </template>
          </Column>

          <Column
            :header="t('onboarding.courses.columns.passingScore')"
            style="width: 90px; text-align: center"
          >
            <template #body="{ data: course }">
              {{ course.passing_score_pct }}%
            </template>
          </Column>

          <Column
            :header="t('onboarding.courses.columns.deadline')"
            style="width: 80px; text-align: center"
          >
            <template #body="{ data: course }">
              <span v-if="course.deadline_days">{{ course.deadline_days }}</span>
              <span v-else class="admin-courses-page__muted">—</span>
            </template>
          </Column>

          <Column
            :header="t('onboarding.courses.columns.status')"
            style="width: 150px"
          >
            <template #body="{ data: course }">
              <CourseStatusTag :is-published="course.is_published" />
            </template>
          </Column>

          <!-- Actions (kebab) -->
          <Column style="width: 60px">
            <template #body="{ data: course }">
              <Button
                icon="pi pi-ellipsis-v"
                size="small"
                text
                severity="secondary"
                :aria-label="t('common.actions')"
                @click="toggleRowMenu($event, course.id)"
              />
              <Menu
                :ref="(el) => setRowMenuRef(course.id, el)"
                :model="getRowMenuItems(course)"
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

    <!-- Create dialog -->
    <CreateCourseDialog
      v-model:visible="showCreateDialog"
      @create="onCreate"
    />
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Menu from 'primevue/menu'
import Popover from 'primevue/popover'
import ListToolbar from '@/components/shared/ListToolbar.vue'
import FilterTriggerButton from '@/components/shared/FilterTriggerButton.vue'
import PaginatorCaption from '@/components/shared/PaginatorCaption.vue'
import CourseStatusTag from '@/components/shared/CourseStatusTag.vue'
import CoursesFilterPanel from './components/CoursesFilterPanel.vue'
import CreateCourseDialog from './components/CreateCourseDialog.vue'
import { useAdminCoursesPage } from './composables/useAdminCoursesPage'
import type { Course } from '@/entities/course'

const { t } = useI18n()
const router = useRouter()

const {
  courses,
  loading,
  totalRecords,
  filters,
  showCreateDialog,
  loadCourses,
  onPage,
  applyFilters,
  resetFilters,
  onCreate,
  onPublish,
  onUnpublish,
  onDelete,
  policyLabel,
} = useAdminCoursesPage()

// ─── Filter panel (пattern Б) ────────────────────────────────────────────────
const filterPanelRef = ref<InstanceType<typeof Popover> | null>(null)

function toggleFilterPanel(event: Event): void {
  filterPanelRef.value?.toggle(event)
}

const activeFilterCount = computed(() => {
  let n = 0
  if (filters.status) n++
  if (filters.completion_policy) n++
  if (filters.search) n++
  return n
})

const isFiltered = computed(() => activeFilterCount.value > 0)

// ─── Row kebab menus ─────────────────────────────────────────────────────────
const rowMenuRefs = ref<Map<number, InstanceType<typeof Menu>>>(new Map())

function setRowMenuRef(id: number, el: unknown): void {
  if (el) rowMenuRefs.value.set(id, el as InstanceType<typeof Menu>)
}

function toggleRowMenu(event: Event, id: number): void {
  rowMenuRefs.value.get(id)?.toggle(event)
}

function getRowMenuItems(course: Course): import('primevue/menuitem').MenuItem[] {
  const items: import('primevue/menuitem').MenuItem[] = [
    {
      label: t('onboarding.courses.menu.edit'),
      icon: 'pi pi-pencil',
      command: () => { void router.push({ name: 'CourseBuilder', params: { id: course.id } }) },
    },
  ]
  if (!course.is_published) {
    items.push({
      label: t('onboarding.courses.menu.publish'),
      icon: 'pi pi-send',
      command: () => { void onPublish(course) },
    })
  } else {
    items.push({
      label: t('onboarding.courses.menu.unpublish'),
      icon: 'pi pi-eye-slash',
      command: () => onUnpublish(course),
    })
  }
  if (!course.is_published) {
    items.push({
      label: t('onboarding.courses.menu.delete'),
      icon: 'pi pi-trash',
      class: 'p-menuitem-danger',
      command: () => onDelete(course),
    })
  }
  return items
}

onMounted(() => {
  void loadCourses()
})
</script>

<style lang="scss" scoped>
.admin-courses-page {
  display: flex;
  flex-direction: column;
  height: 100%;
}

.admin-courses-page__body {
  padding: $space-4 $space-6;
  flex: 1;
  display: flex;
  flex-direction: column;
  min-height: 0;
}

.admin-courses-page__card {
  background: $surface-card;
  border-radius: $radius-lg;
  border: 1px solid $surface-200;
  box-shadow: $shadow-card;
  overflow: hidden;
  flex: 1;
  display: flex;
  flex-direction: column;
}

.admin-courses-page__table {
  flex: 1;

  :deep(.p-datatable-thead > tr > th) {
    text-transform: uppercase;
    font-size: $font-size-2xs;
    letter-spacing: 0.03em;
    color: var(--p-text-muted-color);
  }
}

.admin-courses-page__link {
  color: var(--p-primary-color);
  text-decoration: none;
  font-weight: $font-weight-medium;

  &:hover {
    text-decoration: underline;
  }
}

// Theme-reactive muted placeholder — replaces dead full-Bootstrap .text-muted.
.admin-courses-page__muted {
  color: var(--p-text-muted-color);
}

.admin-courses-page__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-3;
  padding: $space-8;
  text-align: center;
}

.admin-courses-page__empty-icon {
  font-size: $font-size-icon-xl;
  color: var(--p-surface-400);
}

.admin-courses-page__empty-text {
  font-size: $font-size-md;
  font-weight: $font-weight-semibold;
  color: var(--p-surface-700);
  margin: 0;
}

.admin-courses-page__empty-hint {
  font-size: $font-size-sm;
  color: var(--p-surface-500);
  margin: 0;
}
</style>
