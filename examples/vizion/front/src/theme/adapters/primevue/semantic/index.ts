import { primeVueFoundationSemantic } from './foundation'
import { primeVueSurfaceSemantic } from './surfaces'
import { primeVueFormSemantic } from './forms'
import { primeVueButtonSemantic } from './buttons'
import { primeVueDataDisplaySemantic } from './dataDisplay'
import { primeVueNavigationSemantic } from './navigation'
import { primeVueOverlaySemantic } from './overlays'
import { primeVueFeedbackSemantic } from './feedback'

export const primeVueSemantic = {
  ...primeVueFoundationSemantic,
  ...primeVueSurfaceSemantic,
  ...primeVueFormSemantic,
  ...primeVueButtonSemantic,
  ...primeVueDataDisplaySemantic,
  ...primeVueNavigationSemantic,
  ...primeVueOverlaySemantic,
  ...primeVueFeedbackSemantic,
} as const
