<template>
  <span
    class="deal-stage-tag"
    :style="stageStyle"
  >
    {{ stage.name }}
  </span>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import type { PipelineStageDto } from '@/entities/sales'

const props = defineProps<{
  stage: PipelineStageDto
}>()

const stageStyle = computed(() => {
  if (props.stage.color) {
    return { backgroundColor: props.stage.color, color: '#ffffff' }
  }
  if (props.stage.is_won) {
    return { backgroundColor: 'var(--p-green-500)', color: '#ffffff' }
  }
  if (props.stage.is_lost) {
    return { backgroundColor: 'var(--p-red-400)', color: '#ffffff' }
  }
  return { backgroundColor: 'var(--p-blue-400)', color: '#ffffff' }
})
</script>

<style lang="scss" scoped>
.deal-stage-tag {
  display: inline-block;
  padding: 3px 10px;
  border-radius: $radius-sm;
  font-size: $font-size-xs;
  font-weight: $font-weight-medium;
  white-space: nowrap;
  letter-spacing: 0.02em;
}
</style>
