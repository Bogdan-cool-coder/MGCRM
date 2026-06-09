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
  colorScheme: {
    light: {
      primary: {
        color: '{primary.900}',
        contrastColor: '{monochrome.white}',
        hoverColor: '{primary.800}',
        activeColor: '{primary.700}',
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
