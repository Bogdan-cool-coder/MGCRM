/**
 * Z-Index система уровней MG CRM
 * Скопировано 1-в-1 с Vizion (обоснование — в комментарии Vizion zIndex.ts)
 * tooltip намеренно выше всех остальных слоёв (11000).
 *
 * ШКАЛА (audit L1): плавающий Orbita-док (toolbox) должен быть НИЖЕ всех
 * интерактивных PrimeVue-оверлеев (overlay 1000 / menu 1100 / modal 2600), иначе
 * выпадашки пагинатора и kebab-меню нижних строк рисуются ПОД доком. Док —
 * персистентный хром-виджет, легитимной причины перекрывать оверлеи у него нет.
 * Поэтому toolbox = 900 (ниже overlay). Собственные попапы дока (флайаут
 * уведомлений, командная палитра) рендерятся PrimeVue-оверлеями на 1000+ и
 * штатно оказываются НАД доком без ручных z-index.
 */
const Z_BASE = 1000 as const

export const zIndex = {
  bottom: 0,
  base: Z_BASE,
  middle: Z_BASE + 100,

  // Плавающий док — ниже overlay (1000), выше sticky/page-контента (макс ~200).
  toolbox: 900,

  overlay: Z_BASE,
  menu: Z_BASE + 100,
  modal: Z_BASE + 1600,
  top: Z_BASE + 2000,

  tooltip: Z_BASE + 10000,
} as const
