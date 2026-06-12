<template>
  <div class="stage-item">
    <!-- Drag handle — hidden for system stages (won/lost) -->
    <span v-if="!stage.is_won && !stage.is_lost" class="stage-item__drag-handle pi pi-bars" />

    <!-- Color dot (click → open drawer) -->
    <button
      class="stage-item__dot"
      :style="{ backgroundColor: stage.color ?? 'var(--p-surface-400)' }"
      :title="t('sales.stageEditor.editDrawer.title')"
      @click="emit('edit', stage)"
    />

    <!-- Name inline-rename -->
    <div class="stage-item__name-area" @dblclick="startRename">
      <template v-if="renaming">
        <InputText
          ref="inputRef"
          v-model="localName"
          class="stage-item__rename-input"
          @keydown="onKeydown"
          @blur="commitRename"
          @click.stop
        />
        <Button icon="pi pi-check" size="small" @click.stop="commitRename" />
        <Button
          icon="pi pi-times"
          size="small"
          severity="secondary"
          text
          @click.stop="cancelRename"
        />
      </template>
      <span v-else class="stage-item__name">{{ stage.name }}</span>
    </div>

    <!-- Badges -->
    <Tag
      v-if="stage.is_won || stage.is_lost"
      :value="t('sales.stageEditor.systemStageBadge')"
      severity="secondary"
      size="small"
      class="stage-item__badge"
    />

    <!-- Hidden toggle (only non-system stages) -->
    <div
      v-if="!stage.is_won && !stage.is_lost"
      class="stage-item__toggle-area"
      @click.stop
    >
      <ToggleSwitch
        :model-value="stage.hidden_by_default"
        :title="t('sales.stageEditor.fields.hiddenByDefault')"
        size="small"
        @update:model-value="(v) => emit('toggleHidden', stage.id, v)"
      />
    </div>

    <!-- SLA badge -->
    <span v-if="stage.sla_hours" class="stage-item__sla">
      {{ t('sales.stageEditor.fields.slaHours') }}: {{ stage.sla_hours }}ч
    </span>

    <!-- Actions -->
    <div class="stage-item__actions" @click.stop>
      <Button
        icon="pi pi-pencil"
        severity="secondary"
        text
        size="small"
        :title="t('common.edit')"
        @click="emit('edit', stage)"
      />
      <span
        v-tooltip.top="(stage.is_won || stage.is_lost) ? t('sales.stageEditor.deleteStage.systemTooltip') : undefined"
        :class="{ 'p-disabled': stage.is_won || stage.is_lost }"
        :style="(stage.is_won || stage.is_lost) ? { opacity: '0.4', pointerEvents: 'none' } : undefined"
      >
        <Button
          icon="pi pi-trash"
          severity="danger"
          text
          size="small"
          :disabled="stage.is_won || stage.is_lost"
          :title="t('common.delete')"
          @click="emit('delete', stage.id)"
        />
      </span>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import InputText from 'primevue/inputtext'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import ToggleSwitch from 'primevue/toggleswitch'
import type { PipelineStageDto } from '@/entities/sales'

const props = defineProps<{
  stage: PipelineStageDto
}>()

const emit = defineEmits<{
  edit: [stage: PipelineStageDto]
  delete: [id: number]
  rename: [id: number, name: string]
  toggleHidden: [id: number, value: boolean]
}>()

const { t } = useI18n()

const renaming = ref(false)
const localName = ref('')
const inputRef = ref<{ $el?: HTMLElement } | null>(null)

function startRename() {
  localName.value = props.stage.name
  renaming.value = true
  nextTick(() => {
    const el = inputRef.value?.$el?.querySelector<HTMLInputElement>('input') ?? (inputRef.value?.$el as HTMLInputElement | null)
    el?.focus()
    el?.select()
  })
}

function cancelRename() {
  renaming.value = false
  localName.value = ''
}

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Enter') {
    e.preventDefault()
    commitRename()
  } else if (e.key === 'Escape') {
    cancelRename()
  }
}

function commitRename() {
  if (!renaming.value) return
  const newName = localName.value.trim()
  if (!newName || newName === props.stage.name) {
    cancelRename()
    return
  }
  renaming.value = false
  emit('rename', props.stage.id, newName)
}
</script>

<style lang="scss" scoped>
.stage-item {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-2 $space-3;
  border-radius: $radius-md;
  background-color: var(--p-card-background);
  border: 1px solid var(--p-surface-200);
  transition: background-color var(--app-transition-fast);

  .app-dark & {
    border-color: var(--p-surface-700);
  }

  &__drag-handle {
    cursor: grab;
    color: var(--p-surface-400);
    font-size: $font-size-sm;
    flex-shrink: 0;
    user-select: none;

    &:active {
      cursor: grabbing;
    }
  }

  &__dot {
    width: 16px;
    height: 16px;
    border-radius: 50%;
    flex-shrink: 0;
    border: none;
    cursor: pointer;
    padding: 0;
    transition: transform var(--app-transition-fast);

    &:hover {
      transform: scale(1.2);
    }
  }

  &__name-area {
    flex: 1;
    display: flex;
    align-items: center;
    gap: $space-1;
    min-width: 0;
    cursor: text;
  }

  &__name {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  &__rename-input {
    flex: 1;
    font-size: $font-size-sm;
  }

  &__badge {
    flex-shrink: 0;
  }

  &__toggle-area {
    flex-shrink: 0;
    display: flex;
    align-items: center;
  }

  &__sla {
    font-size: $font-size-xs;
    color: var(--p-text-muted-color);
    flex-shrink: 0;
    white-space: nowrap;
  }

  &__actions {
    display: flex;
    gap: $space-1;
    flex-shrink: 0;
  }
}

// Ghost / dragging states
:global(.stage-item--ghost) {
  opacity: 0.5;
  background-color: var(--p-primary-100);
}

:global(.stage-item--dragging) {
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
}
</style>
