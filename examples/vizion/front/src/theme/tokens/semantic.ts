import {
  bluePalette,
  commonColors,
  greenPalette,
  monochromePalette,
  orangePalette,
  primaryPalette,
  redPalette,
  surfacePalette,
} from './colors'
import { radius } from './radius'
import { shadows } from './shadows'
import { motion } from './motion'
import { layout } from './layout'
import { typography } from './typography'
import { zIndex } from './zIndex'

export const semantic = {
  brand: {
    primary: commonColors.primary,
    primaryDark: commonColors.primaryDark,
    primaryLight: commonColors.primaryLight,
    secondary: commonColors.secondary,
    secondaryDark: commonColors.secondaryDark,
    secondaryLight: commonColors.secondaryLight,
  },
  surface: {
    page: surfacePalette[100],
    card: monochromePalette.white,
    overlay: monochromePalette.white,
    muted: surfacePalette[50],
    hover: surfacePalette[200],
    panel: surfacePalette[100],
  },
  text: {
    primary: surfacePalette[900],
    secondary: surfacePalette[700],
    muted: surfacePalette[600],
    inverse: monochromePalette.white,
  },
  border: {
    default: surfacePalette[200],
    strong: surfacePalette[300],
    focus: primaryPalette[900],
    accent: primaryPalette[400],
    danger: redPalette[500],
  },
  status: {
    success: {
      bg: greenPalette[100],
      border: greenPalette[300],
      text: greenPalette[900],
      solid: greenPalette[500],
    },
    danger: {
      bg: redPalette[50],
      border: redPalette[200],
      text: redPalette[700],
      solid: redPalette[500],
    },
    warning: {
      bg: orangePalette[100],
      border: orangePalette[300],
      text: orangePalette[900],
      solid: orangePalette[500],
    },
    info: {
      bg: bluePalette[100],
      border: bluePalette[300],
      text: bluePalette[900],
      solid: bluePalette[500],
    },
  },
  action: {
    primary: {
      bg: primaryPalette[500],
      hover: primaryPalette[600],
      active: primaryPalette[700],
      text: monochromePalette.white,
    },
    secondary: {
      bg: surfacePalette[700],
      hover: surfacePalette[800],
      active: surfacePalette[900],
      text: monochromePalette.white,
    },
    danger: {
      bg: redPalette[500],
      hover: redPalette[600],
      active: redPalette[500],
      text: monochromePalette.white,
    },
  },
  input: {
    bg: surfacePalette[0],
    border: surfacePalette[300],
    hoverBorder: primaryPalette[400],
    focusBorder: primaryPalette[900],
    text: surfacePalette[900],
  },
  radius,
  shadow: shadows,
  motion,
  layout,
  typography,
  z: zIndex,
} as const
