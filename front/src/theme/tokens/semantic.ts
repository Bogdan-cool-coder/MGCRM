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
    page: surfacePalette[100],    // #F1F2F3 — page background
    card: monochromePalette.white, // #FFFFFF
    overlay: monochromePalette.white,
    muted: surfacePalette[50],
    hover: surfacePalette[200],
    panel: surfacePalette[100],
  },
  text: {
    primary: surfacePalette[900],   // #272829
    secondary: surfacePalette[700], // #616263
    muted: surfacePalette[600],     // #7E7F82
    inverse: monochromePalette.white,
  },
  border: {
    default: surfacePalette[200],  // #E3E4E6
    strong: surfacePalette[300],   // #D5D6D8
    focus: primaryPalette[900],    // #172747
    accent: primaryPalette[400],   // #6f87bc
    danger: redPalette[500],       // #FF5A44
  },
  status: {
    success: {
      bg: greenPalette[100],    // #dcfadc
      border: greenPalette[300], // #93eba4
      text: greenPalette[900],  // #2b6b38
      solid: greenPalette[500], // #A7EFAA
    },
    danger: {
      bg: redPalette[50],       // #fff5f4
      border: redPalette[200],  // #ffd1cd
      text: redPalette[700],    // #e61c14
      solid: redPalette[500],   // #FF5A44
    },
    warning: {
      bg: orangePalette[100],    // #fff0e8
      border: orangePalette[300], // #ffc8ad
      text: orangePalette[900],  // #9b4029
      solid: orangePalette[500], // #FFB38A
    },
    info: {
      bg: bluePalette[100],    // #e8f4ff
      border: bluePalette[300], // #a3d5ff
      text: bluePalette[900],  // #22589b
      solid: bluePalette[500], // #8DD9FF
    },
    // Deal/Pipeline статусы
    deal: {
      new: { bg: bluePalette[100], text: bluePalette[900] },
      active: { bg: primaryPalette[100], text: primaryPalette[900] },
      won: { bg: greenPalette[100], text: greenPalette[900] },
      hot: { bg: orangePalette[100], text: orangePalette[900] },
      lost: { bg: redPalette[50], text: redPalette[700] },
      neutral: { bg: surfacePalette[200], text: surfacePalette[800] },
    },
    // Contract статусы
    contract: {
      draft: { bg: bluePalette[100], text: bluePalette[900] },
      review: { bg: orangePalette[100], text: orangePalette[900] },
      signed: { bg: greenPalette[100], text: greenPalette[900] },
      expired: { bg: redPalette[50], text: redPalette[700] },
    },
  },
  action: {
    primary: {
      bg: primaryPalette[500],    // #4a67a3
      hover: primaryPalette[600], // #334d8a
      active: primaryPalette[700], // #263a6e
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
    placeholder: surfacePalette[500],
    errorBorder: redPalette[500],
    errorBg: redPalette[50],
    disabledBg: surfacePalette[100],
    disabledBorder: surfacePalette[400],
    disabledText: surfacePalette[500],
  },
  radius,
  shadow: shadows,
  motion,
  layout,
  typography,
  z: zIndex,
} as const
