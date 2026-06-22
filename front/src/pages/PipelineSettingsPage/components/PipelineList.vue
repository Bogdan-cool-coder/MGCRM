<template>
  <Card class="pipeline-list-card">
    <template #content>
      <!-- Header row -->
      <div class="pipeline-list-card__header">
        <span class="pipeline-list-card__title">
          {{ t('sales.pipelineEditor.section.pipelines') }}
        </span>
        <Button
          :label="t('sales.pipelineEditor.createPipeline')"
          icon="pi pi-plus"
          severity="primary"
          size="small"
          @click="emit('create')"
        />
      </div>

      <Divider class="pipeline-list-card__divider" />

      <!-- Loading skeleton -->
      <template v-if="loading">
        <div class="pipeline-list-card__skeleton">
          <Skeleton height="44px" class="mb-2" border-radius="8px" />
          <Skeleton height="44px" border-radius="8px" width="70%" />
        </div>
      </template>

      <!-- Empty state -->
      <div v-else-if="pipelines.length === 0" class="pipeline-list-card__empty">
        <i class="pi pi-sitemap pipeline-list-card__empty-icon" />
        <p class="pipeline-list-card__empty-title">{{ t('sales.pipelineEditor.section.pipelines') }}</p>
        <Button
          :label="t('sales.pipelineEditor.createPipeline')"
          icon="pi pi-plus"
          size="small"
          @click="emit('create')"
        />
      </div>

      <!-- List -->
      <div v-else class="pipeline-list-card__list">
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
    </template>
  </Card>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Card from 'primevue/card'
import Button from 'primevue/button'
import Divider from 'primevue/divider'
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
.pipeline-list-card {
  &__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: $space-3;
    padding: $space-1 0;
  }

  &__title {
    font-size: $font-size-base;
    font-weight: $font-weight-semibold;
    color: var(--p-text-color);
  }

  &__divider {
    margin: $space-3 0;
  }

  &__skeleton {
    padding: $space-2 0;
  }

  &__empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: $space-3;
    padding: $space-8 $space-4;
    text-align: center;
  }

  &__empty-icon {
    font-size: $font-size-icon-2xl;
    color: var(--p-surface-400);
  }

  &__empty-title {
    font-size: $font-size-base;
    color: var(--p-text-muted-color);
    margin: 0;
  }

  &__list {
    display: flex;
    flex-direction: column;
    gap: $space-1;
  }
}
</style>
