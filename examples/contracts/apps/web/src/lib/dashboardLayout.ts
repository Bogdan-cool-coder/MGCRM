/**
 * Конфигурация виджетов дашборда (Wave 2b / MARATHON-V2-FIXES #3).
 * Хранится через GET/PUT /api/me/dashboard-config как массив DashboardWidgetConfig[].
 * Поля x, y, w, h — grid-позиция для react-grid-layout (12 колонок).
 * Поля visible, order — обратная совместимость с Wave 2b.
 *
 * v2 (sprint-fixes #2): каждый KPI, статус-тайл и разбивка — отдельный виджет.
 * Старые групповые id (kpi-row, status-tiles, breakdowns) удалены;
 * mergeLayout молча дропает неизвестные id, новые получают дефолтные позиции.
 */

export type DashboardWidgetId =
  // KPI-плитки (было kpi-row)
  | "kpi-total"
  | "kpi-in-review"
  | "kpi-avg-approve"
  | "kpi-avg-cycle"
  | "kpi-deals-no-tasks"
  // Статус-группы (было status-tiles)
  | "status-archive"
  | "status-draft"
  | "status-in-review"
  | "status-approved"
  // Разбивки (было breakdowns)
  | "breakdown-products"
  | "breakdown-countries"
  | "breakdown-managers"
  | "breakdown-statuses"
  // Уже отдельные
  | "my-tasks"
  | "hot-deals"
  | "funnel-conversion"
  | "revenue-forecast"
  | "awaiting-payment"
  | "paid-deals"
  | "hot-forecast";

export interface DashboardWidgetConfig {
  id: DashboardWidgetId;
  visible: boolean;
  order: number;
  /** Grid позиция (react-grid-layout, 12-кол). Опциональны — mergeLayout подставляет defaults. */
  x: number;
  y: number;
  w: number;
  h: number;
}

export interface DashboardWidgetMeta {
  id: DashboardWidgetId;
  label: string;
  icon: string;
  /** Минимальный размер виджета */
  minW?: number;
  minH?: number;
}

/** Все виджеты в порядке по умолчанию (= order). */
export const DASHBOARD_WIDGETS: DashboardWidgetMeta[] = [
  // KPI-ряд — 5 плиток
  { id: "kpi-total",          label: "Всего договоров",              icon: "bi-file-earmark-text",  minW: 2, minH: 2 },
  { id: "kpi-in-review",      label: "На согласовании",              icon: "bi-hourglass-split",    minW: 2, minH: 2 },
  { id: "kpi-avg-approve",    label: "Ср. время согласования",       icon: "bi-clock-history",      minW: 2, minH: 2 },
  { id: "kpi-avg-cycle",      label: "Ср. цикл до подписания",       icon: "bi-graph-up",           minW: 2, minH: 2 },
  { id: "kpi-deals-no-tasks", label: "Сделки без задач",             icon: "bi-clipboard-x",        minW: 2, minH: 2 },
  // Статус-группы — 4 плитки
  { id: "status-archive",     label: "Статус: Архив",                icon: "bi-archive",            minW: 2, minH: 2 },
  { id: "status-draft",       label: "Статус: Черновик",             icon: "bi-file-earmark",       minW: 2, minH: 2 },
  { id: "status-in-review",   label: "Статус: На согласовании",      icon: "bi-hourglass-split",    minW: 2, minH: 2 },
  { id: "status-approved",    label: "Статус: Согласован",           icon: "bi-check-circle",       minW: 2, minH: 2 },
  // Разбивки — 4 блока
  { id: "breakdown-products",  label: "Разбивка по продуктам",       icon: "bi-bar-chart",          minW: 3, minH: 4 },
  { id: "breakdown-countries", label: "Разбивка по странам",         icon: "bi-globe",              minW: 3, minH: 4 },
  { id: "breakdown-managers",  label: "Разбивка по менеджерам",      icon: "bi-person-badge",       minW: 3, minH: 4 },
  { id: "breakdown-statuses",  label: "Разбивка по статусам",        icon: "bi-tags",               minW: 3, minH: 4 },
  // Уже отдельные
  { id: "my-tasks",           label: "Мои задачи",                   icon: "bi-check2-square",      minW: 3, minH: 3 },
  { id: "hot-deals",          label: "Горячие сделки",               icon: "bi-fire",               minW: 3, minH: 3 },
  { id: "funnel-conversion",  label: "Воронка и конверсия",          icon: "bi-funnel",             minW: 4, minH: 3 },
  { id: "revenue-forecast",   label: "Прогноз выручки",             icon: "bi-graph-up-arrow",     minW: 4, minH: 3 },
  { id: "awaiting-payment",   label: "Ожидают оплаты",              icon: "bi-hourglass-split",    minW: 3, minH: 3 },
  { id: "paid-deals",         label: "Оплаченные сделки",           icon: "bi-cash-coin",          minW: 3, minH: 3 },
  { id: "hot-forecast",       label: "Прогноз по горячим",          icon: "bi-thermometer-half",   minW: 3, minH: 3 },
];

/**
 * Default grid-позиции (12 колонок, rowHeight=60, margin=[12,12]).
 *
 * Строки:
 *   y=0   h=2 — 5 KPI-плиток (w=3+3+2+2+2=12, 5 штук: 3+2+2+3+2=12)
 *   y=2   h=2 — 4 статус-тайла (w=3 каждый = 12)
 *   y=4   h=5 — my-tasks (w=6) | hot-deals (w=6)
 *   y=9   h=5 — breakdown-products (w=6) | breakdown-countries (w=6)
 *   y=14  h=5 — breakdown-managers (w=6) | breakdown-statuses (w=6)
 *   y=19  h=5 — funnel-conversion (w=6) | revenue-forecast (w=6)
 *   y=24  h=4 — awaiting-payment (w=4) | paid-deals (w=4) | hot-forecast (w=4)
 */
const DEFAULT_GRID_POSITIONS: Record<DashboardWidgetId, { x: number; y: number; w: number; h: number }> = {
  // KPI row — 5 плиток в ряд (w: 3+2+2+3+2 = 12)
  "kpi-total":           { x: 0,  y: 0,  w: 3, h: 2 },
  "kpi-in-review":       { x: 3,  y: 0,  w: 2, h: 2 },
  "kpi-avg-approve":     { x: 5,  y: 0,  w: 2, h: 2 },
  "kpi-avg-cycle":       { x: 7,  y: 0,  w: 3, h: 2 },
  "kpi-deals-no-tasks":  { x: 10, y: 0,  w: 2, h: 2 },
  // Статус-тайлы — 4 плитки в ряд (w=3 × 4 = 12)
  "status-archive":      { x: 0,  y: 2,  w: 3, h: 2 },
  "status-draft":        { x: 3,  y: 2,  w: 3, h: 2 },
  "status-in-review":    { x: 6,  y: 2,  w: 3, h: 2 },
  "status-approved":     { x: 9,  y: 2,  w: 3, h: 2 },
  // my-tasks / hot-deals
  "my-tasks":            { x: 0,  y: 4,  w: 6, h: 5 },
  "hot-deals":           { x: 6,  y: 4,  w: 6, h: 5 },
  // Разбивки — 2×2 сетка
  "breakdown-products":  { x: 0,  y: 9,  w: 6, h: 5 },
  "breakdown-countries": { x: 6,  y: 9,  w: 6, h: 5 },
  "breakdown-managers":  { x: 0,  y: 14, w: 6, h: 5 },
  "breakdown-statuses":  { x: 6,  y: 14, w: 6, h: 5 },
  // Воронка / прогноз
  "funnel-conversion":   { x: 0,  y: 19, w: 6, h: 5 },
  "revenue-forecast":    { x: 6,  y: 19, w: 6, h: 5 },
  // Нижний ряд
  "awaiting-payment":    { x: 0,  y: 24, w: 4, h: 4 },
  "paid-deals":          { x: 4,  y: 24, w: 4, h: 4 },
  "hot-forecast":        { x: 8,  y: 24, w: 4, h: 4 },
};

export const DEFAULT_LAYOUT: DashboardWidgetConfig[] = DASHBOARD_WIDGETS.map((w, i) => ({
  id: w.id,
  visible: true,
  order: i,
  ...DEFAULT_GRID_POSITIONS[w.id],
}));

const WIDGET_IDS = new Set<string>(DASHBOARD_WIDGETS.map((w) => w.id));

function isWidgetConfig(v: unknown): v is Partial<DashboardWidgetConfig> & { id: DashboardWidgetId; visible: boolean; order: number } {
  return (
    typeof v === "object" &&
    v !== null &&
    typeof (v as { id?: unknown }).id === "string" &&
    WIDGET_IDS.has((v as { id: string }).id) &&
    typeof (v as { visible?: unknown }).visible === "boolean" &&
    typeof (v as { order?: unknown }).order === "number"
  );
}

/**
 * Сливает сохранённый конфиг с DEFAULT_LAYOUT по id.
 * - Новые виджеты (которых не было в сохранённом) добавляются в конец видимыми.
 * - Если у сохранённого виджета нет x/y/w/h (старый формат Wave 2b) —
 *   подставляет дефолтные позиции (миграция без потери порядка).
 * - Невалидные/устаревшие записи (kpi-row, status-tiles, breakdowns и др.)
 *   игнорируются — isWidgetConfig отфильтровывает id не из WIDGET_IDS.
 */
export function mergeLayout(saved: unknown): DashboardWidgetConfig[] {
  const savedList = Array.isArray(saved) ? saved.filter(isWidgetConfig) : [];

  const byId = new Map<string, typeof savedList[number]>();
  for (const item of savedList) byId.set(item.id, item);

  const maxOrder = savedList.reduce((m, x) => Math.max(m, x.order), -1);
  let nextOrder = maxOrder + 1;

  const result: DashboardWidgetConfig[] = DASHBOARD_WIDGETS.map((w) => {
    const found = byId.get(w.id);
    const defaults = DEFAULT_GRID_POSITIONS[w.id];

    if (found) {
      const x = typeof (found as { x?: unknown }).x === "number" ? (found as { x: number }).x : defaults.x;
      const y = typeof (found as { y?: unknown }).y === "number" ? (found as { y: number }).y : defaults.y;
      const ww = typeof (found as { w?: unknown }).w === "number" ? (found as { w: number }).w : defaults.w;
      const h = typeof (found as { h?: unknown }).h === "number" ? (found as { h: number }).h : defaults.h;
      return { id: w.id, visible: found.visible, order: found.order, x, y, w: ww, h };
    }
    return { id: w.id, visible: true, order: nextOrder++, ...defaults };
  });

  result.sort((a, b) => a.order - b.order);
  return result;
}
