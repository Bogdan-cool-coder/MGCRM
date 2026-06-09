/**
 * Z-Index система уровней
 * Базовое значение: 1000
 *
 * Раскладка:
 *  - overlay / menu / toolbox — приложение (1000–1200)
 *  - tooltip — НАМЕРЕННО поднят ОЧЕНЬ высоко (11000), выше любого реалистичного
 *    overlay'я приложения. Причина: PrimeVue ZIndex util автоинкрементирует
 *    z-index Popover'ов от baseZIndex, и при открытом MiniChat popover'е
 *    runtime значение уходило до ~3251 — выше прежнего tooltip-клэмпа 2500.
 *    Tooltip ВСЕГДА должен быть верхним слоем (UX-семантика подсказки),
 *    поэтому держим запас на любые будущие overlay'и без переподбора порогов.
 *    Saga: 2026-05-22 → 2500 (мало) → 2026-05-24 → 11000.
 *  - modal — остаётся на 2600. Tooltip над modal — это правильное поведение
 *    (подсказка над модалкой). Если когда-нибудь понадобится Confirm-диалог
 *    поверх tooltip — поднимать modal/top, не трогать tooltip.
 *  - top — escape-hatch для редких слоёв ниже tooltip (выше modal).
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

  // ВНИМАНИЕ: tooltip намеренно выше всех остальных слоёв (включая modal/top).
  // Не понижать без обоснования. См. .p-tooltip clamp в _overlays.scss.
  tooltip: Z_BASE + 10000,
} as const
