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

    // BUG-TOGGLEBUTTON FIX: Aura dark ToggleButton (и SelectButton, который его наследует)
    // использует '{surface.950}' для background/checkedBackground/hoverBackground/borderColor.
    // Наша dark-палитра ИНВЕРТИРОВАНА → dark surface.950 = surfacePalette[0] = #FFFFFF → белый.
    // Дополнительно: content.checkedBackground = '{surface.800}' → surfacePalette[100] = #F1F2F3 (почти белый).
    //
    // Фикс через тот же паттерн, что и datatable выше (components.*.colorScheme.dark):
    //   {surface.100} → surfacePalette[800] = #444547  — контейнер SelectButton (канон card bg)
    //   {surface.50}  → surfacePalette[900] = #272829  — выбранный элемент (темнее = выделяется)
    //   {surface.400} → surfacePalette[500] = #9B9C9F  — muted text (не выбранный)
    //   {surface.300} → surfacePalette[600] = #7E7F82  — hover text
    //   {surface.900} → surfacePalette[50]  = #F9FAFB  — текст активного (checked) элемента
    togglebutton: {
      colorScheme: {
        dark: {
          root: {
            background: '{surface.100}',           // #444547 — контейнер кнопок
            checkedBackground: '{surface.100}',    // #444547 — тот же контейнер
            hoverBackground: '{surface.100}',      // #444547
            borderColor: '{surface.100}',          // #444547
            color: '{surface.400}',                // #9B9C9F — неактивный текст
            hoverColor: '{surface.300}',           // #7E7F82 — hover
            checkedColor: '{surface.900}',         // #F9FAFB — активный (читаемый)
            checkedBorderColor: '{surface.100}',   // #444547
          },
          content: {
            checkedBackground: '{surface.50}',     // #272829 — выбранный чип (тёмнее контейнера)
          },
          icon: {
            color: '{surface.400}',                // #9B9C9F
            hoverColor: '{surface.300}',           // #7E7F82
            checkedColor: '{surface.900}',         // #F9FAFB
          },
        },
      },
    },
  },
})
