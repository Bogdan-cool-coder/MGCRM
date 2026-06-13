<template>
  <span class="binding-chip">
    <i class="pi pi-link binding-chip__icon" />
    <span class="binding-chip__text">{{ label }}</span>
    <button class="binding-chip__remove" @click="emit('remove')">
      <i class="pi pi-times" />
    </button>
  </span>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import type { MessageTemplateBindingDto } from '@/entities/messageTemplate'

const props = defineProps<{
  binding: MessageTemplateBindingDto
}>()

const emit = defineEmits<{
  remove: []
}>()

const label = computed(() => {
  const parts: string[] = [props.binding.channel]
  if (props.binding.pipeline_name) parts.push(props.binding.pipeline_name)
  if (props.binding.stage_name) parts.push(props.binding.stage_name)
  if (props.binding.activity_type) parts.push(props.binding.activity_type)
  if (props.binding.automation_slot) parts.push(props.binding.automation_slot)
  return parts.join(' · ')
})
</script>

<style lang="scss" scoped>
.binding-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.25rem 0.5rem;
  background: var(--p-surface-100);
  border: 1px solid var(--p-surface-300);
  border-radius: 9999px;
  font-size: $font-size-xs;
  color: var(--p-text-color);

  &__icon {
    font-size: 0.7rem;
    color: var(--p-text-muted-color);
  }

  &__text {
    max-width: 240px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  &__remove {
    display: flex;
    align-items: center;
    justify-content: center;
    background: none;
    border: none;
    padding: 0;
    cursor: pointer;
    color: var(--p-text-muted-color);
    font-size: 0.65rem;
    line-height: 1;

    &:hover {
      color: var(--p-red-500);
    }
  }
}
</style>
