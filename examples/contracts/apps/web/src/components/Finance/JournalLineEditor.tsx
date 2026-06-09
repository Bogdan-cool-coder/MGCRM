"use client";

import useSWR from "swr";
import { CurrencySelect } from "@/components/Currency/CurrencySelect";
import { formatAmount } from "./MoneyCell";
import { fetcher } from "@/lib/api";
import type { FinAccountGl, FinManualJournalLine } from "@/lib/types";

export type JournalLineDraft = {
  _key: string;
  account_gl_id: number | null;
  side: "dt" | "kt";
  amount: string;
  currency: string;
  counterparty_company_id: number | null;
  comment: string;
};

interface Props {
  lines: JournalLineDraft[];
  onChange: (lines: JournalLineDraft[]) => void;
  funcCurrency: string;
  readOnly?: boolean;
}

interface GlOption {
  id: number;
  code: string;
  name: string;
  requires_counterparty: boolean;
}

interface CounterpartyOption {
  id: number;
  name: string;
}

function newLine(): JournalLineDraft {
  return {
    _key: Math.random().toString(36).slice(2),
    account_gl_id: null,
    side: "dt",
    amount: "",
    currency: "RUB",
    counterparty_company_id: null,
    comment: "",
  };
}

export function makeInitialLines(): JournalLineDraft[] {
  return [
    { ...newLine(), side: "dt" },
    { ...newLine(), side: "kt" },
  ];
}

export function journalLinesToPayload(lines: JournalLineDraft[]): Omit<FinManualJournalLine, "id" | "account_gl">[] {
  return lines.map((l) => ({
    account_gl_id: l.account_gl_id ?? 0,
    side: l.side,
    amount: parseFloat(l.amount) || 0,
    currency: l.currency,
    counterparty_company_id: l.counterparty_company_id,
    comment: l.comment || null,
  }));
}

export function JournalLineEditor({ lines, onChange, funcCurrency, readOnly = false }: Props) {
  const { data: glAccounts } = useSWR<GlOption[]>("/api/finance/chart-of-accounts", fetcher);
  const { data: counterparties } = useSWR<CounterpartyOption[]>("/api/companies?limit=300", fetcher);

  // Compute balance
  const dtSum = lines
    .filter((l) => l.side === "dt")
    .reduce((acc, l) => acc + (parseFloat(l.amount) || 0), 0);
  const ktSum = lines
    .filter((l) => l.side === "kt")
    .reduce((acc, l) => acc + (parseFloat(l.amount) || 0), 0);
  const balanced = Math.abs(dtSum - ktSum) < 0.01 && dtSum > 0;
  const hasCrossCurrency = lines.some((l) => l.currency !== funcCurrency);

  function updateLine(key: string, patch: Partial<JournalLineDraft>) {
    onChange(lines.map((l) => (l._key === key ? { ...l, ...patch } : l)));
  }

  function removeLine(key: string) {
    onChange(lines.filter((l) => l._key !== key));
  }

  function addLine() {
    onChange([...lines, newLine()]);
  }

  const glById = (id: number | null): GlOption | undefined =>
    id !== null ? glAccounts?.find((g) => g.id === id) : undefined;

  if (readOnly) {
    return (
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-gray-200 dark:border-gray-700">
              <th className="text-left py-2 pr-3 text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">GL-счёт</th>
              <th className="text-center py-2 px-2 text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Сторона</th>
              <th className="text-right py-2 px-2 text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Сумма</th>
              <th className="text-left py-2 px-2 text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Валюта</th>
              <th className="text-left py-2 px-2 text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Контрагент</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
            {lines.map((l) => {
              const gl = glById(l.account_gl_id);
              return (
                <tr key={l._key} className="text-sm">
                  <td className="py-2 pr-3 text-gray-800 dark:text-gray-200">
                    {gl ? `${gl.code} — ${gl.name}` : l.account_gl_id ?? "—"}
                  </td>
                  <td className="py-2 px-2 text-center">
                    <span className={`inline-block px-2 py-0.5 rounded text-xs font-semibold ${l.side === "dt" ? "bg-blue-50 text-blue-700 dark:bg-blue-900/20 dark:text-blue-400" : "bg-orange-50 text-orange-700 dark:bg-orange-900/20 dark:text-orange-400"}`}>
                      {l.side === "dt" ? "Дт" : "Кт"}
                    </span>
                  </td>
                  <td className="py-2 px-2 text-right tabular-nums font-medium dark:text-gray-200">
                    {formatAmount(parseFloat(l.amount) || 0)}
                  </td>
                  <td className="py-2 px-2 text-gray-600 dark:text-gray-400">{l.currency}</td>
                  <td className="py-2 px-2 text-gray-600 dark:text-gray-400 text-xs">
                    {l.counterparty_company_id
                      ? counterparties?.find((c) => c.id === l.counterparty_company_id)?.name ?? `#${l.counterparty_company_id}`
                      : "—"}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-3">
      {/* Строки проводки */}
      <div className="flex flex-col gap-2">
        {lines.map((line) => {
          const gl = glById(line.account_gl_id);
          const needsCounterparty = gl?.requires_counterparty ?? false;
          return (
            <div key={line._key} className="flex flex-wrap items-start gap-2 p-3 rounded-md bg-gray-50 dark:bg-gray-800/40 border border-gray-100 dark:border-gray-700">
              {/* GL-счёт */}
              <div className="flex-1 min-w-[200px]">
                <select
                  className="input text-sm w-full"
                  value={line.account_gl_id ?? ""}
                  onChange={(e) => updateLine(line._key, { account_gl_id: e.target.value ? Number(e.target.value) : null })}
                >
                  <option value="">Выберите GL-счёт...</option>
                  {glAccounts?.map((g) => (
                    <option key={g.id} value={g.id}>{g.code} — {g.name}</option>
                  ))}
                </select>
              </div>

              {/* Дт / Кт toggle */}
              <div className="flex rounded-md overflow-hidden border border-gray-200 dark:border-gray-600 shrink-0">
                <button
                  type="button"
                  onClick={() => updateLine(line._key, { side: "dt" })}
                  className={`px-3 py-2 text-xs font-semibold transition-colors ${line.side === "dt" ? "bg-primary text-white" : "bg-white dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-600"}`}
                >
                  Дт
                </button>
                <button
                  type="button"
                  onClick={() => updateLine(line._key, { side: "kt" })}
                  className={`px-3 py-2 text-xs font-semibold transition-colors ${line.side === "kt" ? "bg-primary text-white" : "bg-white dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-600"}`}
                >
                  Кт
                </button>
              </div>

              {/* Сумма */}
              <input
                type="number"
                min="0.01"
                step="0.01"
                placeholder="0.00"
                className="input text-sm w-28"
                value={line.amount}
                onChange={(e) => updateLine(line._key, { amount: e.target.value })}
              />

              {/* Валюта */}
              <CurrencySelect
                value={line.currency}
                onChange={(v) => updateLine(line._key, { currency: v })}
                className="input text-sm w-28"
              />

              {/* Контрагент (только для счетов с requires_counterparty) */}
              {needsCounterparty && (
                <select
                  className="input text-sm flex-1 min-w-[160px]"
                  value={line.counterparty_company_id ?? ""}
                  onChange={(e) => updateLine(line._key, { counterparty_company_id: e.target.value ? Number(e.target.value) : null })}
                >
                  <option value="">Контрагент (опц.)</option>
                  {counterparties?.map((c) => (
                    <option key={c.id} value={c.id}>{c.name}</option>
                  ))}
                </select>
              )}

              {/* Удалить строку */}
              <button
                type="button"
                onClick={() => removeLine(line._key)}
                disabled={lines.length <= 2}
                className="p-2 text-gray-400 hover:text-danger transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
                title="Удалить строку"
              >
                <i className="bi bi-x-lg" />
              </button>
            </div>
          );
        })}
      </div>

      {/* Добавить строку */}
      <button
        type="button"
        onClick={addLine}
        className="btn-ghost text-sm self-start"
      >
        <i className="bi bi-plus mr-1" />
        Добавить строку
      </button>

      {/* Live-баланс */}
      <div className={`flex flex-wrap items-center gap-4 p-3 rounded-md text-sm ${balanced ? "bg-green-50 dark:bg-green-900/20 text-success" : "bg-red-50 dark:bg-red-900/20 text-danger"}`}>
        <span className="tabular-nums">Σ Дт: <strong>{formatAmount(dtSum)}</strong></span>
        <span className="tabular-nums">Σ Кт: <strong>{formatAmount(ktSum)}</strong></span>
        <span className="flex items-center gap-1">
          <span className={`w-2 h-2 rounded-full ${balanced ? "bg-success" : "bg-danger"}`} />
          {balanced
            ? "Сбалансировано"
            : dtSum > 0 || ktSum > 0
              ? `Дисбаланс: ${formatAmount(Math.abs(dtSum - ktSum))}`
              : "Заполни суммы"}
        </span>
        {hasCrossCurrency && (
          <span className="text-xs text-gray-500 dark:text-gray-400 ml-auto">
            <i className="bi bi-info-circle mr-1" />
            Точный баланс проверяется при проводке (backend учитывает курсы)
          </span>
        )}
      </div>
    </div>
  );
}
