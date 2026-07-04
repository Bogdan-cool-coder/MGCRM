import { zIndex } from '@/theme/tokens/zIndex'

// Orbita dock lives BELOW PrimeVue overlays (toolbox=900 < overlay=1000) — see
// theme/tokens/zIndex.ts. Its own popovers ride the PrimeVue overlay tier so they
// float above the dock without manual z-index; this base only needs to clear the
// overlay floor if ever consumed explicitly.
export const ORBITA_LAYER_Z_INDEX = zIndex.toolbox
export const ORBITA_POPOVER_BASE_Z_INDEX = zIndex.overlay + 50
