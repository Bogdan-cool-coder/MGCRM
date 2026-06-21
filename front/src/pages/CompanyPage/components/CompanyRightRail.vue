<template>
  <div class="company-rail">
    <!-- Responsible -->
    <div v-if="company.responsible_user" class="company-rail__section">
      <div class="company-rail__section-title">{{ t('company.page.rail.responsible') }}</div>
      <div class="company-rail__user">
        <div class="company-rail__avatar">
          <i class="pi pi-user" />
        </div>
        <span class="company-rail__user-name">{{ company.responsible_user.full_name }}</span>
      </div>
    </div>

    <!-- Owner -->
    <div v-if="company.owner_user" class="company-rail__section">
      <div class="company-rail__section-title">{{ t('company.page.rail.owner') }}</div>
      <div class="company-rail__user">
        <div class="company-rail__avatar">
          <i class="pi pi-user" />
        </div>
        <span class="company-rail__user-name">{{ company.owner_user.full_name }}</span>
      </div>
    </div>

    <!-- Source -->
    <div v-if="company.source" class="company-rail__section">
      <div class="company-rail__section-title">{{ t('company.page.rail.source') }}</div>
      <Tag :value="directoriesStore.getSourceLabel(company.source)" severity="secondary" size="small" />
    </div>

    <!-- Tags -->
    <div class="company-rail__section">
      <div class="company-rail__section-title">{{ t('company.page.rail.tags') }}</div>
      <div class="company-rail__tags">
        <Tag
          v-for="tag in company.tags"
          :key="tag"
          :value="tag"
          severity="secondary"
          size="small"
        />
        <span v-if="!company.tags.length" class="company-rail__empty-text">—</span>
      </div>
    </div>

    <!-- Dates -->
    <div class="company-rail__section">
      <div class="company-rail__section-title">{{ t('company.page.rail.createdAt') }}</div>
      <div class="company-rail__date">{{ formatDate(company.created_at) }}</div>
    </div>
    <div class="company-rail__section">
      <div class="company-rail__section-title">{{ t('company.page.rail.updatedAt') }}</div>
      <div class="company-rail__date">{{ formatDate(company.updated_at) }}</div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Tag from 'primevue/tag'
import { useDirectoriesStore } from '@/stores/directories'
import type { Company } from '@/entities/crm'

defineProps<{
  company: Company
}>()

const { t } = useI18n()
const directoriesStore = useDirectoriesStore()

function formatDate(iso: string | null | undefined): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleString('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}
</script>

<style lang="scss" scoped>
.company-rail {
  display: flex;
  flex-direction: column;
  gap: $space-5;
}

.company-rail__section {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.company-rail__section-title {
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  color: $surface-500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.company-rail__user {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.company-rail__avatar {
  width: 28px;
  height: 28px;
  border-radius: $radius-circle;
  background-color: $surface-100;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;

  i {
    font-size: $font-size-xs;
    color: $surface-500;
  }
}

.company-rail__user-name {
  font-size: $font-size-sm;
  color: $surface-800;
}

.company-rail__tags {
  display: flex;
  flex-wrap: wrap;
  gap: $space-1;
}

.company-rail__empty-text {
  font-size: $font-size-sm;
  color: $surface-400;
}

.company-rail__date {
  font-size: $font-size-sm;
  color: $surface-700;
}
</style>
