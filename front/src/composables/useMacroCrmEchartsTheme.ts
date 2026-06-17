/**
 * Reactive ECharts theme switcher for MACRO CRM.
 *
 * Watches `theme` from useThemeStore and re-registers the
 * `macro-crm` ECharts theme so all <VChart theme="macro-crm"> instances
 * pick up the correct axis/legend/tooltip colours.
 *
 * Mount once in App.vue (or DashboardPage) — multiple calls are harmless
 * because `registerTheme` is idempotent.
 */
import { watch } from 'vue'
import { useThemeStore } from '@/stores/theme'
import { rebuildMacroCrmTheme } from '@/plugins/echarts'

export const useMacroCrmEchartsTheme = (): void => {
  const themeStore = useThemeStore()

  // Immediate: sync theme with current dark-mode state on mount
  watch(
    () => themeStore.theme,
    (theme) => {
      rebuildMacroCrmTheme(theme === 'dark')
    },
    { immediate: true },
  )
}
