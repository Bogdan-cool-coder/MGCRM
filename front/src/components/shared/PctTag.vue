<template>
  <!--
    Quiet mode (ManagerCabinet-v2 §3.6): the 80–99 «warning» band renders as a
    neutral grey number instead of an orange status tag. Success (≥100) and
    danger (<80) stay semantic; `null` → muted «—». Non-quiet mode keeps the
    original Tag rendering used across the МК card.
  -->
  <span
    v-if="quiet"
    :class="['pct-tag', 'pct-tag--text', `pct-tag--${size}`, `pct-tag--tone-${quietTone}`]"
  >{{ label }}</span>
  <Tag
    v-else
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
 *
 * `quiet` (ManagerCabinet-v2 §3.6) switches the 80–99 band to a neutral grey
 * number rather than an orange warn tag — used in the cabinet / МК screens where
 * «in norm» should read calm, not alarming. Success/danger keep their meaning.
 */
const props = withDefaults(
  defineProps<{
    value: number | null
    /** Explicit badge from the API; falls back to threshold derivation. */
    badge?: PctBadge
    size?: 'sm' | 'md'
    /** Quiet palette: 80–99 → neutral grey text instead of orange warn tag. */
    quiet?: boolean
  }>(),
  { badge: undefined, size: 'md', quiet: false },
)

const badge = computed<PctBadge>(() => props.badge ?? pctToBadge(props.value))

const severity = computed(() => badgeToSeverity(badge.value))

/** Text-tone class for quiet mode: success/danger stay coloured, warning→neutral, none→muted. */
const quietTone = computed<'success' | 'danger' | 'neutral' | 'muted'>(() => {
  switch (badge.value) {
    case 'success':
      return 'success'
    case 'danger':
      return 'danger'
    case 'warning':
      return 'neutral'
    default:
      return 'muted'
  }
})

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

// ── Quiet mode: plain coloured number (no Tag chrome) ────────────────────────
.pct-tag--text {
  display: inline-block;
  line-height: 1;
}

.pct-tag--tone-success {
  color: var(--p-green-500);
}

.pct-tag--tone-danger {
  color: var(--p-red-500);
}

// 80–99 band — neutral grey (theme-reactive), NOT the orange warn.
.pct-tag--tone-neutral {
  color: $surface-700;
}

.pct-tag--tone-muted {
  color: $surface-500;

  .app-dark & {
    color: var(--p-surface-600);
  }
}
</style>
