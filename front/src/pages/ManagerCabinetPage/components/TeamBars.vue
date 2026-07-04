<template>
  <!--
    Team gradient bars (ManagerCabinet-v2 §2.10). Each bar is a red→amber→green
    «health scale»; the right-hand mask crops it at the member's achievement
    against a 120% ceiling. Percent rendered by PctTag in quiet tone.
  -->
  <div class="team-bars">
    <div class="team-bars__head">
      <span class="team-bars__eyebrow">{{ t('managerCabinet.team.eyebrow') }}</span>
      <span v-if="!isAlone" class="team-bars__avg">
        {{ t('managerCabinet.team.avgLabel') }} {{ avgPct }}%
      </span>
    </div>

    <!-- Empty: sole member of the department -->
    <div v-if="isAlone" class="team-bars__empty">
      <i class="pi pi-users team-bars__empty-icon" aria-hidden="true" />
      <span class="team-bars__empty-text">{{ t('managerCabinet.team.alone') }}</span>
    </div>

    <!-- Member rows -->
    <div v-else class="team-bars__list">
      <div
        v-for="(m, idx) in members"
        :key="`${m.full_name}-${idx}`"
        class="team-bars__row"
      >
        <span
          class="team-bars__name"
          :class="{ 'team-bars__name--viewer': m.is_viewer }"
        >
          <i v-if="m.is_viewer" class="pi pi-user team-bars__name-icon" aria-hidden="true" />
          {{ m.full_name }}
        </span>

        <span class="team-bars__bar">
          <template v-if="m.score_pct != null">
            <span class="team-bars__bar-fill" />
            <span
              class="team-bars__bar-mask"
              :style="{ left: `${maskLeft(m.score_pct)}%` }"
            />
          </template>
        </span>

        <span class="team-bars__pct">
          <PctTag :value="m.score_pct" size="sm" quiet />
        </span>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import PctTag from '@/components/shared/PctTag.vue'
import type { TeamMember } from '@/entities/managerCabinet'

// Bar ceiling — 108% must not hit the edge, so the scale tops out at 120%.
const BAR_MAX = 120

defineProps<{
  members: TeamMember[]
  avgPct: number
  /** Department has only the viewer → show the «alone» empty state. */
  isAlone: boolean
}>()

const { t } = useI18n()

const maskLeft = (pct: number): number => (Math.min(pct, BAR_MAX) / BAR_MAX) * 100
</script>

<style lang="scss" scoped>
.team-bars {
  height: 100%;
}

.team-bars__head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: $space-3;
}

.team-bars__eyebrow {
  font-size: $font-size-2xs;
  font-weight: $font-weight-bold;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-600);
  }
}

.team-bars__avg {
  font-size: $font-size-xs;
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-600);
  }
}

.team-bars__list {
  display: flex;
  flex-direction: column;
  gap: $space-3;
  margin-top: $space-3;
}

.team-bars__row {
  display: grid;
  grid-template-columns: 1fr 76px 42px;
  align-items: center;
  gap: $space-3;
}

.team-bars__name {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-900;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;

  &--viewer {
    font-weight: $font-weight-semibold;
    color: var(--p-primary-color);
  }
}

.team-bars__name-icon {
  font-size: $font-size-3xs;
  margin-right: $space-1;
}

.team-bars__bar {
  position: relative;
  height: 6px;
  border-radius: $radius-pill;
  background: var(--p-surface-100);
  overflow: hidden;
}

.team-bars__bar-fill {
  position: absolute;
  inset: 0;
  background: linear-gradient(
    90deg,
    var(--p-red-500) 0%,
    var(--p-orange-400) 52%,
    var(--p-green-500) 100%
  );
}

// Crops the gradient at the achievement point — reveals only up to the member's pct.
.team-bars__bar-mask {
  position: absolute;
  top: 0;
  right: 0;
  bottom: 0;
  background: var(--p-surface-100);
}

.team-bars__pct {
  text-align: right;
}

.team-bars__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-2;
  padding: $space-6;
}

.team-bars__empty-icon {
  font-size: $font-size-icon-lg;
  color: $surface-400;
}

.team-bars__empty-text {
  font-size: $font-size-sm;
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-600);
  }
}
</style>
