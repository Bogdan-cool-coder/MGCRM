import { appTheme } from '@/theme/config'
import { primeVuePrimitive as primitive } from '../primitive'

const { primary: primaryPalette } = appTheme.palette

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
  // ВАЖНО: готча colorScheme — оверрайды должны зеркалить light/dark структуру!
  colorScheme: {
    light: {
      primary: {
        color: '{primary.900}',        // #172747 — brand primary
        contrastColor: '{monochrome.white}',
        hoverColor: '{primary.800}',   // #1f2f5a
        activeColor: '{primary.700}',  // #263a6e
      },
      surface: {
        0: '{surface.0}',
        50: '{surface.50}',
        100: '{surface.100}',
        200: '{surface.200}',
        300: '{surface.300}',
        400: '{surface.400}',
        500: '{surface.500}',
        600: '{surface.600}',
        700: '{surface.700}',
        800: '{surface.800}',
        900: '{surface.900}',
        950: '{surface.950}',
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
        0: '{surface.950}',
        50: '{surface.900}',
        100: '{surface.800}',
        200: '{surface.700}',
        300: '{surface.600}',
        400: '{surface.500}',
        500: '{surface.400}',
        600: '{surface.300}',
        700: '{surface.200}',
        800: '{surface.100}',
        900: '{surface.50}',
        950: '{surface.0}',
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
