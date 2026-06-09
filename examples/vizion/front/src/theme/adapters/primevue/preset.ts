import Aura from '@primevue/themes/aura'
import { definePreset } from '@primevue/themes'
import { zIndex as primeVueZIndex } from '@/theme/tokens/zIndex'
import { primeVuePrimitive } from './primitive'
import { primeVueSemantic } from './semantic'

export { primeVueZIndex }

export const primeVuePreset = definePreset(Aura, {
  primitive: primeVuePrimitive,
  semantic: primeVueSemantic,
})
