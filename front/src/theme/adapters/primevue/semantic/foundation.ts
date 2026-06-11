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
