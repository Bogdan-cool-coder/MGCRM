"use client";

import useSWR from "swr";
import { fetcher } from "@/lib/api";
import { formatCurrency } from "@/lib/format";
import type { FinCashflowCategory } from "@/lib/types";

export interface AllocationLine {
  cashflow_category_id: string;
  amount: string;
}

interface Props {
  lines: AllocationLine[];
  onChange: (lines: AllocationLine[]) => void;
  totalAmount: number;
  currency: string;
  flowHint?: "inflow" | "outflow";
}

export function AllocationEditor({ lines, onChange, totalAmount, currency, flowHint }: Props) {
  const { data: cats } = useSWR<FinCashflowCategory[]>("/api/finance/categories", fetcher);

  // flowHint (inflow|outflow) → category.direction (inflow|outflow|both).
  const filteredCats = cats?.filter((c) => {
    if (!flowHint) return true;
    if (flowHint === "inflow") return c.direction === "inflow" || c.direction === "both";
    return c.direction === "outflow" || c.direction === "both";
  }) ?? [];

  const sum = lines.reduce((acc, l) => acc + (parseFloat(l.amount) || 0), 0);
  const balanced = Math.abs(sum - totalAmount) < 0.01;

  function addLine() {
    onChange([...lines, { cashflow_category_id: "", amount: "" }]);
  }

  function removeLine(idx: number) {
    onChange(lines.filter((_, i) => i !== idx));
  }

  function updateLine(idx: number, field: keyof AllocationLine, value: string) {
    const next = lines.map((l, i) => (i === idx ? { ...l, [field]: value } : l));
    onChange(next);
  }

  return (
    <div className="space-y-2">
      {lines.map((line, idx) => (
        <div key={idx} className="flex items-center gap-2">
          <div className="flex-1">
            <select
              className="input text-sm"
              value={line.cashflow_category_id}
              onChange={(e) => updateLine(idx, "cashflow_category_id", e.target.value)}
            >
              <option value="">Выберите статью…</option>
              {filteredCats.map((c) => (
                <option key={c.id} value={String(c.id)}>{c.name}</option>
              ))}
            </select>
          </div>
          <div className="w-32">
            <input
              type="number"
              min="0.01"
              step="0.01"
              className="input text-sm"
              placeholder="Сумма"
              value={line.amount}
              onChange={(e) => updateLine(idx, "amount", e.target.value)}
            />
          </div>
          <button
            type="button"
            onClick={() => removeLine(idx)}
            className="text-gray-400 hover:text-danger p-1"
            title="Удалить строку"
          >
            <i className="bi bi-trash text-sm" />
          </button>
        </div>
      ))}

      <button
        type="button"
        onClick={addLine}
        className="text-sm text-primary hover:underline flex items-center gap-1"
      >
        <i className="bi bi-plus" /> Добавить строку
      </button>

      <div className={`flex items-center gap-2 px-3 py-2 rounded-md text-sm mt-1 ${balanced ? "bg-green-50 dark:bg-green-900/20 text-success" : "bg-red-50 dark:bg-red-900/20 text-danger"}`}>
        <span>Разнесено: {formatCurrency(sum, currency)} из {formatCurrency(totalAmount, currency)}</span>
        {balanced && <i className="bi bi-check-circle ml-auto" />}
      </div>
    </div>
  );
}
