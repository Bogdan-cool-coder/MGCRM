<template>
  <!-- Гэп-4: pipelines rail (250px) — matches pipeline.html <nav> -->
  <nav class="pipeline-rail" aria-label="Воронки">
    <p class="pipeline-rail__title">{{ t('sales.pipelineEditor.railTitle') }}</p>

    <!-- Loading skeleton -->
    <div v-if="loading" class="pipeline-rail__skeleton">
      <Skeleton height="40px" class="mb-1" border-radius="8px" />
      <Skeleton height="40px" border-radius="8px" width="70%" />
    </div>

    <!-- Empty state -->
    <div v-else-if="pipelines.length === 0" class="pipeline-rail__empty">
      <i class="pi pi-sitemap pipeline-rail__empty-icon" />
      <p class="pipeline-rail__empty-title">{{ t('sales.pipelineEditor.section.pipelines') }}</p>
    </div>

    <!-- List -->
    <div v-else class="pipeline-rail__list">
      <PipelineListItem
        v-for="pipeline in pipelines"
        :key="pipeline.id"
        :pipeline="pipeline"
        :is-active="pipeline.id === selectedPipelineId"
        :saving="savingId === pipeline.id"
        :duplicating="duplicatingId === pipeline.id"
        :highlighted="highlightedId === pipeline.id"
        @select="emit('select', pipeline.id)"
        @rename="(id, name) => emit('rename', id, name)"
        @duplicate="(id) => emit('duplicate', id)"
        @delete="(id) => emit('delete', id)"
      />
    </div>

    <!-- New pipeline (bottom of rail) -->
    <Button
      :label="t('sales.pipelineEditor.createPipeline')"
      icon="pi pi-plus"
      severity="secondary"
      outlined
      size="small"
      class="pipeline-rail__new"
      @click="emit('create')"
    />
  </nav>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import PipelineListItem from './PipelineListItem.vue'
import type { PipelineDto } from '@/entities/sales'

defineProps<{
  pipelines: PipelineDto[]
  selectedPipelineId: number | null
  loading?: boolean
  savingId?: number | null
  duplicatingId?: number | null
  highlightedId?: number | null
}>()

const emit = defineEmits<{
  create: []
  select: [id: number]
  rename: [id: number, name: string]
  duplicate: [id: number]
  delete: [id: number]
}>()

const { t } = useI18n()
</script>

<style lang="scss" scoped>
.pipeline-rail {
  width: 250px;
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  padding: $space-4 $space-3;
  background-color: $surface-card;
  border-inline-end: 1px solid var(--p-surface-200);
  overflow-y: auto;
}

.pipeline-rail__title {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  text-transform: uppercase;
  letter-spacing: 0.07em;
  color: $surface-500;
  padding: $space-1 $space-2 $space-2;
  margin: 0;

  // Inverted dark scale: uppercase header needs surface-600+ to read on dark card.
  .app-dark & {
    color: var(--p-surface-600);
  }
}

.pipeline-rail__list {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-height: 0;
}

.pipeline-rail__new {
  margin-top: $space-2;
  width: 100%;
  justify-content: center;
}

.pipeline-rail__skeleton {
  padding: $space-2 0;
}

.pipeline-rail__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-2;
  padding: $space-6 $space-2;
  text-align: center;
}

.pipeline-rail__empty-icon {
  font-size: $font-size-icon-lg;
  color: var(--p-surface-400);
}

.pipeline-rail__empty-title {
  font-size: $font-size-sm;
  color: var(--p-text-muted-color);
  margin: 0;
}
</style>
