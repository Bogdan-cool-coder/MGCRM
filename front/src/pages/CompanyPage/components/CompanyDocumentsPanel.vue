<template>
  <InfoPanel
    :title="t('company.page.tabs.documents')"
    icon="pi-file"
    panel-key="company-documents-overview"
    :count="documents.length"
    :default-collapsed="false"
  >
    <!-- Loading -->
    <div v-if="loading" class="company-docs-panel__skeleton">
      <Skeleton height="36px" class="mb-2" />
      <Skeleton height="36px" class="mb-2" />
      <Skeleton height="36px" />
    </div>

    <!-- Empty -->
    <div v-else-if="documents.length === 0" class="company-docs-panel__empty">
      <i class="pi pi-file-edit company-docs-panel__empty-icon" />
      <p class="company-docs-panel__empty-text">{{ t('documents.list.empty') }}</p>
    </div>

    <!-- Last 5 docs list -->
    <template v-else>
      <div v-for="doc in lastFive" :key="doc.id" class="company-docs-panel__row">
        <div class="company-docs-panel__row-main">
          <RouterLink
            :to="`/documents/${doc.id}`"
            class="company-docs-panel__doc-name"
          >
            {{ doc.title || doc.number || `#draft-${doc.id}` }}
          </RouterLink>
          <DocumentStatusTag :status="doc.status" class="company-docs-panel__status-tag" />
        </div>
        <span class="company-docs-panel__doc-date">{{ formatDate(doc.created_at) }}</span>
      </div>

      <!-- See all link -->
      <button
        v-if="documents.length > 5"
        type="button"
        class="company-docs-panel__see-all"
        @click="$emit('goToTab', 'documents')"
      >
        {{ t('company.page.documents.seeAll', { count: documents.length }) }}
        <i class="pi pi-arrow-right" />
      </button>
    </template>
  </InfoPanel>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import Skeleton from 'primevue/skeleton'
import InfoPanel from '@/components/crm/entity/InfoPanel.vue'
import DocumentStatusTag from '@/components/shared/DocumentStatusTag.vue'
import type { DocumentListItemDto } from '@/entities/document'

const props = defineProps<{
  documents: DocumentListItemDto[]
  loading: boolean
}>()

defineEmits<{
  goToTab: [tab: string]
}>()

const { t } = useI18n()

const lastFive = computed(() => props.documents.slice(0, 5))

function formatDate(iso: string): string {
  const d = new Date(iso)
  return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' })
}
</script>

<style lang="scss" scoped>
.company-docs-panel__skeleton {
  display: flex;
  flex-direction: column;
  padding: 0 0 $space-3;
}

.company-docs-panel__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-2;
  padding: $space-6 $space-4;
  text-align: center;
}

.company-docs-panel__empty-icon {
  font-size: $font-size-icon-lg;
  color: $surface-300;
}

.company-docs-panel__empty-text {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0;
}

.company-docs-panel__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $space-2;
  padding: $space-2 0;
  border-bottom: 1px solid var(--p-surface-100);

  .app-dark & {
    border-bottom-color: var(--p-surface-800);
  }

  &:last-of-type {
    border-bottom: none;
  }
}

.company-docs-panel__row-main {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex: 1;
  min-width: 0;
}

.company-docs-panel__doc-name {
  font-size: $font-size-sm;
  color: var(--p-primary-color);
  text-decoration: none;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  flex: 1;
  min-width: 0;

  &:hover {
    text-decoration: underline;
  }
}

.company-docs-panel__status-tag {
  flex-shrink: 0;
}

.company-docs-panel__doc-date {
  font-size: $font-size-xs;
  color: $surface-400;
  flex-shrink: 0;
}

.company-docs-panel__see-all {
  display: flex;
  align-items: center;
  gap: $space-1;
  background: transparent;
  border: none;
  cursor: pointer;
  padding: $space-2 0 0;
  font-size: $font-size-xs;
  color: var(--p-primary-color);
  font-weight: $font-weight-medium;

  i {
    font-size: $font-size-3xs; // snap from 10px
  }
}
</style>
