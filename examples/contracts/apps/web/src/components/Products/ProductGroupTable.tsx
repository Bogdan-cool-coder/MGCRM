"use client";

import { formatCurrency } from "@/lib/format";
import type { PricingType, Product } from "@/lib/types";

const CURRENCIES = ["KZT", "UZS", "AED", "USD", "RUB"] as const;

const PRICING_LABEL: Record<PricingType, string> = {
  fixed: "Фиксированная (годовая)",
  tiered: "Тарифы",
  per_minute: "Поминутная",
  package: "Пакеты",
  custom: "Под проект / договорная",
};

function priceFor(p: Product, currency: string): string {
  const pr = p.prices.find((x) => x.currency === currency && x.plan_id == null);
  // Символ валюты уже в заголовке колонки — отображаем только число
  return pr ? formatCurrency(pr.amount, null) : "—";
}

interface ProductGroupTableProps {
  groups: [string, Product[]][];
  onEdit: (p: Product) => void;
  onToggleActive: (p: Product) => void;
  onDelete: (p: Product) => void;
}

export function ProductGroupTable({ groups, onEdit, onToggleActive, onDelete }: ProductGroupTableProps) {
  return (
    <div className="card overflow-x-auto p-0">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b border-gray-200 dark:border-gray-700 text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
            <th className="px-4 py-3 font-semibold">Название</th>
            <th className="px-4 py-3 font-semibold">Тип цены</th>
            {CURRENCIES.map((c) => (
              <th key={c} className="px-4 py-3 font-semibold text-right whitespace-nowrap">{c}</th>
            ))}
            <th className="px-4 py-3 font-semibold text-center">Активен</th>
            <th className="px-4 py-3 font-semibold text-right">Действия</th>
          </tr>
        </thead>
        <tbody>
          {groups.map(([group, items]) => (
            <ProductGroupSection
              key={group}
              group={group}
              items={items}
              onEdit={onEdit}
              onToggleActive={onToggleActive}
              onDelete={onDelete}
            />
          ))}
        </tbody>
      </table>
    </div>
  );
}

function ProductGroupSection({
  group, items, onEdit, onToggleActive, onDelete,
}: { group: string; items: Product[]; onEdit: (p: Product) => void; onToggleActive: (p: Product) => void; onDelete: (p: Product) => void }) {
  const colCount = 4 + CURRENCIES.length;
  return (
    <>
      <tr className="bg-gray-50 dark:bg-gray-900/50 border-b border-gray-200 dark:border-gray-700">
        <td colSpan={colCount} className="px-4 py-2 font-semibold text-gray-700 dark:text-gray-200">
          {group}
          <span className="ml-2 text-xs font-normal text-gray-400">{items.length}</span>
        </td>
      </tr>
      {items.map((p) => (
        <tr
          key={p.id}
          className={`border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-900/40 ${p.is_active ? "" : "opacity-50"}`}
        >
          <td className="px-4 py-2.5">
            <div className="font-medium text-gray-900 dark:text-gray-100">{p.name}</div>
            {p.plans.length > 0 && (
              <div className="text-xs text-gray-400">тарифов: {p.plans.length}</div>
            )}
          </td>
          <td className="px-4 py-2.5 text-gray-600 dark:text-gray-400 whitespace-nowrap">
            {PRICING_LABEL[p.pricing_type] ?? p.pricing_type}
          </td>
          {CURRENCIES.map((c) => {
            const v = priceFor(p, c);
            return (
              <td key={c} className={`px-4 py-2.5 text-right whitespace-nowrap ${v === "—" ? "text-gray-300 dark:text-gray-600" : "text-gray-700 dark:text-gray-300"}`}>
                {v}
              </td>
            );
          })}
          <td className="px-4 py-2.5 text-center">
            <button onClick={() => onToggleActive(p)} className="btn-ghost text-xs" title={p.is_active ? "Деактивировать" : "Активировать"}>
              <i className={p.is_active ? "bi bi-toggle-on text-success text-base" : "bi bi-toggle-off text-base"} />
            </button>
          </td>
          <td className="px-4 py-2.5 text-right whitespace-nowrap">
            <button onClick={() => onEdit(p)} className="btn-ghost text-xs" title="Редактировать"><i className="bi bi-pencil" /></button>
            <button onClick={() => onDelete(p)} className="btn-ghost text-xs text-danger" title="Удалить"><i className="bi bi-trash" /></button>
          </td>
        </tr>
      ))}
    </>
  );
}
