<template>
  <span
    class="engagement-chip"
    :class="[`engagement-chip--${tier}`, { 'engagement-chip--dot': dotOnly }]"
    v-tooltip.bottom="tooltipText"
  >
    <i :class="['pi', chipIcon]" />
    <span v-if="!dotOnly" class="engagement-chip__label">{{ chipLabel }}</span>
  </span>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

export type EngagementTier = 'fresh' | 'cooling' | 'cold'

const props = withDefaults(
  defineProps<{
    tier: EngagementTier
    lastActivityAt?: string | null
    /** If true — renders as a colored dot only (for list view) */
    dotOnly?: boolean
  }>(),
  {
    lastActivityAt: null,
    dotOnly: false,
  },
)

const { t } = useI18n()

const chipIcon = computed((): string => {
  if (props.tier === 'cold') return 'pi-exclamation-circle'
  return 'pi-circle-fill'
})

const chipLabel = computed((): string => {
  return t(`crm.entity.engagement.${props.tier}`)
})

const tooltipText = computed((): string => {
  if (!props.lastActivityAt) return chipLabel.value
  const days = Math.floor(
    (Date.now() - new Date(props.lastActivityAt).getTime()) / (1000 * 60 * 60 * 24),
  )
  return t('crm.entity.engagement.tooltip', { days })
})
</script>

<style lang="scss" scoped>
.engagement-chip {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  font-size: $font-size-xs;
  padding: 2px 8px;
  border-radius: $radius-sm;
  white-space: nowrap;
  cursor: default;

  i {
    font-size: 10px;
    flex-shrink: 0;
  }

  // ── Tiers ──────────────────────────────────────────────────────────────────
  &--fresh {
    color: var(--p-green-500);
    background: rgba(34, 197, 94, 0.12);
  }

  &--cooling {
    color: var(--p-yellow-400);
    background: rgba(234, 179, 8, 0.12);
  }

  &--cold {
    color: var(--p-red-400);
    background: rgba(239, 68, 68, 0.15);
  }

  // ── Dot-only mode (list view) ───────────────────────────────────────────────
  &--dot {
    padding: 0;
    background: transparent;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    justify-content: center;

    i {
      font-size: 8px;
    }

    .engagement-chip__label {
      display: none;
    }
  }
}

// In dark-header context (override for white text on brand bg)
.entity-header & {
  background: rgba(255, 255, 255, 0.12);
  color: rgba(255, 255, 255, 0.9);

  &.engagement-chip--fresh i {
    color: var(--p-green-300);
  }

  &.engagement-chip--cooling i {
    color: var(--p-yellow-300);
  }

  &.engagement-chip--cold i {
    color: var(--p-red-300);
  }
}
</style>
