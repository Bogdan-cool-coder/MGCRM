"use client";

/**
 * DocPayModal — общий модал оплаты для инвойсов и вендор-счетов.
 * POST /{docType}/{id}/pay с телом DocPaymentIn.
 */

import { useState } from "react";
import useSWR from "swr";
import { Modal } from "@/components/Modal";
import { DatePicker } from "@/components/ui/DatePicker";
import { api, fetcher } from "@/lib/api";
import type { FinMoneyAccount } from "@/lib/types";
import { formatCurrency } from "@/lib/format";

interface Props {
  /** Путь к ресурсу, например "/api/finance/invoices/42" */
  apiBase: string;
  /** Валюта документа */
  currency: string;
  /** Оставшийся долг (gross − paid). Подставляется дефолтом. */
  outstanding: number;
  onClose: () => void;
  onSuccess: () => void;
}

export function DocPayModal({ apiBase, currency, outstanding, onClose, onSuccess }: Props) {
  const today = new Date().toISOString().slice(0, 10);

  const { data: accounts = [] } = useSWR<FinMoneyAccount[]>(
    "/api/finance/money-accounts",
    fetcher
  );

  const [moneyAccountId, setMoneyAccountId] = useState("");
  const [amount, setAmount] = useState(String(outstanding > 0 ? outstanding : ""));
  const [onDate, setOnDate] = useState(today);
  const [error, setError] = useState("");
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError("");

    if (!moneyAccountId) {
      setError("Выберите счёт списания");
      return;
    }
    const amountNum = parseFloat(amount);
    if (!amount || isNaN(amountNum) || amountNum <= 0) {
      setError("Укажи сумму оплаты");
      return;
    }
    if (outstanding > 0 && amountNum > outstanding + 0.001) {
      setError("Сумма превышает остаток к оплате");
      return;
    }
    if (!onDate) {
      setError("Укажи дату оплаты");
      return;
    }

    setSubmitting(true);
    try {
      await api(`${apiBase}/pay`, {
        method: "POST",
        body: {
          money_account_id: parseInt(moneyAccountId),
          amount: amount,
          on_date: onDate,
          cashflow_category_id: null,
        },
      });
      onSuccess();
      onClose();
    } catch (err) {
      const msg = err instanceof Error ? err.message : "Ошибка при проведении оплаты";
      setError(msg);
    } finally {
      setSubmitting(false);
    }
  }

  const amountNum = parseFloat(amount);
  const hasAmount = !!amount && !isNaN(amountNum);
  const isPartial = hasAmount && amountNum < outstanding - 0.001;
  // Переплата ловится и backend'ом, но блокируем заранее ради UX.
  const isOverpay = hasAmount && outstanding > 0 && amountNum > outstanding + 0.001;

  return (
    <Modal open title="Провести оплату" onClose={onClose} width="md">
      <form onSubmit={handleSubmit}>
        <div className="space-y-4 p-5">
          {outstanding > 0 && (
            <div className="bg-primary-light/10 dark:bg-blue-900/20 rounded-lg p-3 text-sm flex items-center gap-2">
              <i className="bi bi-info-circle text-primary" />
              <span className="text-gray-700 dark:text-gray-300">
                Остаток к оплате:{" "}
                <span className="tabular-nums font-semibold text-gray-900 dark:text-gray-100">
                  {formatCurrency(outstanding, currency)}
                </span>
              </span>
            </div>
          )}

          <div>
            <label className="label">Счёт списания *</label>
            <select
              className="input w-full"
              value={moneyAccountId}
              onChange={(e) => setMoneyAccountId(e.target.value)}
            >
              <option value="">— Выберите счёт —</option>
              {accounts
                .filter((a) => a.is_active)
                .map((a) => (
                  <option key={a.id} value={a.id}>
                    {a.name} ({a.currency})
                  </option>
                ))}
            </select>
          </div>

          <div>
            <label className="label">
              Сумма оплаты *{" "}
              <span className="text-xs text-gray-400">{currency}</span>
            </label>
            <input
              type="number"
              className="input w-full"
              min="0.01"
              step="0.01"
              placeholder={String(outstanding > 0 ? outstanding : "")}
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
            />
            {isOverpay && (
              <p className="text-xs text-danger mt-1">
                <i className="bi bi-exclamation-triangle mr-1" />
                Превышает остаток к оплате ({formatCurrency(outstanding, currency)})
              </p>
            )}
            {!isOverpay && isPartial && (
              <p className="text-xs text-warning mt-1">
                <i className="bi bi-exclamation-triangle mr-1" />
                Частичная оплата — статус сменится на «Частично оплачен»
              </p>
            )}
          </div>

          <div>
            <DatePicker
              label="Дата оплаты *"
              value={onDate}
              onChange={(v) => setOnDate(v ?? "")}
              required
            />
          </div>

          {error && (
            <p className="text-sm text-danger bg-red-50 dark:bg-red-900/20 rounded p-2">
              {error}
            </p>
          )}
        </div>

        <div className="flex justify-end gap-2 px-5 py-4 border-t border-gray-100 dark:border-gray-700">
          <button type="button" className="btn-ghost" onClick={onClose} disabled={submitting}>
            Отмена
          </button>
          <button type="submit" className="btn-primary" disabled={submitting || isOverpay}>
            {submitting ? "Проводится…" : "Провести оплату"}
          </button>
        </div>
      </form>
    </Modal>
  );
}
