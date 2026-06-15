<template>
  <div class="stage-progress">
    <div
      v-for="seg in segments"
      :key="seg.id"
      v-tooltip.top="seg.name"
      class="stage-progress__seg"
      :class="{ 'stage-progress__seg--active': seg.isActive, 'stage-progress__seg--past': seg.isPast, 'stage-progress__seg--won': seg.isWon }"
      :style="seg.style"
      @click="$emit('stageClick', seg.id)"
    />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Tooltip } from 'primevue'
import type { PipelineStageDto } from '@/entities/sales'

const vTooltip = Tooltip

const props = defineProps<{
  stages: PipelineStageDto[]
  currentStageId: number
}>()

defineEmits<{
  stageClick: [stageId: number]
}>()

interface Segment {
  id: number
  name: string
  isActive: boolean
  isPast: boolean
  isWon: boolean
  style: Record<string, string>
}

const segments = computed((): Segment[] => {
  const visible = props.stages
    .filter((s) => !s.hidden_by_default && !s.is_lost)
    .sort((a, b) => a.sort_order - b.sort_order)

  // Separate won stages to append at the end
  const normal = visible.filter((s) => !s.is_won)
  const won = visible.filter((s) => s.is_won)

  const ordered = [...normal, ...won]

  const currentIdx = ordered.findIndex((s) => s.id === props.currentStageId)

  return ordered.map((stage, idx): Segment => {
    const isPast = idx < currentIdx
    const isActive = idx === currentIdx
    const isFuture = idx > currentIdx

    let bg: string
    if (stage.is_won) {
      bg = '#A7EFAA'
    } else if (isPast || isActive) {
      bg = stage.color ?? 'var(--p-primary-400)'
    } else {
      bg = 'rgba(255,255,255,0.2)'
    }

    return {
      id: stage.id,
      name: stage.name,
      isActive,
      isPast,
      isWon: stage.is_won,
      style: {
        flex: '1',
        backgroundColor: bg,
        cursor: isFuture || stage.is_won ? 'pointer' : 'default',
      },
    }
  })
})
</script>

<style lang="scss" scoped>
.stage-progress {
  display: flex;
  gap: 2px;
  height: 6px;
  border-radius: $radius-sm;
  overflow: hidden;
  width: 100%;
}

.stage-progress__seg {
  height: 100%;
  border-radius: 2px;
  transition: opacity 0.15s;

  &:hover {
    opacity: 0.85;
  }
}
</style>
