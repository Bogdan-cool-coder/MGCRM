"use client";

/**
 * InvoiceLineEditor — редактор позиций для инвойсов, актов и вендор-счетов.
 *
 * ВАЖНО по расчёту НДС:
 *   VatRateOut.rate_pct — ПРОЦЕНТ (например, 20 = 20%).
 *   Сервер сам считает amount_net/vat_amount/amount_gross из qty×unit_price + rate.
 *   Фронт только показывает предварительный расчёт (preview), реальные итоги
 *   возвращает сервер.
 *   Preview: vat = qty * unit_price * (rate_pct / 100)
 */

import { useState, useEffect } from "react";
import useSWR from "swr";
import { fetcher } from "@/lib/api";
import type { FinVatRate } from "@/lib/types";
import { formatAmount } from "./MoneyCell";

export interface LineFormData {
  /** Стабильный client-side id для key в списке (не отправляется на сервер) */
  _id?: string;
  name: string;
  qty: string;
  unit_price: string;
  vat_rate_id: number | null;
  sort_order: number;
  // Optional fields (used by invoice/vendor-bill)
  revenue_account_code?: string;
  expense_account_code?: string;
  cashflow_category_id?: number | null;
}

function emptyLine(sort_order: number): LineFormData {
  return {
    _id: crypto.randomUUID(),
    name: "",
    qty: "1",
    unit_price: "0",
    vat_rate_id: null,
    sort_order,
  };
}

interface LinePreview {
  net: number;
  vat: number;
  gross: number;
}

function calcPreview(line: LineFormData, vatRates: FinVatRate[]): LinePreview {
  const qty = parseFloat(line.qty) || 0;
  const price = parseFloat(line.unit_price) || 0;
  const net = qty * price;
  const rate = line.vat_rate_id
    ? vatRates.find((r) => r.id === line.vat_rate_id)?.rate_pct
    : null;
  const ratePct = rate != null ? parseFloat(String(rate)) : 0;
  const vat = net * (ratePct / 100);
  return { net, vat, gross: net + vat };
}

interface Props {
  lines: LineFormData[];
  onChange: (lines: LineFormData[]) => void;
  /** Показывать колонку «Счёт дохода/расхода» */
  showAccountCode?: boolean;
  accountCodeLabel?: string;
  accountCodeField?: "revenue_account_code" | "expense_account_code";
}

export function InvoiceLineEditor({
  lines,
  onChange,
  showAccountCode = false,
  accountCodeLabel = "Счёт",
  accountCodeField = "revenue_account_code",
}: Props) {
  const { data: vatRates = [] } = useSWR<FinVatRate[]>("/api/finance/vat-rates", fetcher);

  function updateLine(idx: number, patch: Partial<LineFormData>) {
    const next = lines.map((l, i) => (i === idx ? { ...l, ...patch } : l));
    onChange(next);
  }

  function addLine() {
    onChange([...lines, emptyLine(lines.length)]);
  }

  function removeLine(idx: number) {
    if (lines.length <= 1) return;
    onChange(lines.filter((_, i) => i !== idx).map((l, i) => ({ ...l, sort_order: i })));
  }

  const totals = lines.reduce(
    (acc, l) => {
      const p = calcPreview(l, vatRates);
      return { net: acc.net + p.net, vat: acc.vat + p.vat, gross: acc.gross + p.gross };
    },
    { net: 0, vat: 0, gross: 0 }
  );

  return (
    <div>
      <div className="overflow-x-auto">
        <table className="w-full text-sm border-collapse">
          <thead>
            <tr className="bg-gray-50 dark:bg-gray-800 text-gray-500 dark:text-gray-400 text-xs">
              <th className="text-left px-3 py-2 font-medium">Наименование</th>
              <th className="text-right px-3 py-2 font-medium w-20">Кол-во</th>
              <th className="text-right px-3 py-2 font-medium w-28">Цена</th>
              <th className="text-left px-3 py-2 font-medium w-28">Ставка НДС</th>
              <th className="text-right px-3 py-2 font-medium w-28">Без НДС</th>
              <th className="text-right px-3 py-2 font-medium w-24">НДС</th>
              <th className="text-right px-3 py-2 font-medium w-28">Итого</th>
              {showAccountCode && (
                <th className="text-left px-3 py-2 font-medium w-24">{accountCodeLabel}</th>
              )}
              <th className="w-8" />
            </tr>
          </thead>
          <tbody>
            {lines.map((line, idx) => {
              const preview = calcPreview(line, vatRates);
              return (
                <tr
                  key={line._id ?? idx}
                  className="border-b border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800/50"
                >
                  <td className="px-2 py-1.5">
                    <input
                      type="text"
                      className="input text-sm w-full min-w-[160px]"
                      placeholder="Наименование товара / услуги"
                      value={line.name}
                      onChange={(e) => updateLine(idx, { name: e.target.value })}
                    />
                  </td>
                  <td className="px-2 py-1.5">
                    <input
                      type="number"
                      className="input text-sm text-right w-20"
                      min="0"
                      step="0.001"
                      value={line.qty}
                      onChange={(e) => updateLine(idx, { qty: e.target.value })}
                    />
                  </td>
                  <td className="px-2 py-1.5">
                    <input
                      type="number"
                      className="input text-sm text-right w-28"
                      min="0"
                      step="0.01"
                      value={line.unit_price}
                      onChange={(e) => updateLine(idx, { unit_price: e.target.value })}
                    />
                  </td>
                  <td className="px-2 py-1.5">
                    <select
                      className="input text-sm w-28"
                      value={line.vat_rate_id ?? ""}
                      onChange={(e) =>
                        updateLine(idx, {
                          vat_rate_id: e.target.value ? parseInt(e.target.value) : null,
                        })
                      }
                    >
                      <option value="">Без НДС</option>
                      {vatRates.map((r) => (
                        <option key={r.id} value={r.id}>
                          {r.name} ({parseFloat(String(r.rate_pct))}%)
                        </option>
                      ))}
                    </select>
                  </td>
                  <td className="px-3 py-1.5 text-right tabular-nums text-gray-600 dark:text-gray-300">
                    {formatAmount(preview.net)}
                  </td>
                  <td className="px-3 py-1.5 text-right tabular-nums text-gray-500 dark:text-gray-400 text-xs">
                    {formatAmount(preview.vat)}
                  </td>
                  <td className="px-3 py-1.5 text-right tabular-nums font-medium text-gray-900 dark:text-gray-100">
                    {formatAmount(preview.gross)}
                  </td>
                  {showAccountCode && (
                    <td className="px-2 py-1.5">
                      <input
                        type="text"
                        className="input text-sm w-24"
                        maxLength={8}
                        placeholder="4030"
                        value={line[accountCodeField] ?? ""}
                        onChange={(e) =>
                          updateLine(idx, { [accountCodeField]: e.target.value || undefined })
                        }
                      />
                    </td>
                  )}
                  <td className="px-2 py-1.5">
                    <button
                      type="button"
                      className="btn-ghost text-danger text-xs px-1.5 py-1 disabled:opacity-30"
                      disabled={lines.length <= 1}
                      onClick={() => removeLine(idx)}
                      title="Удалить позицию"
                    >
                      <i className="bi bi-x-lg" />
                    </button>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      <div className="flex items-center justify-between mt-3">
        <button type="button" className="btn-secondary text-sm" onClick={addLine}>
          <i className="bi bi-plus mr-1" />
          Добавить позицию
        </button>

        <div className="flex gap-6 text-sm text-right">
          <span className="text-gray-500 dark:text-gray-400">
            Без НДС:{" "}
            <span className="tabular-nums font-medium text-gray-800 dark:text-gray-200">
              {formatAmount(totals.net)}
            </span>
          </span>
          <span className="text-gray-500 dark:text-gray-400">
            НДС:{" "}
            <span className="tabular-nums font-medium text-gray-800 dark:text-gray-200">
              {formatAmount(totals.vat)}
            </span>
          </span>
          <span className="text-gray-700 dark:text-gray-300 font-semibold">
            Итого:{" "}
            <span className="tabular-nums text-gray-900 dark:text-gray-100">
              {formatAmount(totals.gross)}
            </span>
          </span>
        </div>
      </div>
      <p className="text-xs text-gray-400 dark:text-gray-500 mt-1">
        Предварительный расчёт. Точные суммы рассчитает сервер.
      </p>
    </div>
  );
}
