<template>
  <div class="my-courses-page">
    <PageHeader :title="t('onboarding.myCourses.title')" icon="pi pi-book" />

    <div class="p-4">
      <Tabs v-model:value="activeTab" class="mb-4">
        <TabList>
          <Tab value="active">
            {{ t('onboarding.myCourses.tabs.active') }}
            <Badge v-if="activeCount > 0" :value="activeCount" class="ms-2" />
          </Tab>
          <Tab value="completed">
            {{ t('onboarding.myCourses.tabs.completed') }}
            <Badge v-if="completedCount > 0" :value="completedCount" class="ms-2" />
          </Tab>
          <Tab value="overdue">
            {{ t('onboarding.myCourses.tabs.overdue') }}
            <Badge v-if="overdueCount > 0" :value="overdueCount" severity="danger" class="ms-2" />
          </Tab>
        </TabList>
      </Tabs>

      <!-- Loading skeletons -->
      <div v-if="loading" class="row g-3">
        <div v-for="n in 6" :key="n" class="col-md-6 col-lg-4">
          <div class="card p-0 overflow-hidden">
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
      <div v-else-if="allCount === 0" class="my-courses-page__empty text-center py-6">
        <i class="pi pi-book my-courses-page__empty-icon" />
        <p class="mt-3">{{ t('onboarding.myCourses.empty') }}</p>
      </div>

      <!-- Empty by tab -->
      <div v-else-if="filteredAssignments.length === 0" class="my-courses-page__empty text-center py-5">
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
import { onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import Badge from 'primevue/badge'
import Skeleton from 'primevue/skeleton'
import Message from 'primevue/message'
import PageHeader from '@/components/AppShell/PageHeader.vue'
import MyCourseCard from './components/MyCourseCard.vue'
import { useMyCoursesPage } from './composables/useMyCoursesPage'

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

onMounted(async () => {
  await load()
})
</script>

<style lang="scss" scoped>
.my-courses-page {
  &__empty {
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
