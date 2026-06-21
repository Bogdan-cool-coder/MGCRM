import {
  bluePalette,
  commonColors,
  colors,
  greenPalette,
  monochromePalette,
  orangePalette,
  primaryPalette,
  redPalette,
  surfacePalette,
  tealPalette,
} from './tokens/colors'
import { layout } from './tokens/layout'
import { motion } from './tokens/motion'
import { radius } from './tokens/radius'
import { semantic } from './tokens/semantic'
import { shadows } from './tokens/shadows'
import { spacing } from './tokens/spacing'
import { typography } from './tokens/typography'
import { zIndex } from './tokens/zIndex'

export const appTheme = {
  palette: {
    primary: primaryPalette,
    surface: surfacePalette,
    red: redPalette,
    orange: orangePalette,
    blue: bluePalette,
    green: greenPalette,
    teal: tealPalette,
    monochrome: monochromePalette,
  },
  common: commonColors,
  colors,
  semantic,
  radius,
  shadows,
  motion,
  spacing,
  layout,
  typography,
  zIndex,
} as const
