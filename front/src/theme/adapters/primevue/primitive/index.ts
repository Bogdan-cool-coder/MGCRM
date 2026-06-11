import { appTheme } from '@/theme/config'
import { primeVueColors } from './colors'

export const primeVuePrimitive = {
  transitionDuration: appTheme.motion.duration.fast,
  borderRadius: {
    sm: appTheme.radius.sm,
    md: appTheme.radius.md,
    lg: appTheme.radius.lg,
  },
  focusRing: {
    width: '2px',
    style: 'solid',
    color: '{primary.400}',
    offset: '2px',
  },
  ...primeVueColors,
} as const
