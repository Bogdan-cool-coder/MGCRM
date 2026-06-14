<template>
  <div class="admin-courses-page">
    <PageHeader :title="t('onboarding.courses.title')" icon="pi pi-graduation-cap">
      <template #actions>
        <Button
          :label="t('onboarding.courses.create')"
          icon="pi pi-plus"
          @click="showCreateDialog = true"
        />
      </template>
    </PageHeader>

    <!-- Filters -->
    <CoursesFilterPanel
      :filters="filters"
      @change="applyFilters"
      @reset="resetFilters"
    />

    <!-- DataTable -->
    <DataTable
      :value="courses"
      :loading="loading"
      row-hover
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
          <i class="pi pi-graduation-cap admin-courses-page__empty-icon" />
          <p class="admin-courses-page__empty-text">
            {{ filters.search || filters.status || filters.completion_policy
              ? t('onboarding.courses.emptyFiltered')
              : t('onboarding.courses.empty') }}
          </p>
          <p v-if="!filters.search && !filters.status && !filters.completion_policy" class="admin-courses-page__empty-hint">
            {{ t('onboarding.courses.emptyCreate') }}
          </p>
          <Button
            v-if="!filters.search && !filters.status && !filters.completion_policy"
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
        style="width: 90px"
        class="text-center"
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
        style="width: 90px"
        class="text-center"
      >
        <template #body="{ data: course }">
          {{ course.passing_score_pct }}%
        </template>
      </Column>

      <Column
        :header="t('onboarding.courses.columns.deadline')"
        style="width: 80px"
        class="text-center"
      >
        <template #body="{ data: course }">
          <span v-if="course.deadline_days">{{ course.deadline_days }}</span>
          <span v-else class="text-muted">—</span>
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

      <Column style="width: 120px">
        <template #body="{ data: course }">
          <div class="d-flex gap-1">
            <Button
              icon="pi pi-pencil"
              size="small"
              text
              :title="t('common.edit')"
              @click="$router.push({ name: 'CourseBuilder', params: { id: course.id } })"
            />
            <Button
              v-if="!course.is_published"
              icon="pi pi-send"
              size="small"
              text
              severity="success"
              :title="t('onboarding.courses.publish')"
              @click="onPublish(course)"
            />
            <Button
              v-if="course.is_published"
              icon="pi pi-eye-slash"
              size="small"
              text
              severity="warn"
              :title="t('onboarding.courses.unpublish')"
              @click="onUnpublish(course)"
            />
            <Button
              v-if="!course.is_published"
              icon="pi pi-trash"
              size="small"
              text
              severity="danger"
              :title="t('common.delete')"
              @click="onDelete(course)"
            />
          </div>
        </template>
      </Column>
    </DataTable>

    <!-- Create dialog -->
    <CreateCourseDialog
      v-model:visible="showCreateDialog"
      @create="onCreate"
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
import ConfirmDialog from 'primevue/confirmdialog'
import PageHeader from '@/components/AppShell/PageHeader.vue'
import CourseStatusTag from '@/components/shared/CourseStatusTag.vue'
import CoursesFilterPanel from './components/CoursesFilterPanel.vue'
import CreateCourseDialog from './components/CreateCourseDialog.vue'
import { useAdminCoursesPage } from './composables/useAdminCoursesPage'

const { t } = useI18n()

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

onMounted(() => {
  void loadCourses()
})
</script>

<style lang="scss" scoped>
.admin-courses-page {
  &__table {
    :deep(.p-datatable-tbody > tr) {
      cursor: default;
    }
  }

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
    padding: 2.5rem;
    text-align: center;
  }

  &__empty-icon {
    font-size: 3rem;
    color: var(--p-surface-400);
  }

  &__empty-text {
    font-size: $font-size-md;
    font-weight: $font-weight-medium;
    color: var(--p-surface-700);
    margin: 0;
  }

  &__empty-hint {
    font-size: $font-size-sm;
    color: var(--p-surface-500);
    margin: 0;
  }
}
</style>
