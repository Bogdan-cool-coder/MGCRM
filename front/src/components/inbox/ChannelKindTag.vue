<template>
  <Tag
    :class="['channel-kind-tag', `channel-kind-tag--${kind}`]"
    :size="size"
  >
    <template #default>
      <i :class="['pi', iconMap[kind]]" />
      <span v-if="showLabel" class="channel-kind-tag__label">{{ t(`inbox.channelKind.${kind}`) }}</span>
    </template>
  </Tag>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Tag from 'primevue/tag'
import type { ChannelKind } from '@/api/inbox'

withDefaults(
  defineProps<{
    kind: ChannelKind
    size?: 'small' | 'normal'
    showLabel?: boolean
  }>(),
  {
    size: 'small',
    showLabel: true,
  },
)

const { t } = useI18n()

const iconMap: Record<ChannelKind, string> = {
  tg: 'pi-telegram',
  wa: 'pi-whatsapp',
  email: 'pi-envelope',
  web_form: 'pi-globe',
  api: 'pi-code',
}
</script>

<style lang="scss" scoped>
.channel-kind-tag {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  border-radius: $radius-sm;
  font-size: $font-size-xs;
  font-weight: $font-weight-medium;
  padding: 2px $space-2;
  border: none;

  // ── Channel kind colour overrides ──────────────────────────────────────────

  &--tg {
    background-color: $blue-100;
    color: $blue-900;
  }

  &--wa {
    background-color: $green-100;
    color: $green-900;
  }

  &--email {
    background-color: $primary-50;
    color: $primary-900;
  }

  &--web_form {
    background-color: $surface-100;
    color: $surface-700;
  }

  &--api {
    background-color: $orange-100;
    color: $orange-900;
  }

  .app-dark & {
    // Invert channel kind colours for dark theme using surface-equivalent tones
    &.channel-kind-tag--tg {
      background-color: var(--p-blue-900);
      color: var(--p-blue-100);
    }

    &.channel-kind-tag--wa {
      background-color: var(--p-green-900);
      color: var(--p-green-100);
    }

    &.channel-kind-tag--email {
      background-color: var(--p-surface-700);
      color: var(--p-surface-100);
    }

    &.channel-kind-tag--web_form {
      background-color: var(--p-surface-700);
      color: var(--p-surface-200);
    }

    &.channel-kind-tag--api {
      background-color: var(--p-orange-900);
      color: var(--p-orange-100);
    }
  }
}

.channel-kind-tag__label {
  line-height: 1;
}
</style>
