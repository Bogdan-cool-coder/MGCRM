<template>
  <div class="cabinet-header">
    <!-- Loading skeleton -->
    <template v-if="loading">
      <div class="d-flex align-items-start gap-3">
        <Skeleton shape="circle" size="64px" class="flex-shrink-0" />
        <div class="d-flex flex-column gap-2 flex-grow-1">
          <Skeleton width="200px" height="1.25rem" />
          <Skeleton width="140px" height="1rem" />
          <Skeleton width="160px" height="1rem" />
          <Skeleton width="120px" height="1rem" />
        </div>
      </div>
    </template>

    <!-- Empty state -->
    <template v-else-if="!profile">
      <div class="cabinet-header__empty">
        <i class="pi pi-id-card cabinet-header__empty-icon" />
        <p class="cabinet-header__empty-text">{{ t('managerCabinet.profile.unavailable') }}</p>
      </div>
    </template>

    <!-- Content -->
    <template v-else>
      <div class="d-flex align-items-start gap-3">
        <!-- Avatar -->
        <Avatar
          :image="profile.avatar_path ?? undefined"
          icon="pi pi-user"
          size="large"
          shape="circle"
          class="cabinet-header__avatar flex-shrink-0"
        />

        <!-- Info -->
        <div class="d-flex flex-column gap-1 flex-grow-1 min-width-0">
          <div class="cabinet-header__name">{{ profile.full_name }}</div>
          <div v-if="profile.job_title" class="cabinet-header__job-title">
            {{ profile.job_title }}
          </div>

          <!-- Meta rows -->
          <div class="d-flex flex-wrap gap-3 mt-1">
            <div class="cabinet-header__meta-row">
              <i class="pi pi-building cabinet-header__meta-icon" />
              <span class="cabinet-header__meta-text">
                {{ profile.department_name ?? t('managerCabinet.profile.noDepartment') }}
              </span>
            </div>

            <div class="cabinet-header__meta-row">
              <i class="pi pi-user cabinet-header__meta-icon" />
              <span class="cabinet-header__meta-text">
                {{ profile.manager_name ?? t('managerCabinet.profile.noManager') }}
              </span>
            </div>

            <div class="cabinet-header__meta-row">
              <i class="pi pi-users cabinet-header__meta-icon" />
              <span class="cabinet-header__meta-text">
                {{
                  profile.subordinates_count > 0
                    ? t('managerCabinet.profile.subordinates', { n: profile.subordinates_count })
                    : t('managerCabinet.profile.noSubordinates')
                }}
              </span>
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Avatar from 'primevue/avatar'
import Skeleton from 'primevue/skeleton'
import type { MeProfile } from '@/entities/managerCabinet'

defineProps<{
  profile: MeProfile | null
  loading: boolean
}>()

const { t } = useI18n()
</script>

<style lang="scss" scoped>
.cabinet-header {
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  padding: $space-5;
}

.cabinet-header__avatar {
  width: 64px;
  height: 64px;
}

.cabinet-header__name {
  font-size: var(--app-font-size-lg, 1.125rem);
  font-weight: $font-weight-bold;
  color: $surface-900;
  line-height: $line-height-tight;
}

.cabinet-header__job-title {
  font-size: $font-size-sm;
  color: $surface-600;
  line-height: $line-height-normal;
}

.cabinet-header__meta-row {
  display: flex;
  align-items: center;
  gap: $space-1;
}

.cabinet-header__meta-icon {
  font-size: 14px;
  color: $surface-400;
  flex-shrink: 0;
}

.cabinet-header__meta-text {
  font-size: $font-size-sm;
  color: $surface-600;
}

.cabinet-header__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: $space-6;
  gap: $space-3;
  min-height: 80px;
}

.cabinet-header__empty-icon {
  font-size: 2rem;
  color: $surface-400;
}

.cabinet-header__empty-text {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0;
}
</style>
