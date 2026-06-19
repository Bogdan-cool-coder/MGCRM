<template>
  <div
    class="pipeline-item"
    :class="{ 'pipeline-item--active': isActive, 'pipeline-item--highlight': highlighted }"
    @click="emit('select')"
  >
    <!-- Color dot -->
    <span class="pipeline-item__dot" />

    <!-- Name / inline-rename -->
    <div class="pipeline-item__name-area" @dblclick.stop="startRename">
      <template v-if="renaming">
        <InputText
          ref="inputRef"
          v-model="localName"
          class="pipeline-item__rename-input"
          :disabled="saving"
          @keydown="onKeydown"
          @blur="commitRename"
          @click.stop
        />
        <Button
          icon="pi pi-check"
          size="small"
          :loading="saving"
          class="pipeline-item__rename-btn"
          @click.stop="commitRename"
        />
        <Button
          icon="pi pi-times"
          size="small"
          severity="secondary"
          text
          :disabled="saving"
          class="pipeline-item__rename-btn"
          @click.stop="cancelRename"
        />
      </template>
      <span v-else class="pipeline-item__name">{{ pipeline.name }}</span>
    </div>

    <Tag
      v-if="isActive && !renaming"
      :value="t('sales.pipelineEditor.activeBadge')"
      severity="info"
      class="pipeline-item__active-tag"
    />

    <!-- Actions -->
    <div v-if="!renaming" class="pipeline-item__actions" @click.stop>
      <Button
        icon="pi pi-pencil"
        severity="secondary"
        text
        size="small"
        :title="t('common.edit')"
        @click="startRename"
      />
      <Button
        icon="pi pi-copy"
        severity="secondary"
        text
        size="small"
        :loading="duplicating"
        :title="t('sales.pipelineEditor.duplicatePipeline.buttonTitle')"
        @click="emit('duplicate', pipeline.id)"
      />
      <Button
        icon="pi pi-trash"
        severity="danger"
        outlined
        size="small"
        :title="t('common.delete')"
        @click="emit('delete', pipeline.id)"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import InputText from 'primevue/inputtext'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import type { PipelineDto } from '@/entities/sales'

const props = defineProps<{
  pipeline: PipelineDto
  isActive?: boolean
  saving?: boolean
  duplicating?: boolean
  highlighted?: boolean
}>()

const emit = defineEmits<{
  select: []
  rename: [id: number, name: string]
  duplicate: [id: number]
  delete: [id: number]
}>()

const { t } = useI18n()

const renaming = ref(false)
const localName = ref('')
const inputRef = ref<{ $el?: HTMLElement } | null>(null)

function startRename() {
  localName.value = props.pipeline.name
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
    void commitRename()
  } else if (e.key === 'Escape') {
    cancelRename()
  }
}

async function commitRename() {
  if (!renaming.value) return
  const newName = localName.value.trim()
  if (!newName || newName === props.pipeline.name) {
    cancelRename()
    return
  }
  renaming.value = false
  emit('rename', props.pipeline.id, newName)
}
</script>

<style lang="scss" scoped>
.pipeline-item {
  display: flex;
  align-items: center;
  gap: $space-3;
  padding: $space-2 $space-3;
  border-radius: $radius-md;
  cursor: pointer;
  transition: background-color var(--app-transition-fast);
  min-height: 44px;

  &:hover {
    background-color: var(--p-surface-hover);
  }

  &--active {
    background-color: var(--p-primary-50);

    .app-dark & {
      background-color: var(--p-surface-800);
    }
  }

  &__dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background-color: var(--p-primary-500);
    flex-shrink: 0;
  }

  &__name-area {
    flex: 1;
    display: flex;
    align-items: center;
    gap: $space-1;
    min-width: 0;
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

  &__rename-btn {
    flex-shrink: 0;
  }

  &__active-tag {
    flex-shrink: 0;
  }

  &__actions {
    display: flex;
    gap: $space-1;
    flex-shrink: 0;
    opacity: 0;
    transition: opacity var(--app-transition-fast);
  }

  &:hover &__actions {
    opacity: 1;
  }

  // Flash-highlight for newly duplicated pipeline
  @keyframes pipeline-item-highlight {
    0%   { background-color: var(--p-primary-100); }
    70%  { background-color: var(--p-primary-50); }
    100% { background-color: transparent; }
  }

  &--highlight {
    animation: pipeline-item-highlight 2.4s ease-out forwards;

    .app-dark & {
      @keyframes pipeline-item-highlight-dark {
        0%   { background-color: var(--p-surface-700); }
        70%  { background-color: var(--p-surface-800); }
        100% { background-color: transparent; }
      }
      animation-name: pipeline-item-highlight-dark;
    }
  }
}
</style>
