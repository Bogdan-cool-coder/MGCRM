// PrimeVue 4.5 — @primeuix/themes
import Aura from '@primeuix/themes/aura'
import { definePreset } from '@primeuix/themes'
import { navyDarkAccent } from '@/theme/tokens/colors'
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
    // BUG-DS4-4: primary-кнопка в dark-режиме получала {primary.color} = {primary.400} = #6F87BC
    // (светло-синий), потому что dark colorScheme.primary.color = '{primary.400}'.
    // Семантический primary намеренно светлый в dark (ссылки, фокус-ринг — читаемы на тёмном фоне).
    // Но заливная Button (filled) должна оставаться brand-navy (#172747) в ОБЕИХ темах.
    // Фикс: переопределяем только components.button.colorScheme.dark.root — залитая кнопка.
    // outlined/text/link не трогаем: у них нет background-заливки, контраст не нужен.
    button: {
      // BUG-DS4-4 + BUG-DS4-6: используем colorScheme.{light,dark} — тот же паттерн, что
      // datatable и togglebutton ниже. Top-level root/outlined токены попадают в :root и
      // перебиваются Aura .app-dark с более высокой специфичностью → переносим в colorScheme.
      colorScheme: {
        light: {
          // BUG-DS4-4 LIGHT: явно фиксируем brand-navy, чтобы не сломать при возможных
          // изменениях semantic.light.primary.
          root: {
            primary: {
              background: '{primary.900}',        // #172747 — brand navy
              borderColor: '{primary.900}',
              color: '{monochrome.white}',
              hoverBackground: '{primary.800}',   // #1f2f5a
              hoverBorderColor: '{primary.800}',
              hoverColor: '{monochrome.white}',
              activeBackground: '{primary.700}',  // #263a6e
              activeBorderColor: '{primary.700}',
              activeColor: '{monochrome.white}',
            },
          },
          // LIGHT outlined secondary уже читаем из Aura-defaults — оставляем без изменений.
        },
        dark: {
          // MSales 2.0 (ТЗ решение a): navy #172747 растворяется на navy-фоне #0A1426,
          // поэтому filled-primary в dark СВЕТЛЕЕТ до акцента #4C7DF0 (light-блок выше НЕ трогаем).
          // ink остаётся светлым #EEF3FB (не инвертируем в тёмный — контраст ≈ 4.6:1, AA для 14px+).
          root: {
            primary: {
              background: navyDarkAccent.color,        // #4C7DF0 — --mg-action-primary-bg
              borderColor: navyDarkAccent.color,
              color: navyDarkAccent.ink,               // #EEF3FB — --mg-action-primary-text
              hoverBackground: navyDarkAccent.hover,   // #6E99FF
              hoverBorderColor: navyDarkAccent.hover,
              hoverColor: navyDarkAccent.ink,
              activeBackground: navyDarkAccent.active, // #3D6AD8
              activeBorderColor: navyDarkAccent.active,
              activeColor: navyDarkAccent.ink,
            },
          },
          // BUG-DS4-6 DARK: outlined secondary — бордер и текст читаемы на navy-фоне.
          // Navy-шкала (инверсия сохранена):
          //   {surface.300} → #27395C — бордер (default)
          //   {surface.800} → #C6D0E2 — светлый текст (читаемо)
          //   {surface.100} → #111E38 — hover bg (navy card bg)
          outlined: {
            secondary: {
              borderColor: '{surface.400}',      // #3A4F78 — видимый strong border на navy
              color: '{surface.800}',            // #C6D0E2 — светлый, читаемый
              hoverBackground: '{surface.100}',  // #111E38 — navy card bg
              activeBackground: '{surface.100}',
            },
          },
        },
      },
    },

    // BUG-STRIPED FIX: Aura задаёт dark stripedBackground = '{surface.950}' в расчёте
    // на НЕинвертированную палитру (950 = почти чёрный). Наша dark-схема инвертирована
    // (dark surface.950 = #F5F8FE) → striped-строки становились светлыми.
    // '{surface.50}' в dark = #0F1F3D — чуть темнее обычных строк ({content.background}
    // = #111E38), симметрично light (striped #F9FAFB чуть темнее белых строк).
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
    // Наша dark-палитра ИНВЕРТИРОВАНА → dark surface.950 = #F5F8FE (светлый) → фон был светлым.
    //
    // Фикс через тот же паттерн, что и datatable выше (components.*.colorScheme.dark):
    //   {surface.100} → #111E38  — контейнер SelectButton (navy card bg)
    //   {surface.50}  → #0F1F3D  — выбранный элемент (темнее = выделяется)
    //   {surface.400} → #3A4F78  — muted text (не выбранный)
    //   {surface.300} → #27395C  — hover
    //   {surface.900} → #EAF0FA  — текст активного (checked) элемента
    // TABS SPEC §3:
    // - active tab label font-weight = 600 (scoped via colorScheme to avoid affecting inactive).
    // - active underline bar height = 2px (Aura default = 1px).
    // - tablist dark background = {surface.100} = #111E38 (navy card bg, NOT {surface.900}=#EAF0FA).
    //
    // NOTE: tabs.tab.fontWeight is a single token for all tabs — it cannot be split active/inactive
    // at the token level. Aura Tabs render active tab with data-p-active="true"; we use scoped
    // :deep SCSS in the consumer pages/components for active-only font-weight = 600.
    // Here we only set activeBar.height=2px and colorScheme.dark.tablist.background.
    tabs: {
      activeBar: {
        height: '2px',
        background: '{primary.900}', // brand #172747 underline
      },
      colorScheme: {
        dark: {
          tablist: {
            background: '{surface.100}', // dark: #111E38 (navy card bg, NOT {surface.900}=#EAF0FA)
          },
          tab: {
            background: '{surface.100}',
            hoverBackground: '{surface.100}',
            activeBackground: '{surface.100}',
          },
        },
      },
    },

    togglebutton: {
      colorScheme: {
        dark: {
          root: {
            background: '{surface.100}',           // #111E38 — контейнер кнопок
            checkedBackground: '{surface.100}',    // #111E38 — тот же контейнер
            hoverBackground: '{surface.100}',      // #111E38
            borderColor: '{surface.100}',          // #111E38
            color: '{surface.500}',                // #647294 — неактивный текст
            hoverColor: '{surface.700}',           // #B4C2DA — hover
            checkedColor: '{surface.900}',         // #EAF0FA — активный (читаемый)
            checkedBorderColor: '{surface.100}',   // #111E38
          },
          content: {
            checkedBackground: '{surface.50}',     // #0F1F3D — выбранный чип (тёмнее контейнера)
          },
          icon: {
            color: '{surface.500}',                // #647294
            hoverColor: '{surface.700}',           // #B4C2DA
            checkedColor: '{surface.900}',         // #EAF0FA
          },
        },
      },
    },
  },
})
