<template>
  <div class="contacts-kpi-bar">
    <!-- Loading skeleton -->
    <template v-if="loading">
      <Skeleton
        v-for="i in (entityType === 'company' ? 6 : 4)"
        :key="i"
        class="contacts-kpi-bar__skeleton"
      />
    </template>

    <!-- Company chips -->
    <template v-else-if="entityType === 'company'">
      <div class="contacts-kpi-bar__chip contacts-kpi-bar__chip--brand">
        <i class="pi pi-building contacts-kpi-bar__chip-icon" />
        <span class="contacts-kpi-bar__chip-label">{{ t('contacts.kpi.total') }}:</span>
        <strong class="contacts-kpi-bar__chip-value">{{ stats.total }}</strong>
      </div>
      <div class="contacts-kpi-bar__chip contacts-kpi-bar__chip--success">
        <i class="pi pi-check-circle contacts-kpi-bar__chip-icon" />
        <span class="contacts-kpi-bar__chip-label">{{ t('contacts.kpi.clients') }}:</span>
        <strong class="contacts-kpi-bar__chip-value">{{ stats.clients ?? 0 }}</strong>
      </div>
      <div class="contacts-kpi-bar__chip contacts-kpi-bar__chip--danger">
        <i class="pi pi-star-fill contacts-kpi-bar__chip-icon" />
        <span class="contacts-kpi-bar__chip-label">{{ t('contacts.kpi.catL') }}:</span>
        <strong class="contacts-kpi-bar__chip-value">{{ stats.cat_l ?? 0 }}</strong>
      </div>
      <div class="contacts-kpi-bar__chip contacts-kpi-bar__chip--amber">
        <i class="pi pi-star contacts-kpi-bar__chip-icon" />
        <span class="contacts-kpi-bar__chip-label">{{ t('contacts.kpi.catM') }}:</span>
        <strong class="contacts-kpi-bar__chip-value">{{ stats.cat_m ?? 0 }}</strong>
      </div>
      <div class="contacts-kpi-bar__chip contacts-kpi-bar__chip--success">
        <i class="pi pi-minus-circle contacts-kpi-bar__chip-icon" />
        <span class="contacts-kpi-bar__chip-label">{{ t('contacts.kpi.catS') }}:</span>
        <strong class="contacts-kpi-bar__chip-value">{{ stats.cat_s ?? 0 }}</strong>
      </div>
      <div class="contacts-kpi-bar__chip contacts-kpi-bar__chip--teal">
        <i class="pi pi-calendar-plus contacts-kpi-bar__chip-icon" />
        <span class="contacts-kpi-bar__chip-label">{{ t('contacts.kpi.newWeek') }}:</span>
        <strong class="contacts-kpi-bar__chip-value">{{ stats.new_week ?? 0 }}</strong>
      </div>
    </template>

    <!-- Contact chips -->
    <template v-else>
      <div class="contacts-kpi-bar__chip contacts-kpi-bar__chip--brand">
        <i class="pi pi-users contacts-kpi-bar__chip-icon" />
        <span class="contacts-kpi-bar__chip-label">{{ t('contacts.kpi.total') }}:</span>
        <strong class="contacts-kpi-bar__chip-value">{{ stats.total }}</strong>
      </div>
      <div class="contacts-kpi-bar__chip contacts-kpi-bar__chip--success">
        <i class="pi pi-check-circle contacts-kpi-bar__chip-icon" />
        <span class="contacts-kpi-bar__chip-label">{{ t('contacts.kpi.active') }}:</span>
        <strong class="contacts-kpi-bar__chip-value">{{ stats.active ?? 0 }}</strong>
      </div>
      <div class="contacts-kpi-bar__chip contacts-kpi-bar__chip--info">
        <i class="pi pi-clock contacts-kpi-bar__chip-icon" />
        <span class="contacts-kpi-bar__chip-label">{{ t('contacts.kpi.noTouch') }}:</span>
        <strong class="contacts-kpi-bar__chip-value">{{ stats.no_touch_30 ?? 0 }}</strong>
      </div>
      <div class="contacts-kpi-bar__chip contacts-kpi-bar__chip--teal">
        <i class="pi pi-calendar-plus contacts-kpi-bar__chip-icon" />
        <span class="contacts-kpi-bar__chip-label">{{ t('contacts.kpi.newWeek') }}:</span>
        <strong class="contacts-kpi-bar__chip-value">{{ stats.new_week ?? 0 }}</strong>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Skeleton from 'primevue/skeleton'
import type { EntityType } from '../composables/useContactsPageData'

export interface KpiStats {
  total: number
  // companies
  clients?: number
  cat_l?: number
  cat_m?: number
  cat_s?: number
  // contacts
  active?: number
  no_touch_30?: number
  // shared
  new_week?: number
}

defineProps<{
  entityType: EntityType
  stats: KpiStats
  loading?: boolean
}>()

const { t } = useI18n()
</script>

<style lang="scss" scoped>
.contacts-kpi-bar {
  display: flex;
  flex-wrap: wrap;
  gap: $space-2;
  padding: 13px $space-5;
  border-bottom: 1px solid $surface-200;
  background: $surface-50;
  flex-shrink: 0;

  .app-dark & {
    background: var(--p-surface-100);
    border-bottom-color: var(--p-surface-700);
  }
}

.contacts-kpi-bar__skeleton {
  border-radius: $radius-pill;
  width: 100px;
  height: 32px;
}

.contacts-kpi-bar__chip {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  border-radius: $radius-pill;
  padding: 6px 13px;
  font-size: $font-size-xs;
  white-space: nowrap;
}

.contacts-kpi-bar__chip-icon {
  font-size: $font-size-2xs;
}

.contacts-kpi-bar__chip-label {
  opacity: 0.8;
}

.contacts-kpi-bar__chip-value {
  font-weight: $font-weight-bold;
}

// Accent variants
.contacts-kpi-bar__chip--brand {
  background: $primary-100;
  color: $primary-900;
}

.contacts-kpi-bar__chip--success {
  background: $green-100;
  color: $green-900;
}

.contacts-kpi-bar__chip--danger {
  background: $red-50;
  color: $red-700;
}

.contacts-kpi-bar__chip--amber {
  background: $orange-100;
  color: $orange-900;
}

.contacts-kpi-bar__chip--info {
  background: $blue-100;
  color: $blue-900;
}

.contacts-kpi-bar__chip--teal {
  background: $teal-100;
  color: $teal-700;

  .app-dark & {
    background: $teal-900;
    color: $teal-100;
  }
}
</style>
