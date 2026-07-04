import { appTheme } from '@/theme/config'
import { navyDarkAccent, navyDarkSurfacePalette } from '@/theme/tokens/colors'
import { primeVuePrimitive as primitive } from '../primitive'

const { primary: primaryPalette, surface: surfacePalette } = appTheme.palette

export const primeVueFoundationSemantic = {
  transitionDuration: primitive.transitionDuration,
  focusRing: primitive.focusRing,
  borderRadius: primitive.borderRadius,
  primary: {
    50: primaryPalette[50],
    100: primaryPalette[100],
    200: primaryPalette[200],
    300: primaryPalette[300],
    400: primaryPalette[400],
    500: primaryPalette[500],
    600: primaryPalette[600],
    700: primaryPalette[700],
    800: primaryPalette[800],
    900: primaryPalette[900],
    950: primaryPalette[950],
  },
  // ПАТТЕРН PrimeVue 4 styled (фикс BUG #2 — само-ссылки):
  //
  // Проблема: если задать colorScheme.light.surface через {surface.X} токены,
  // PrimeVue генерирует --p-surface-0: var(--p-surface-0) → само-ссылка → CSS invalid.
  //
  // Решение: задаём colorScheme.light/dark.surface ТОЛЬКО hex-значениями из surfacePalette.
  // Это также перекрывает Aura-default {slate.X} токены (которых нет в нашем primitive).
  // primitive.surface даёт исходный :root, colorScheme.light.surface его уточняет hex'ами.
  // colorScheme.dark.surface задаём hex в инверсном порядке (0→950, 950→0).
  colorScheme: {
    light: {
      primary: {
        color: '{primary.900}',        // #172747 — brand primary
        contrastColor: '{monochrome.white}',
        hoverColor: '{primary.800}',   // #1f2f5a
        activeColor: '{primary.700}',  // #263a6e
      },
      surface: {
        0: surfacePalette[0],
        50: surfacePalette[50],
        100: surfacePalette[100],
        200: surfacePalette[200],
        300: surfacePalette[300],
        400: surfacePalette[400],
        500: surfacePalette[500],
        600: surfacePalette[600],
        700: surfacePalette[700],
        800: surfacePalette[800],
        900: surfacePalette[900],
        950: surfacePalette[950],
      },
    },
    dark: {
      // BRAND ACCENT (MSales 2.0): navy #172747 растворился бы на navy-фоне,
      // поэтому в dark акцент СВЕТЛЕЕТ до #4C7DF0. Читают ссылки/outlined/text-кнопки,
      // focus-ring, активные checkbox/radio/switch (все через {primary.color}).
      // Light-блок выше НЕ трогаем.
      primary: {
        color: navyDarkAccent.color,        // #4C7DF0 — --mg-navy-accent
        contrastColor: navyDarkAccent.ink,  // #EEF3FB — светлый ink на #4C7DF0
        hoverColor: navyDarkAccent.hover,   // #6E99FF
        activeColor: navyDarkAccent.active, // #3D6AD8
      },
      // NAVY SURFACE SCALE (MSales 2.0, tokens/dark.css).
      // Инверсия СОХРАНЕНА: surface.0 = самый тёмный фон (#0A1426),
      // surface.900/950 = светлый текст. Меняем только hex grey→navy;
      // navyDarkSurfacePalette уже авторена в порядке 0=тёмный…900=светлый,
      // поэтому мапим по индексу напрямую (не пере-инвертируем).
      // Все {surface.X}-ссылки ниже сохраняют смысл: {surface.100}=card #111E38,
      // {surface.200}=soft border #172847, {surface.900}=primary text #EAF0FA.
      surface: {
        0: navyDarkSurfacePalette[0],     // #0A1426 — page / deepest
        50: navyDarkSurfacePalette[50],   // #0F1F3D — striped / app bg alt
        100: navyDarkSurfacePalette[100], // #111E38 — card / panel / input / overlay
        200: navyDarkSurfacePalette[200], // #172847 — soft border / raised
        300: navyDarkSurfacePalette[300], // #27395C — border default
        400: navyDarkSurfacePalette[400], // #3A4F78 — strong border / icon / placeholder
        500: navyDarkSurfacePalette[500], // #647294 — muted ink
        600: navyDarkSurfacePalette[600], // #8593B0 — secondary-muted text
        700: navyDarkSurfacePalette[700], // #B4C2DA — secondary text
        800: navyDarkSurfacePalette[800], // #C6D0E2 — strong text (outlined ink)
        900: navyDarkSurfacePalette[900], // #EAF0FA — primary text
        950: navyDarkSurfacePalette[950], // #F5F8FE — max contrast text
      },
      // DARK CARD/CONTENT (MSales 2.0 navy: Card/Panel bg = #111E38, border = #172847).
      //
      // 1) semantic.card (surfaces.ts) — common-токен, эмитится только в :root (light).
      //    Без dark-оверрайда здесь --p-card-background в dark наследовал бы light-значение.
      // 2) ВАЖНО: Card-КОМПОНЕНТ Aura тоже эмитит --p-card-background = {content.background}
      //    в своём style-таге (позже в @layer primeui, селектор :root перебивает наш .app-dark
      //    при равной специфичности). Поэтому dark card.background ОБЯЗАН совпадать с dark
      //    content.background ('{surface.100}') — тогда порядок тагов не влияет на цвет.
      //
      // Navy-шкала (инверсия сохранена): '{surface.100}' = #111E38 (card),
      // '{surface.50}' = #0F1F3D, '{surface.200}' = #172847 (soft border), '{surface.900}' = #EAF0FA (текст).
      card: {
        background: '{surface.100}',   // #111E38 — navy Card/Panel bg
        borderColor: '{surface.200}',  // #172847 — soft border
        color: '{surface.900}',        // #EAF0FA — читабельный текст
      },
      content: {
        background: '{surface.100}',   // #111E38 — строки DataTable, панели
        hoverBackground: '{surface.200}',
        borderColor: '{surface.200}',
        color: '{surface.900}',
      },
      // DARK OVERLAY/MODAL (BUG-A Drawer + BUG-B Dialog).
      //
      // Aura: Dialog + Drawer оба используют {overlay.modal.background}.
      // Aura dark-default: overlay.modal.background = {surface.900}.
      // Наша dark-палитра ИНВЕРТИРОВАНА → dark.surface.900 = navyDarkSurfacePalette[900] = #EAF0FA (светло!).
      // Результат: Drawer/Dialog получали белый фон в dark-режиме.
      //
      // Фикс: явно задаём dark colorScheme overlay, используя {surface.X} ссылки, которые
      // через нашу инвертированную navy dark.surface резолвятся в нужные тёмные hex-значения:
      //   {surface.100} → navyDarkSurfacePalette[100] = #111E38 (Card/Panel bg — MSales 2.0)
      //   {surface.200} → navyDarkSurfacePalette[200] = #172847 (soft border — MSales 2.0)
      //   {surface.900} → navyDarkSurfacePalette[900] = #EAF0FA (читабельный текст)
      //
      // То же самое для overlay.select (Select-дропдаун внутри Drawer/Dialog).
      overlay: {
        select: {
          background: '{surface.100}',
          borderColor: '{surface.200}',
          color: '{surface.900}',
        },
        popover: {
          background: '{surface.100}',
          borderColor: '{surface.200}',
          color: '{surface.900}',
        },
        modal: {
          background: '{surface.100}',
          borderColor: '{surface.200}',
          color: '{surface.900}',
        },
        navigation: {
          shadow: '0 4px 6px -1px rgba(0,0,0,0.4), 0 2px 4px -2px rgba(0,0,0,0.3)',
        },
      },
      // DARK TEXT COLOR (BUG: --p-text-color = #000 в dark-режиме).
      //
      // Aura dark задаёт text.color = {surface.0}. Наша dark-палитра ИНВЕРТИРОВАНА →
      // dark.surface.0 = navyDarkSurfacePalette[0] = #0A1426 (тёмный фон → невидимый текст).
      // Фикс: явно переопределяем text.color через {surface.900} = navyDarkSurfacePalette[900] = #EAF0FA.
      text: {
        color: '{surface.900}',        // #EAF0FA — читабельный светлый текст в dark
        mutedColor: '{surface.400}',   // #3A4F78 — muted
        hoverMutedColor: '{surface.300}', // #27395C
      },
      // DARK FORM FIELDS (BUG-3: инпуты белые в dark-режиме).
      //
      // Причина: Aura dark использует {surface.950} для background формполей.
      // Наша dark-палитра ИНВЕРТИРОВАНА → dark.surface.950 = navy #F5F8FE (светлый текст!).
      // Результат без фикса: InputText/Textarea/Select/InputNumber получали светлый фон в dark.
      //
      // Фикс: явно переопределяем formField-токены через {surface.X} navy-ссылки:
      //   {surface.100} → #111E38 (основной фон инпута = card bg)
      //   {surface.200} → #172847 (disabled bg)
      //   {surface.400} → #3A4F78 (border strong — --mg-input-border)
      //   {surface.500} → #647294 (placeholder/muted — --mg-input-placeholder)
      //   {surface.900} → #EAF0FA (читабельный текст)
      //   hover = #4C7DF0 (accent), focus = #6E99FF
      // MSales 2.0 inputs: bg #111E38, border #3A4F78 (strong, {surface.400}),
      // hover-border = акцент #4C7DF0, focus-border = #6E99FF (светлее акцента,
      // читается на navy). Border поднят с {surface.200} до {surface.400} по ТЗ (§c).
      formField: {
        background: '{surface.100}',           // #111E38 — navy card bg (вместо Aura {surface.950})
        disabledBackground: '{surface.200}',   // #172847 — --mg-input-disabled-bg
        filledBackground: '{surface.100}',     // #111E38
        filledHoverBackground: '{surface.100}',
        filledFocusBackground: '{surface.100}',
        borderColor: '{surface.400}',              // #3A4F78 — --mg-input-border (strong)
        hoverBorderColor: '{primary.color}',       // #4C7DF0 — --mg-input-hover-border
        focusBorderColor: navyDarkAccent.hover,    // #6E99FF — --mg-input-focus-border
        invalidBorderColor: '{red.300}',
        color: '{surface.900}',                // #EAF0FA — основной текст
        disabledColor: '{surface.500}',        // #647294 — muted
        placeholderColor: '{surface.500}',     // #647294 — --mg-input-placeholder
        invalidPlaceholderColor: '{red.400}',
        floatLabelColor: '{surface.500}',
        floatLabelFocusColor: '{primary.color}',
        floatLabelActiveColor: '{surface.500}',
        floatLabelInvalidColor: '{form.field.invalid.placeholder.color}',
        iconColor: '{surface.400}',            // #3A4F78
        shadow: '0 0 #0000, 0 0 #0000, 0 1px 2px 0 rgba(0, 0, 0, 0.3)',
      },
    },
  },
  // NOTE: severity colours (secondary/success/danger/warning/info) are NOT valid
  // top-level `semantic` keys in PrimeVue 4 Aura — the theme service only reads
  // primary/colorScheme/formField/list/content/mask/navigation/overlay + the sizing
  // tokens. Severity is driven per-component (button.*Button, tag.*, message.*,
  // inline-message.*) in buttons.ts / dataDisplay.ts / feedback.ts. Former inert
  // top-level blocks here emitted no CSS variables and were removed (dead code).
} as const
