<template>
  <!--
    Inline-SVG progress ring (ManagerCabinet-v2 §2.6). Charter §4 permits inline
    SVG primitives in the template — this is the only way to draw a ring with a
    centre slot without a chart library. Stroke colours come from repo tokens
    passed by the parent (never hex here).
  -->
  <div class="score-ring" :style="{ width: `${size}px`, height: `${size}px` }">
    <svg
      :width="size"
      :height="size"
      class="score-ring__svg"
      aria-hidden="true"
    >
      <circle
        class="score-ring__track"
        :cx="size / 2"
        :cy="size / 2"
        :r="radius"
        fill="none"
        :stroke-width="stroke"
      />
      <circle
        class="score-ring__value"
        :cx="size / 2"
        :cy="size / 2"
        :r="radius"
        fill="none"
        :stroke="color"
        :stroke-width="stroke"
        stroke-linecap="round"
        :stroke-dasharray="circumference"
        :stroke-dashoffset="dashOffset"
      />
    </svg>
    <div class="score-ring__center">
      <slot />
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    /** Completion percent (clamped 0..100 internally). */
    pct: number
    /** Outer diameter in px. */
    size?: number
    /** Ring stroke width in px. */
    stroke?: number
    /** Fill stroke colour — a repo-token CSS string (e.g. var(--p-green-500)). */
    color: string
  }>(),
  { size: 104, stroke: 9 },
)

const radius = computed<number>(() => (props.size - props.stroke) / 2)

const circumference = computed<number>(() => 2 * Math.PI * radius.value)

const clampedPct = computed<number>(() => Math.max(0, Math.min(props.pct, 100)))

const dashOffset = computed<number>(
  () => circumference.value * (1 - clampedPct.value / 100),
)
</script>

<style lang="scss" scoped>
.score-ring {
  position: relative;
  flex-shrink: 0;
}

.score-ring__svg {
  transform: rotate(-90deg);
}

.score-ring__track {
  // Theme-reactive track — light: pale grey, dark: raised navy.
  stroke: var(--p-surface-100);
}

.score-ring__value {
  transition: stroke-dashoffset 0.5s ease;
}

.score-ring__center {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
}
</style>
