/**
 * Z-Index система уровней MG CRM
 * Скопировано 1-в-1 с Vizion (обоснование — в комментарии Vizion zIndex.ts)
 * tooltip намеренно выше всех остальных слоёв (11000).
 */
const Z_BASE = 1000 as const

export const zIndex = {
  bottom: 0,
  base: Z_BASE,
  middle: Z_BASE + 100,

  overlay: Z_BASE,
  menu: Z_BASE + 100,
  toolbox: Z_BASE + 200,
  modal: Z_BASE + 1600,
  top: Z_BASE + 2000,

  tooltip: Z_BASE + 10000,
} as const
