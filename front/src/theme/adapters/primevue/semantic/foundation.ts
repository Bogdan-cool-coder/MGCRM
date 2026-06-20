import { appTheme } from '@/theme/config'
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
      primary: {
        color: '{primary.400}',
        contrastColor: '{monochrome.white}',
        hoverColor: '{primary.300}',
        activeColor: '{primary.200}',
      },
      surface: {
        0: surfacePalette[950],
        50: surfacePalette[900],
        100: surfacePalette[800],
        200: surfacePalette[700],
        300: surfacePalette[600],
        400: surfacePalette[500],
        500: surfacePalette[400],
        600: surfacePalette[300],
        700: surfacePalette[200],
        800: surfacePalette[100],
        900: surfacePalette[50],
        950: surfacePalette[0],
      },
      // DARK CARD/CONTENT (дизайн-система §5.2: Card/Panel bg = #444547, border = #616263).
      //
      // 1) semantic.card (surfaces.ts) — common-токен, эмитится только в :root (light).
      //    Без dark-оверрайда здесь --p-card-background в dark наследовал бы light-значение.
      // 2) ВАЖНО: Card-КОМПОНЕНТ Aura тоже эмитит --p-card-background = {content.background}
      //    в своём style-таге (позже в @layer primeui, селектор :root перебивает наш .app-dark
      //    при равной специфичности). Поэтому dark card.background ОБЯЗАН совпадать с dark
      //    content.background ('{surface.100}') — тогда порядок тагов не влияет на цвет.
      //
      // Палитра dark ИНВЕРТИРОВАНА: '{surface.100}' = surfacePalette[800] = #444547 (Gray-800),
      // '{surface.50}' = #272829, '{surface.200}' = #616263, '{surface.900}' = #F9FAFB (текст).
      card: {
        background: '{surface.100}',   // #444547 — канон §5.2 Card/Panel bg
        borderColor: '{surface.200}',  // #616263 — канон §5.2 Border default
        color: '{surface.900}',        // #F9FAFB — читабельный текст
      },
      content: {
        background: '{surface.100}',   // #444547 — строки DataTable, панели
        hoverBackground: '{surface.200}',
        borderColor: '{surface.200}',
        color: '{surface.900}',
      },
      // DARK OVERLAY/MODAL (BUG-A Drawer + BUG-B Dialog).
      //
      // Aura: Dialog + Drawer оба используют {overlay.modal.background}.
      // Aura dark-default: overlay.modal.background = {surface.900}.
      // Наша dark-палитра ИНВЕРТИРОВАНА → dark.surface.900 = surfacePalette[50] = #F9FAFB (светло!).
      // Результат: Drawer/Dialog получали белый фон в dark-режиме.
      //
      // Фикс: явно задаём dark colorScheme overlay, используя {surface.X} ссылки, которые
      // через нашу инвертированную dark.surface резолвятся в нужные тёмные hex-значения:
      //   {surface.100} → surfacePalette[800] = #444547 (Card/Panel bg — канон §5.2)
      //   {surface.200} → surfacePalette[700] = #616263 (Border — канон §5.2)
      //   {surface.900} → surfacePalette[50]  = #F9FAFB (читабельный текст)
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
      // dark.surface.0 = surfacePalette[950] = #000000 (чёрный на тёмном фоне → невидимый текст).
      // Фикс: явно переопределяем text.color через {surface.900} = surfacePalette[50] = #F9FAFB.
      text: {
        color: '{surface.900}',        // #F9FAFB — читабельный светлый текст в dark
        mutedColor: '{surface.400}',   // #9B9C9F — muted (как Aura: {surface.500})
        hoverMutedColor: '{surface.300}', // #7E7F82
      },
      // DARK FORM FIELDS (BUG-3: инпуты белые в dark-режиме).
      //
      // Причина: Aura dark использует {surface.950} для background формполей.
      // Наша dark-палитра ИНВЕРТИРОВАНА → dark.surface.950 = surfacePalette[0] = #FFFFFF (белый!).
      // Результат: InputText/Textarea/Select/InputNumber получали белый фон в dark.
      //
      // Фикс: явно переопределяем все formField-токены, используя {surface.X} ссылки,
      // которые через нашу инвертированную dark.surface резолвятся в нужные тёмные значения:
      //   {surface.0}   → #000000 (глубокий фон)
      //   {surface.50}  → #272829 (disabled bg — чуть светлее)
      //   {surface.100} → #444547 (Card/Panel bg — канон §5.2; основной фон инпута)
      //   {surface.200} → #616263 (border default)
      //   {surface.300} → #7E7F82 (hover border)
      //   {surface.400} → #9B9C9F (placeholder/icon/muted)
      //   {surface.900} → #F9FAFB (читабельный текст)
      //
      // Токены соответствуют семантике Aura dark, но через нашу инвертированную палитру.
      formField: {
        background: '{surface.100}',           // #444547 — card bg (вместо Aura {surface.950}=white)
        disabledBackground: '{surface.50}',    // #272829 — темнее основного
        filledBackground: '{surface.100}',     // #444547
        filledHoverBackground: '{surface.100}',
        filledFocusBackground: '{surface.100}',
        borderColor: '{surface.200}',          // #616263 — border default
        hoverBorderColor: '{surface.300}',     // #7E7F82
        focusBorderColor: '{primary.color}',   // brand-primary
        invalidBorderColor: '{red.300}',
        color: '{surface.900}',                // #F9FAFB — основной текст
        disabledColor: '{surface.400}',        // #9B9C9F — мuted
        placeholderColor: '{surface.400}',     // #9B9C9F
        invalidPlaceholderColor: '{red.400}',
        floatLabelColor: '{surface.400}',
        floatLabelFocusColor: '{primary.color}',
        floatLabelActiveColor: '{surface.400}',
        floatLabelInvalidColor: '{form.field.invalid.placeholder.color}',
        iconColor: '{surface.400}',            // #9B9C9F
        shadow: '0 0 #0000, 0 0 #0000, 0 1px 2px 0 rgba(0, 0, 0, 0.3)',
      },
    },
  },
  secondary: {
    color: '{surface.700}',
    contrastColor: '{monochrome.white}',
    hoverColor: '{surface.800}',
    activeColor: '{surface.900}',
  },
  success: {
    color: '{primary.500}',
    contrastColor: '{monochrome.white}',
    hoverColor: '{primary.600}',
    activeColor: '{primary.500}',
  },
  danger: {
    color: '{red.500}',
    contrastColor: '{monochrome.white}',
    hoverColor: '{red.600}',
    activeColor: '{red.500}',
  },
  warning: {
    color: '{orange.500}',
    contrastColor: '{monochrome.black}',
    hoverColor: '{orange.600}',
    activeColor: '{orange.500}',
  },
  info: {
    color: '{blue.500}',
    contrastColor: '{monochrome.white}',
    hoverColor: '{blue.600}',
    activeColor: '{blue.500}',
  },
} as const
