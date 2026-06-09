"use client";

/**
 * RegistryTableSkeleton — 8 строк-заглушек с animate-pulse для состояния загрузки
 * таблицы реестра клиентов. Имитирует реальные пропорции колонок.
 */

const COLUMN_WIDTHS = [
  "w-8",      // checkbox
  "w-36",     // клиент (широкая)
  "w-24",     // продукты
  "w-24",     // страна и регион
  "w-8",      // кат.
  "w-20",     // прогресс сегменты
  "w-16",     // активность
  "w-12",     // здоровье
  "w-20",     // абонентка
];

function SkeletonRow({ withCheckbox }: { withCheckbox: boolean }) {
  return (
    <tr className="border-b border-gray-100 dark:border-gray-800">
      {withCheckbox && (
        <td className="px-3 py-2 text-center">
          <div className="animate-pulse h-4 w-4 bg-gray-100 dark:bg-gray-800 rounded mx-auto" />
        </td>
      )}
      {COLUMN_WIDTHS.slice(1).map((w, i) => (
        <td key={i} className="px-3 py-2">
          <div className={`animate-pulse h-4 bg-gray-100 dark:bg-gray-800 rounded ${w}`} />
        </td>
      ))}
    </tr>
  );
}

interface RegistryTableSkeletonProps {
  rows?: number;
  withCheckbox?: boolean;
}

export function RegistryTableSkeleton({
  rows = 8,
  withCheckbox = false,
}: RegistryTableSkeletonProps) {
  return (
    <>
      {Array.from({ length: rows }).map((_, i) => (
        <SkeletonRow key={i} withCheckbox={withCheckbox} />
      ))}
    </>
  );
}
