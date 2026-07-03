<template>
  <Tag
    :severity="severity"
    :value="label"
    :class="['pct-tag', `pct-tag--${size}`, { 'pct-tag--muted': badge === 'none' }]"
  />
</template>

<script setup lang="ts">
import { computed } from 'vue'
import Tag from 'primevue/tag'
import type { PctBadge } from '@/entities/motivation'
import { formatMkPct, pctToBadge, badgeToSeverity } from '@/utils/motivation'

/**
 * Semantic percent tag (SPEC §PctTag). A thin wrapper over PrimeVue `Tag` — the
 * severity is derived from the ≥100 / 80–99 / <80 thresholds; `null` → muted «—».
 * When `badge` is supplied it overrides the threshold derivation (the backend
 * already computes a badge per row/dept in the contract).
 */
const props = withDefaults(
  defineProps<{
    value: number | null
    /** Explicit badge from the API; falls back to threshold derivation. */
    badge?: PctBadge
    size?: 'sm' | 'md'
  }>(),
  { badge: undefined, size: 'md' },
)

const badge = computed<PctBadge>(() => props.badge ?? pctToBadge(props.value))

const severity = computed(() => badgeToSeverity(badge.value))

const label = computed<string>(() => formatMkPct(props.value))
</script>

<style lang="scss" scoped>
.pct-tag {
  font-variant-numeric: tabular-nums;
  font-weight: $font-weight-semibold;
}

.pct-tag--sm {
  font-size: $font-size-2xs;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  padding: 1px $space-2;
}

.pct-tag--md {
  font-size: $font-size-sm;
}

// Muted (no plan) — neutral, not a status colour.
.pct-tag--muted {
  color: $surface-500;

  .app-dark & {
    color: var(--p-surface-600);
  }
}
</style>
