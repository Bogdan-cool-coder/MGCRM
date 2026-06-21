<template>
  <Card class="stage-editor-card">
    <template #content>
      <!-- Header -->
      <div class="stage-editor-card__header">
        <span class="stage-editor-card__title">
          {{
            t('sales.pipelineEditor.section.stages')
          }}<template v-if="pipelineName">: {{ pipelineName }}</template>
        </span>
        <Button
          :label="t('sales.stageEditor.addStage')"
          icon="pi pi-plus"
          severity="secondary"
          outlined
          size="small"
          @click="emit('addStage')"
        />
      </div>

      <Divider class="stage-editor-card__divider" />

      <!-- Loading -->
      <template v-if="loading">
        <div class="stage-editor-card__skeleton">
          <Skeleton
            v-for="i in 4"
            :key="i"
            height="48px"
            border-radius="8px"
            class="mb-2"
          />
        </div>
      </template>

      <!-- Empty state -->
      <div v-else-if="topLevelStages.length === 0" class="stage-editor-card__empty">
        <i class="pi pi-list stage-editor-card__empty-icon" />
        <p class="stage-editor-card__empty-title">
          {{ t('sales.stageEditor.emptyStages.title') }}
        </p>
        <p class="stage-editor-card__empty-sub">
          {{ t('sales.stageEditor.emptyStages.subtitle') }}
        </p>
        <Button
          :label="t('sales.stageEditor.addStage')"
          icon="pi pi-plus"
          size="small"
          @click="emit('addStage')"
        />
      </div>

      <!-- Draggable list -->
      <div v-else class="stage-editor-card__list">
        <draggable
          v-model="localStages"
          item-key="id"
          handle=".stage-item__drag-handle"
          :animation="200"
          ghost-class="stage-item--ghost"
          drag-class="stage-item--dragging"
          @end="onReorderEnd"
        >
          <template #item="{ element }">
            <div class="stage-editor-card__stage-block">
              <StageEditorItem
                :stage="element"
                :stage-automations="automationsFor(element.id)"
                :automations-loading="automationsLoading"
                :automations-error="automationsError"
                @edit="(s) => emit('editStage', s)"
                @delete="(id) => emit('deleteStage', id)"
                @rename="(id, name) => emit('renameStage', id, name)"
                @toggle-hidden="(id, v) => emit('toggleHidden', id, v)"
                @add-automation="(stageId) => emit('addAutomation', stageId)"
                @edit-automation="(a) => emit('editAutomation', a)"
                @delete-automation="(id) => emit('deleteAutomation', id)"
                @toggle-automation="(id, v) => emit('toggleAutomation', id, v)"
                @refetch-automations="(stageId) => emit('refetchAutomations', stageId)"
              />
              <!-- Substages -->
              <StageSubstageItem
                v-for="sub in substagesOf(element.id)"
                :key="sub.id"
                :stage="sub"
                @edit="(s) => emit('editStage', s)"
                @delete="(id) => emit('deleteStage', id)"
                @rename="(id, name) => emit('renameStage', id, name)"
              />
            </div>
          </template>
        </draggable>
      </div>
    </template>
  </Card>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import draggable from 'vuedraggable'
import Card from 'primevue/card'
import Button from 'primevue/button'
import Divider from 'primevue/divider'
import Skeleton from 'primevue/skeleton'
import StageEditorItem from './StageEditorItem.vue'
import StageSubstageItem from './StageSubstageItem.vue'
import type { PipelineStageDto } from '@/entities/sales'
import type { AutomationDto } from '@/entities/automation'

const props = defineProps<{
  topLevelStages: PipelineStageDto[]
  substagesOf: (parentId: number) => PipelineStageDto[]
  pipelineName?: string
  loading?: boolean
  // Automation props
  automationsFor: (stageId: number) => AutomationDto[]
  automationsLoading: boolean
  automationsError: unknown
}>()

const emit = defineEmits<{
  addStage: []
  editStage: [stage: PipelineStageDto]
  deleteStage: [id: number]
  renameStage: [id: number, name: string]
  toggleHidden: [id: number, value: boolean]
  reorder: [ordered: PipelineStageDto[]]
  // Automation emits
  addAutomation: [stageId: number]
  editAutomation: [automation: AutomationDto]
  deleteAutomation: [id: number]
  toggleAutomation: [id: number, isActive: boolean]
  refetchAutomations: [stageId: number]
}>()

const { t } = useI18n()

// Local copy for v-model on draggable
const localStages = ref<PipelineStageDto[]>([...props.topLevelStages])

watch(
  () => props.topLevelStages,
  (v) => {
    localStages.value = [...v]
  },
)

function onReorderEnd() {
  emit('reorder', localStages.value)
}
</script>

<style lang="scss" scoped>
.stage-editor-card {
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
    font-size: $font-size-icon-lg;
    color: var(--p-surface-400);
  }

  &__empty-title {
    font-size: $font-size-base;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
    margin: 0;
  }

  &__empty-sub {
    font-size: $font-size-sm;
    color: var(--p-text-muted-color);
    margin: 0;
  }

  &__list {
    display: flex;
    flex-direction: column;
    gap: $space-2;
  }

  &__stage-block {
    display: flex;
    flex-direction: column;
    gap: $space-1;
  }
}
</style>
