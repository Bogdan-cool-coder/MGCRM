<template>
  <div class="substage-item">
    <span class="substage-item__indent" />

    <!-- Color dot -->
    <span
      class="substage-item__dot"
      :style="{ backgroundColor: stage.color ?? 'var(--p-surface-400)' }"
    />

    <!-- Name -->
    <div class="substage-item__name-area" @dblclick="startRename">
      <template v-if="renaming">
        <InputText
          ref="inputRef"
          v-model="localName"
          class="substage-item__rename-input"
          size="small"
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
      <span v-else class="substage-item__name">{{ stage.name }}</span>
    </div>

    <Tag
      :value="t('sales.stageEditor.substageBadge')"
      severity="info"
      size="small"
      class="substage-item__tag"
    />

    <!-- Actions -->
    <div class="substage-item__actions" @click.stop>
      <Button
        icon="pi pi-pencil"
        severity="secondary"
        text
        size="small"
        @click="emit('edit', stage)"
      />
      <Button
        icon="pi pi-trash"
        severity="danger"
        text
        size="small"
        @click="emit('delete', stage.id)"
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
import type { PipelineStageDto } from '@/entities/sales'

const props = defineProps<{
  stage: PipelineStageDto
}>()

const emit = defineEmits<{
  edit: [stage: PipelineStageDto]
  delete: [id: number]
  rename: [id: number, name: string]
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
.substage-item {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-1 $space-3;
  padding-left: calc($space-8 + $space-4);
  min-height: 36px;
  border-radius: $radius-md;
  transition: background-color var(--app-transition-fast);

  &:hover {
    background-color: var(--p-surface-hover);

    .substage-item__actions {
      opacity: 1;
    }
  }

  &__indent {
    width: 2px;
    height: 20px;
    background-color: var(--p-surface-300);
    border-radius: 1px;
    flex-shrink: 0;
  }

  &__dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
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
    color: var(--p-text-color);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  &__rename-input {
    flex: 1;
    font-size: $font-size-sm;
  }

  &__tag {
    flex-shrink: 0;
  }

  &__actions {
    display: flex;
    gap: $space-1;
    flex-shrink: 0;
    opacity: 0;
    transition: opacity var(--app-transition-fast);
  }
}
</style>
