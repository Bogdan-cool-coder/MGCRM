// PrimeVue 4.5 — @primeuix/themes
import Aura from '@primeuix/themes/aura'
import { definePreset } from '@primeuix/themes'
import { zIndex as primeVueZIndex } from '@/theme/tokens/zIndex'
import { primeVuePrimitive } from './primitive'
import { primeVueSemantic } from './semantic'

export { primeVueZIndex }

/**
 * MG CRM PrimeVue preset.
 * Переопределяем ТОЛЬКО нужное поверх Aura.
 * options в main.ts: { prefix: 'p', darkModeSelector: '.app-dark', cssLayer: true }
 */
export const MgCrmPreset = definePreset(Aura, {
  primitive: primeVuePrimitive,
  semantic: primeVueSemantic,
})
