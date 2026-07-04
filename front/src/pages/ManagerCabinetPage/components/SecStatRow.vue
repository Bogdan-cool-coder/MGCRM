<template>
  <!--
    Secondary-stat row (ManagerCabinet-v2 §2.8): «label — value — sub» with an
    optional info-tooltip on the label and an optional quiet currency note beside
    the value (replaces the old orange multi-currency Message).
  -->
  <div class="sec-stat">
    <span class="sec-stat__label">
      {{ label }}
      <i
        v-if="hint"
        v-tooltip.top="hint"
        class="pi pi-info-circle sec-stat__hint"
        aria-hidden="true"
      />
    </span>
    <span class="sec-stat__right">
      <span class="sec-stat__value-line">
        <span class="sec-stat__value">{{ value }}</span>
        <i
          v-if="ccy"
          v-tooltip.top="t('managerCabinet.kpi.ccyNote')"
          class="pi pi-info-circle sec-stat__ccy"
          aria-hidden="true"
        />
      </span>
      <span v-if="sub" class="sec-stat__sub">{{ sub }}</span>
    </span>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'

defineProps<{
  label: string
  value: string
  sub?: string
  /** Tooltip text shown on the label info-icon. */
  hint?: string
  /** Show the quiet currency note beside the value. */
  ccy?: boolean
}>()

const { t } = useI18n()
</script>

<style lang="scss" scoped>
.sec-stat {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: $space-3;
  padding: $space-3 0;
  border-top: 1px solid $surface-200;

  .app-dark & {
    border-top-color: var(--p-surface-200);
  }
}

.sec-stat__label {
  display: inline-flex;
  align-items: center;
  font-size: $font-size-sm;
  color: $surface-700;

  .app-dark & {
    color: var(--p-surface-700);
  }
}

.sec-stat__hint,
.sec-stat__ccy {
  margin-left: $space-1;
  font-size: $font-size-2xs;
  color: $surface-600;
  cursor: help;

  .app-dark & {
    color: var(--p-surface-600);
  }
}

.sec-stat__right {
  text-align: right;
}

.sec-stat__value-line {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
}

.sec-stat__value {
  font-size: $font-size-md;
  font-weight: $font-weight-bold;
  color: $surface-900;
  font-variant-numeric: tabular-nums;
}

.sec-stat__sub {
  display: block;
  margin-top: 1px;
  font-size: $font-size-2xs;
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-600);
  }
}
</style>
