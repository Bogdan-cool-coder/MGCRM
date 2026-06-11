// PrimeVue 4.5 — @primeuix/themes
import Aura from '@primeuix/themes/aura'
import { definePreset } from '@primeuix/themes'
import { zIndex as primeVueZIndex } from '@/theme/tokens/zIndex'
import { primeVuePrimitive } from './primitive'
import { primeVueSemantic } from './semantic'

export { primeVueZIndex }

/**
 * MG CRM PrimeVue preset.
 * Переопределяем ТОЛЬКО нужное поверх Aura.
 * options в main.ts: { prefix: 'p', darkModeSelector: '.app-dark', cssLayer: true }
 */
export const MgCrmPreset = definePreset(Aura, {
  primitive: primeVuePrimitive,
  semantic: primeVueSemantic,
  components: {
    // BUG-STRIPED FIX: Aura задаёт dark stripedBackground = '{surface.950}' в расчёте
    // на НЕинвертированную палитру (950 = почти чёрный). Наша dark-схема инвертирована
    // (dark surface.950 = #FFFFFF) → striped-строки становились белыми.
    // '{surface.50}' в dark = #272829 — чуть темнее обычных строк ({content.background}
    // = #444547), симметрично light (striped #F9FAFB чуть темнее белых строк).
    datatable: {
      colorScheme: {
        dark: {
          row: {
            stripedBackground: '{surface.50}',
          },
        },
      },
    },
  },
})
