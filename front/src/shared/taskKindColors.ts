/**
 * Единая карта вид задачи → акцентный цвет.
 *
 * Источник: брендбук MACRO Global + spec DealCard §11 / EntityComposer §9.
 * Эти цвета — бренд-акценты (call=синий, meeting=зелёный, follow_up=янтарный),
 * NOT surface/status-токены — поэтому хардкод допустим здесь, а не в компонентах.
 *
 * Используется во всех местах где нужен typeChipStyle / subtype color:
 *   - EntityComposer.vue
 *   - DealComposer.vue
 *   - TaskExpandedPanel.vue
 *   - OpenTasksList.vue
 *   - TasksKanbanBoard.vue (TASK_BUCKET_COLORS)
 */

import type { ActivityKind, MyBoardBucket } from '@/entities/activity'

// stylelint не видит TS-файлы — hex здесь оправданы как branded constants
export const TASK_KIND_COLORS: Partial<Record<ActivityKind, string>> = {
  task: '#172747', // brand primary — бренд-инвариант
  call: '#2A6FDB', // brand blue
  meeting: '#1F8A5B', // brand green
  follow_up: '#E8A317', // brand amber
  presentation: '#E8A317', // same amber
}

/** Fallback — brand primary */
export const TASK_KIND_COLOR_DEFAULT = '#172747'

/**
 * Возвращает inline-стиль для type-chip (chip background = tint + foreground).
 *
 * В light: фон = tint 14% brand-цвета в белый; текст = brand-цвет (насыщенный, WCAG AA).
 * В dark:  фон = tint 18% brand-цвета в surface-100 (#444547); текст = light-вариант
 *          brand-цвета (55% white + 45% brand-hex) — читаем на тёмном tint'е.
 *
 * @param kind    - вид задачи
 * @param isDark  - true если активна тёмная тема (передаётся из useThemeStore)
 */
export function taskKindChipStyle(
  kind: ActivityKind | null | undefined,
  isDark = false,
): Record<string, string> {
  const color = (kind && TASK_KIND_COLORS[kind]) ?? null
  if (!color) {
    return {
      background: isDark ? 'var(--p-surface-200)' : 'var(--p-surface-100)',
      color: isDark ? 'var(--p-surface-400)' : 'var(--p-surface-500)',
    }
  }
  if (isDark) {
    // Dark surface-100 = #444547; mix brand hex into it for tinted dark background.
    // Text = lighten brand hex 55% toward white for readable contrast.
    return {
      // stylelint-disable-next-line scale-unlimited/declaration-strict-value
      background: `color-mix(in srgb, ${color} 18%, #444547)`,
      // stylelint-disable-next-line scale-unlimited/declaration-strict-value
      color: `color-mix(in srgb, white 55%, ${color})`,
    }
  }
  return {
    background: `color-mix(in srgb, ${color} 14%, white)`,
    color: color,
  }
}

/**
 * Возвращает цвет для subtype-пикера (иконки/метки) в composer-компонентах.
 * Совместим с SubtypeOption.color.
 */
export function taskKindColor(kind: ActivityKind | null | undefined): string {
  return (kind && TASK_KIND_COLORS[kind]) ?? TASK_KIND_COLOR_DEFAULT
}

/**
 * Цвета канбан-колонок задач (bucket → hex).
 * Используются в TasksKanbanBoard через CSS custom property --bucket-color.
 */
export const TASK_BUCKET_COLORS: Record<MyBoardBucket, string> = {
  overdue: '#FF5A44', // color-danger — brand signal
  today: '#EF9F27', // brand amber
  tomorrow: '#378ADD', // brand blue
  this_week: '#7F77DD', // brand violet
  next_week: '#1D9E75', // brand teal
  later: '#6B7280', // neutral gray
}
