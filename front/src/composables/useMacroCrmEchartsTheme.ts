/**
 * Reactive ECharts theme switcher for MACRO CRM.
 *
 * Watches `isDarkMode` from the layout store and re-registers the
 * `macro-crm` ECharts theme so all <VChart theme="macro-crm"> instances
 * pick up the correct axis/legend/tooltip colours.
 *
 * Mount once in App.vue (or DashboardPage) — multiple calls are harmless
 * because `registerTheme` is idempotent.
 */
import { watch } from 'vue'
import { useLayoutStore } from '@/stores/layout'
import { rebuildMacroCrmTheme } from '@/plugins/echarts'

export const useMacroCrmEchartsTheme = (): void => {
  const layoutStore = useLayoutStore()

  // Immediate: sync theme with current dark-mode state on mount
  watch(
    () => layoutStore.isDarkMode,
    (dark) => {
      rebuildMacroCrmTheme(dark)
    },
    { immediate: true },
  )
}
