<template>
  <span
    class="inbox-channel-dot"
    :style="dotStyle"
    :title="t(`inbox.channelKind.${kind}`)"
  >
    <i :class="['pi', iconMap[kind]]" :style="{ fontSize: `${Math.round(size * 0.42)}px` }" />
  </span>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useThemeStore } from '@/stores/theme'
import type { ChannelKind } from '@/api/inbox'

const props = withDefaults(
  defineProps<{
    kind: ChannelKind
    /** Circle diameter in px (34 default; 38 in cozy density). */
    size?: number
  }>(),
  { size: 34 },
)

const { t } = useI18n()
const themeStore = useThemeStore()

/** Channel icons — aligned with ChannelKindTag (web_form = pi-globe, not pi-file-edit). */
const iconMap: Record<ChannelKind, string> = {
  tg: 'pi-telegram',
  wa: 'pi-whatsapp',
  email: 'pi-envelope',
  web_form: 'pi-globe',
  api: 'pi-code',
}

/**
 * Channel colour taken from the ChannelKindTag palette. The full `--p-{color}-{shade}`
 * scale is emitted by PrimeVue at runtime, so these resolve in both themes.
 * In dark, lighter shades keep the icon readable on the tinted circle.
 */
const colorMap: Record<ChannelKind, { light: string; dark: string }> = {
  tg: { light: 'var(--p-blue-700)', dark: 'var(--p-blue-400)' },
  wa: { light: 'var(--p-green-700)', dark: 'var(--p-green-400)' },
  email: { light: 'var(--p-surface-600)', dark: 'var(--p-surface-500)' },
  web_form: { light: 'var(--p-primary-color)', dark: 'var(--p-primary-color)' },
  api: { light: 'var(--p-orange-700)', dark: 'var(--p-orange-400)' },
}

const dotStyle = computed(() => {
  const isDark = themeStore.theme === 'dark'
  const color = isDark ? colorMap[props.kind].dark : colorMap[props.kind].light
  return {
    width: `${props.size}px`,
    height: `${props.size}px`,
    color,
    // color-mix with --mg-surface-card gives the pale tint in BOTH themes automatically
    // (navy-card mixes in dark). Pattern mirrors taskKindColors::taskKindChipStyle.
    background: `color-mix(in srgb, ${color} 14%, var(--mg-surface-card))`,
  }
})
</script>

<style lang="scss" scoped>
.inbox-channel-dot {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  border-radius: $radius-circle;
  // width/height/color/background are set inline (size + color-mix per channel)
}
</style>
