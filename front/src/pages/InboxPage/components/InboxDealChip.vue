<template>
  <!-- failed → outline red "Route" pill (emits reprocess) -->
  <button
    v-if="msg.routing_status === 'failed'"
    type="button"
    class="inbox-deal-chip inbox-deal-chip--reprocess"
    :disabled="pending"
    @click.stop="emit('reprocess', msg.id)"
  >
    <i :class="['pi', pending ? 'pi-spin pi-spinner' : 'pi-refresh']" />
    <span>{{ t('inbox.dealChip.reprocess') }}</span>
  </button>

  <!-- routed / dedup with a target deal → #id link -->
  <RouterLink
    v-else-if="msg.target_deal_id"
    :to="`/deals/${msg.target_deal_id}`"
    :class="[
      'inbox-deal-chip',
      'inbox-deal-chip--deal',
      isDedup ? 'inbox-deal-chip--dedup' : 'inbox-deal-chip--routed',
    ]"
    @click.stop
  >
    <i :class="['pi', isDedup ? 'pi-link' : 'pi-briefcase']" />
    <span>#{{ msg.target_deal_id }}</span>
    <i
      v-if="msg.target_deal_created"
      v-tooltip.top="t('inbox.dealChip.created')"
      class="pi pi-check-circle inbox-deal-chip__created"
    />
  </RouterLink>

  <!-- nothing to route, not failed → em dash -->
  <span v-else class="inbox-deal-chip inbox-deal-chip--none">—</span>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import type { InboundMessage } from '@/api/inbox'

const props = defineProps<{
  msg: InboundMessage
  /** Spinner state for the failed "Route" pill. */
  pending?: boolean
}>()

const emit = defineEmits<{
  reprocess: [id: number]
}>()

const { t } = useI18n()

const isDedup = computed(() => props.msg.routing_status === 'dedup')
</script>

<style lang="scss" scoped>
.inbox-deal-chip {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  white-space: nowrap;
  text-decoration: none;
  line-height: 1;

  .pi {
    font-size: $font-size-sm;
  }
}

// ── routed (green briefcase) ────────────────────────────────────────────────
.inbox-deal-chip--routed {
  color: var(--p-green-700);

  .app-dark & {
    color: var(--p-green-400);
  }
}

// ── dedup (blue link) ───────────────────────────────────────────────────────
.inbox-deal-chip--dedup {
  color: var(--p-blue-700);

  .app-dark & {
    color: var(--p-blue-400);
  }
}

.inbox-deal-chip__created {
  color: var(--p-green-600);

  .app-dark & {
    color: var(--p-green-400);
  }
}

// ── failed (outline red "Route" pill) ───────────────────────────────────────
.inbox-deal-chip--reprocess {
  height: 24px;
  padding: 0 $space-2;
  border-radius: $radius-pill;
  border: 1px solid var(--p-red-300);
  background: transparent;
  color: var(--p-red-700);
  font-family: $font-family-sans;
  font-size: $font-size-xs;
  cursor: pointer;
  transition: background-color $transition-fast;

  &:hover:not(:disabled) {
    background: color-mix(in srgb, var(--p-red-500) 10%, transparent);
  }

  &:disabled {
    cursor: default;
  }

  .pi {
    font-size: $font-size-xs;
  }

  .app-dark & {
    border-color: var(--p-red-400);
    color: var(--p-red-400);

    &:hover:not(:disabled) {
      background: color-mix(in srgb, var(--p-red-400) 14%, transparent);
    }
  }
}

// ── none ────────────────────────────────────────────────────────────────────
.inbox-deal-chip--none {
  color: var(--p-text-muted-color);
  font-weight: $font-weight-normal;
}
</style>
