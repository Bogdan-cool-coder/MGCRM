<template>
  <div class="dashboards-page">
    <div class="dashboards-card">
      <div class="dashboards-header">
        <div class="dashboards-heading">
          <h1 class="dashboards-title">{{ t('title') }}</h1>
          <Button
            v-if="canManage"
            icon="pi pi-plus"
            rounded
            text
            :loading="isCreating"
            :aria-label="t('newDashboard')"
            :title="t('newDashboard')"
            @click="createDashboard"
          />
        </div>
      </div>

      <div class="dashboards-content">
        <LoadingState v-if="loading" />

        <template v-else-if="hasAny">
          <DashboardSection
            v-if="systemDashboards.length > 0"
            :title="t('sections.system')"
            :items="systemDashboards"
            @open="openDashboard"
          />
          <DashboardSection
            v-if="publishedDashboards.length > 0"
            :title="t('sections.published')"
            :items="publishedDashboards"
            @open="openDashboard"
          />
          <DashboardSection
            v-if="personalDashboards.length > 0"
            :title="t('sections.personal')"
            :items="personalDashboards"
            @open="openDashboard"
          />
        </template>

        <EmptyState v-else :message="t('empty')" />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import Button from 'primevue/button'
import LoadingState from '@/components/states/LoadingState.vue'
import EmptyState from '@/components/states/EmptyState.vue'
import DashboardSection from './components/DashboardSection.vue'
import { useDashboardsPage } from './composables/useDashboardsPage'

const {
  t,
  loading,
  hasAny,
  systemDashboards,
  publishedDashboards,
  personalDashboards,
  canManage,
  isCreating,
  openDashboard,
  createDashboard,
} = useDashboardsPage()
</script>

<style lang="scss" scoped>
.dashboards-page {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
  padding: 0.75rem;

  .dashboards-card {
    background: $surface-0;
    border-radius: $card-border-radius;
    padding: 1rem;
    box-shadow: $shadow-md;
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0;
    overflow: hidden;

    .dashboards-header {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 1rem;
      flex-shrink: 0;

      .dashboards-heading {
        display: flex;
        align-items: center;
        gap: 0.25rem;

        .dashboards-title {
          margin: 0;
          font-size: $font-size-2xl;
          font-weight: $font-weight-semibold;
          color: $surface-900;
        }
      }
    }

    .dashboards-content {
      margin-top: 1rem;
      padding-top: 1rem;
      border-top: 1px solid $surface-200;
      flex: 1;
      min-height: 0;
      overflow: auto;
      display: flex;
      flex-direction: column;
      gap: 1.5rem;
    }
  }
}
</style>
