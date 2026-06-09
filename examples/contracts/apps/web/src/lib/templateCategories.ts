/**
 * Эпик 3: справочник категорий шаблонов договоров.
 *
 * Источник истины — backend `TEMPLATE_CATEGORIES` в `apps/api/app/services/templates.py`.
 * Здесь хардкодим визуальные атрибуты (label/icon/cls), чтобы UI работал без сетевого
 * запроса. Если backend добавит новую категорию — придётся синхронизировать руками,
 * fallback на «Без категории» гарантирует, что UI не упадёт.
 */

export type TemplateCategory =
  | "sublicense_main"
  | "addendum"
  | "notice"
  | "act"
  | "cancellation";

export interface CategoryDisplay {
  label: string;
  icon: string;
  cls: string;
}

export const TEMPLATE_CATEGORIES: Record<TemplateCategory, CategoryDisplay> = {
  sublicense_main: {
    label: "Основной договор",
    icon: "bi-file-earmark-text",
    cls: "bg-primary-light/10 text-primary",
  },
  addendum: {
    label: "Доп. соглашение",
    icon: "bi-file-earmark-plus",
    cls: "bg-info-50 text-info-700 dark:bg-info-500/10 dark:text-info-500",
  },
  notice: {
    label: "Уведомление",
    icon: "bi-megaphone",
    cls: "bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-500",
  },
  act: {
    label: "Акт",
    icon: "bi-clipboard-check",
    cls: "bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-500",
  },
  cancellation: {
    label: "Расторжение",
    icon: "bi-file-earmark-x",
    cls: "bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-500",
  },
};

export const NULL_CATEGORY: CategoryDisplay = {
  label: "Без категории",
  icon: "bi-file-earmark",
  cls: "bg-gray-100 text-gray-600",
};

export const TEMPLATE_CATEGORY_ORDER: TemplateCategory[] = [
  "sublicense_main",
  "addendum",
  "notice",
  "act",
  "cancellation",
];

/** Категории, которые считаются «основными» для top-level tab «Основные» на /admin/templates. */
export const MAIN_CATEGORIES: ReadonlySet<TemplateCategory> = new Set([
  "sublicense_main",
  "addendum",
]);

export function getCategoryDisplay(category: string | null | undefined): CategoryDisplay {
  if (!category) return NULL_CATEGORY;
  return TEMPLATE_CATEGORIES[category as TemplateCategory] ?? NULL_CATEGORY;
}

export function isMainCategory(category: string | null | undefined): boolean {
  if (!category) return false;
  return MAIN_CATEGORIES.has(category as TemplateCategory);
}
