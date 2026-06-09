"use client";

import { getCategoryDisplay } from "@/lib/templateCategories";

interface Props {
  category: string | null | undefined;
  /** Если true — без рамки/иконки, только цветной dot + текст (для компактных строк). */
  compact?: boolean;
}

/**
 * Эпик 3: badge категории шаблона (Основной договор / Доп. соглашение / ...).
 *
 * НЕ путать с `@/components/CategoryBadge` — там badge для клиентской категории
 * (A1/B1/L/M/S). Этот живёт в подпапке Templates/ намеренно.
 */
export function CategoryBadge({ category, compact = false }: Props) {
  const d = getCategoryDisplay(category);
  if (compact) {
    return (
      <span className="inline-flex items-center gap-1.5 text-xs text-gray-700">
        <i className={`bi ${d.icon} text-sm`} />
        {d.label}
      </span>
    );
  }
  return (
    <span
      className={`inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium ${d.cls}`}
    >
      <i className={`bi ${d.icon}`} />
      {d.label}
    </span>
  );
}
