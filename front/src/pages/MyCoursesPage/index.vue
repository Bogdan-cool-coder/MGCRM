<template>
  <div class="my-courses-page">
    <ListToolbar
      icon="pi-book"
      :title="t('onboarding.myCourses.title')"
      :subtitle="t('onboarding.myCourses.subtitle', { count: allCount })"
    >
      <template #segmented>
        <SegmentedControl
          v-model="activeTab"
          :options="tabOptions"
          :aria-label="t('onboarding.myCourses.tabsAria')"
        />
      </template>
    </ListToolbar>

    <div class="my-courses-page__body">
      <!-- Loading skeletons -->
      <div v-if="loading" class="row g-3">
        <div v-for="n in 6" :key="n" class="col-md-6 col-lg-4">
          <div class="my-courses-page__skeleton-card">
            <Skeleton height="160px" class="d-block" />
            <div class="p-3">
              <Skeleton width="80%" height="20px" class="mb-2" />
              <Skeleton width="50%" height="16px" class="mb-3" />
              <Skeleton height="6px" class="mb-1" />
              <Skeleton width="40%" height="14px" />
            </div>
          </div>
        </div>
      </div>

      <!-- Error -->
      <Message v-else-if="error" severity="error" :closable="false">
        {{ t('common.loadError') }}
      </Message>

      <!-- Empty — no assignments at all -->
      <div v-else-if="allCount === 0" class="my-courses-page__empty">
        <i class="pi pi-book my-courses-page__empty-icon" />
        <p class="mt-3">{{ t('onboarding.myCourses.empty') }}</p>
      </div>

      <!-- Empty by tab -->
      <div v-else-if="filteredAssignments.length === 0" class="my-courses-page__empty">
        <i class="pi pi-inbox my-courses-page__empty-icon" />
        <p class="mt-2">{{ t('onboarding.myCourses.emptyTab') }}</p>
      </div>

      <!-- Cards grid -->
      <div v-else class="row g-3">
        <div
          v-for="assignment in filteredAssignments"
          :key="assignment.id"
          class="col-md-6 col-lg-4"
        >
          <MyCourseCard :assignment="assignment" />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import Skeleton from 'primevue/skeleton'
import Message from 'primevue/message'
import ListToolbar from '@/components/shared/ListToolbar.vue'
import SegmentedControl, { type SegmentedOption } from '@/components/shared/SegmentedControl.vue'
import MyCourseCard from './components/MyCourseCard.vue'
import { useMyCoursesPage, type TabKey } from './composables/useMyCoursesPage'

const { t } = useI18n()

const {
  loading,
  error,
  activeTab,
  filteredAssignments,
  activeCount,
  completedCount,
  overdueCount,
  allCount,
  load,
} = useMyCoursesPage()

const tabOptions = computed<SegmentedOption<TabKey>[]>(() => [
  { value: 'active', label: t('onboarding.myCourses.tabs.active'), count: activeCount.value },
  { value: 'completed', label: t('onboarding.myCourses.tabs.completed'), count: completedCount.value },
  { value: 'overdue', label: t('onboarding.myCourses.tabs.overdue'), count: overdueCount.value, severity: 'danger' },
])

onMounted(async () => {
  await load()
})
</script>

<style lang="scss" scoped>
.my-courses-page {
  // .p-4 is a full-Bootstrap padding util absent from the grid-only bundle → scoped.
  &__body {
    padding: $space-4;
  }

  // Card scaffold — full-Bootstrap .card/.overflow-hidden are absent from the
  // grid-only bundle, so the loading tile rendered with no surface.
  &__skeleton-card {
    background: $surface-card;
    border: 1px solid var(--p-surface-200);
    border-radius: $radius-lg;
    box-shadow: var(--app-shadow-card);
    overflow: hidden;
  }

  &__empty {
    // .text-center / py-6 are dead full-Bootstrap classes → center + spacing here.
    text-align: center;
    padding-block: $space-8;

    &-icon {
      font-size: $font-size-icon-2xl;
      color: var(--p-surface-400);
    }

    p {
      color: var(--p-surface-500);
    }
  }
}
</style>
