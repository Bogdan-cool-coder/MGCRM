"use client";

interface Props {
  rows?: number;
  /** Массив ширин колонок — строки вида "32%", "16%" (используются как style.width). */
  cols: string[];
}

/**
 * Skeleton-заглушка для финансовых таблиц v2.
 * Вставляется непосредственно внутрь <tbody> при isLoading.
 * Использует animate-pulse + prefers-reduced-motion (глобальный CSS).
 */
export function FinTableSkeleton({ rows = 6, cols }: Props) {
  return (
    <>
      {Array.from({ length: rows }).map((_, i) => (
        <tr key={i} className="border-b border-gray-100 dark:border-gray-800">
          {cols.map((w, j) => (
            <td key={j} className="px-4 py-2.5">
              <div
                className="animate-pulse h-4 bg-gray-100 dark:bg-gray-800 rounded"
                style={{ width: w }}
              />
            </td>
          ))}
        </tr>
      ))}
    </>
  );
}
