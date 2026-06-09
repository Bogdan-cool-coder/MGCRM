"use client";

import { useState } from "react";
import useSWR, { mutate as globalMutate } from "swr";
import { Modal } from "@/components/Modal";
import { api, ApiError, fetcher } from "@/lib/api";
import type { AdminBaseCurrency, AdminBaseCurrencyResponse } from "@/lib/types";

const BASE_CURRENCY_KEY = "/admin/currency-rates/base-currency";

const CURRENCY_OPTIONS = ["RUB", "USD", "EUR", "KZT", "UZS", "AED"] as const;

function extractErrMsg(err: unknown): string {
  if (err instanceof ApiError) {
    const d = err.detail;
    if (typeof d === "object" && d !== null && "detail" in d) {
      return String((d as Record<string, unknown>)["detail"]);
    }
    if (typeof d === "string") return d;
  }
  return "Не удалось сменить базовую валюту";
}

/** Есть ли у ответа сводка пересчёта (значит вернулся FinBaseRecomputeJob). */
function hasJobSummary(res: AdminBaseCurrencyResponse): boolean {
  return typeof res.total_lines === "number";
}

export function BaseCurrencyCard() {
  const { data, mutate } = useSWR<AdminBaseCurrency>(BASE_CURRENCY_KEY, fetcher);

  const [target, setTarget] = useState("");
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [result, setResult] = useState<AdminBaseCurrencyResponse | null>(null);

  const currentBase = data?.base_currency ?? "—";

  async function handleChange() {
    if (!target) {
      setError("Выберите валюту");
      return;
    }
    setBusy(true);
    setError(null);
    setResult(null);
    try {
      const res = await api<AdminBaseCurrencyResponse>(BASE_CURRENCY_KEY, {
        method: "POST",
        body: { target_currency: target },
      });
      setResult(res);
      setTarget("");
      await mutate();
      await globalMutate("/api/finance/settings");
      setConfirmOpen(false);
    } catch (err) {
      setError(extractErrMsg(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="card p-5 flex flex-col gap-4">
      <div className="flex items-center justify-between">
        <h2 className="text-sm font-semibold text-gray-900 dark:text-gray-100 flex items-center gap-2">
          <i className="bi bi-globe2 text-primary" />
          Базовая валюта группы
        </h2>
        <span className="inline-flex items-center px-2.5 py-1 rounded-md bg-primary-light/10 text-primary dark:text-blue-400 text-sm font-semibold">
          {currentBase}
        </span>
      </div>

      {data?.base_currency_changed_at && (
        <p className="text-xs text-gray-400 dark:text-gray-500">
          Последняя смена: {new Date(data.base_currency_changed_at).toLocaleString("ru-RU")}
        </p>
      )}

      <p className="text-xs text-gray-500 dark:text-gray-400">
        Единая валюта для консолидированной отчётности. Все суммы операций пересчитываются
        в неё по курсу на дату (amount_in_base).
      </p>

      <div className="flex flex-wrap items-end gap-3">
        <div>
          <label className="label">Новая базовая валюта</label>
          <select
            className="input text-sm max-w-[180px]"
            value={target}
            onChange={(e) => setTarget(e.target.value)}
          >
            <option value="">Выберите...</option>
            {CURRENCY_OPTIONS.map((c) => (
              <option key={c} value={c} disabled={c === data?.base_currency}>
                {c}
                {c === data?.base_currency ? " (текущая)" : ""}
              </option>
            ))}
          </select>
        </div>
        <button
          type="button"
          className="btn-primary text-sm"
          disabled={!target || target === data?.base_currency}
          onClick={() => {
            setError(null);
            setResult(null);
            setConfirmOpen(true);
          }}
        >
          Сменить
        </button>
      </div>

      <div className="flex items-start gap-2 p-3 bg-yellow-50 dark:bg-yellow-900/10 rounded-md border border-yellow-200 dark:border-yellow-800">
        <i className="bi bi-exclamation-triangle text-warning mt-0.5" />
        <p className="text-sm text-gray-700 dark:text-gray-300">
          При смене пересчитываются только проекции открытых периодов. История закрытых
          периодов не меняется — старый курс по прошлым операциям не трогаем.
        </p>
      </div>

      {result && (
        hasJobSummary(result) ? (
          <div className="rounded-md border border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/10 p-3 text-sm">
            <div className="flex items-center justify-between mb-2">
              <span className="font-medium text-green-700 dark:text-green-400">
                Базовая валюта изменена{result.target_currency ? ` → ${result.target_currency}` : ""}
              </span>
            </div>
            <ul className="text-xs text-gray-600 dark:text-gray-400 space-y-0.5">
              <li>Всего строк: {result.total_lines}</li>
              <li>Пересчитано: {result.processed_lines}</li>
              <li>Пропущено (закрытые периоды): {result.skipped_closed}</li>
              <li>Без курса на дату: {result.missing_rate_lines}</li>
            </ul>
          </div>
        ) : (
          <p className="text-sm text-success">
            <i className="bi bi-check-circle mr-1" />
            Базовая валюта изменена.
          </p>
        )
      )}

      <Modal
        open={confirmOpen}
        title={`Сменить базу на ${target}?`}
        onClose={() => setConfirmOpen(false)}
        width="sm"
        footer={
          <>
            <button className="btn-ghost" onClick={() => setConfirmOpen(false)} disabled={busy}>
              Отмена
            </button>
            <button className="btn-primary" onClick={handleChange} disabled={busy}>
              {busy ? "Пересчитываю..." : "Сменить и пересчитать"}
            </button>
          </>
        }
      >
        <p className="text-sm text-gray-700 dark:text-gray-300">
          <i className="bi bi-exclamation-triangle text-warning mr-1" />
          При смене с {currentBase} на {target} пересчитываются только проекции открытых
          периодов. История закрытых периодов не меняется — старый курс по прошлым операциям
          не трогаем.
        </p>
        {error && <p className="text-sm text-danger mt-2">{error}</p>}
      </Modal>
    </div>
  );
}
