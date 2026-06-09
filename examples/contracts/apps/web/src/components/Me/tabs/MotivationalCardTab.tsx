"use client";

import { useState } from "react";
import useSWR from "swr";
import { fetcher } from "@/lib/api";
import { formatCurrency } from "@/lib/format";
import { EmptyState } from "@/components/EmptyState";
import { MkTable } from "../mk/MkTable";
import { MkRatesFooter } from "../mk/MkRatesFooter";
import { MkStatusBadge } from "../mk/MkStatusBadge";
import type { MotivationalCard } from "@/lib/types";

const MONTHS_RU = [
  "Январь", "Февраль", "Март", "Апрель", "Май", "Июнь",
  "Июль", "Август", "Сентябрь", "Октябрь", "Ноябрь", "Декабрь",
];

function generatePeriodOptions() {
  const now = new Date();
  const options: { value: string; label: string }[] = [];
  for (let i = 0; i < 12; i++) {
    const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
    const y = d.getFullYear();
    const m = d.getMonth() + 1;
    options.push({
      value: `${y}-${String(m).padStart(2, "0")}`,
      label: `${MONTHS_RU[m - 1]} ${y}`,
    });
  }
  return options;
}

const STATUS_BADGE: Record<string, string> = {
  draft: "bg-info/10 text-info",
  finalized: "bg-success/10 text-success",
  paid: "bg-primary/10 text-primary",
};

const STATUS_LABEL: Record<string, string> = {
  draft: "Черновик",
  finalized: "Финализировано",
  paid: "Выплачено",
};

interface Props {
  userId?: number;
}

export function MotivationalCardTab({ userId }: Props) {
  const periodOptions = generatePeriodOptions();
  const [period, setPeriod] = useState(periodOptions[0].value);

  const swrKey = `/me/motivational-card?period=${period}${userId ? `&user_id=${userId}` : ""}`;
  const { data: card, isLoading, error } = useSWR<MotivationalCard | null>(swrKey, fetcher);

  function safePct(fact: number, plan: number) {
    if (!plan) return 0;
    return Math.round((fact / plan) * 100);
  }

  return (
    <div className="space-y-5">
      {/* Шапка */}
      <div className="flex items-center justify-between">
        <span className="text-[11px] uppercase tracking-wider font-semibold text-gray-400 dark:text-gray-500">
          Мотивационная карта
        </span>
        <select
          className="input w-48"
          value={period}
          onChange={(e) => setPeriod(e.target.value)}
          aria-label="Выбрать период МК"
        >
          {periodOptions.map((p) => (
            <option key={p.value} value={p.value}>{p.label}</option>
          ))}
        </select>
      </div>

      {isLoading && (
        <div className="rounded-2xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-1 p-5 space-y-3 animate-pulse">
          {[1, 2, 3].map((i) => (
            <div key={i} className="h-12 bg-gray-100 dark:bg-gray-700 rounded-xl" />
          ))}
        </div>
      )}

      {!isLoading && error && (
        <div className="rounded-2xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-1 p-8">
          <EmptyState icon="bi-exclamation-circle" title="Не удалось загрузить МК" />
        </div>
      )}

      {!isLoading && !error && !card && (
        <div className="rounded-2xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-1 p-10">
          <EmptyState
            icon="bi-file-earmark-bar-graph"
            title="МК за этот период ещё не рассчитана"
            description="Обратись к руководителю для настройки плана"
          />
        </div>
      )}

      {!isLoading && !error && card && (
        <div className="rounded-2xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-1 overflow-hidden">
          {/* Шапка МК */}
          <div className="px-6 py-5 bg-primary/5 dark:bg-white/5 border-b border-gray-200 dark:border-white/10">
            <div className="flex flex-wrap items-center justify-between gap-4">
              <div className="space-y-1">
                <div className="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                  <span className="font-bold text-gray-900 dark:text-white">MACRO Global</span>
                  <span aria-hidden="true">·</span>
                  <span>{MONTHS_RU[card.month - 1]} {card.year}</span>
                </div>
                <div className="flex flex-wrap items-center gap-4 text-sm">
                  <span className="text-gray-500">
                    План:{" "}
                    <span className="font-semibold text-gray-900 dark:text-white">
                      {formatCurrency(card.personal_income_plan, card.personal_income_currency)}
                    </span>
                  </span>
                  <i className="bi bi-arrow-right text-gray-300" aria-hidden="true" />
                  <span className="text-gray-500">
                    Факт:{" "}
                    <span className="font-semibold text-gray-900 dark:text-white">
                      {formatCurrency(card.personal_income_fact, card.personal_income_currency)}
                    </span>
                  </span>
                  <MkStatusBadge pct={safePct(card.personal_income_fact, card.personal_income_plan)} />
                </div>
              </div>
            </div>
          </div>

          {/* Таблица */}
          <MkTable card={card} />

          {/* Курсы */}
          <div className="px-6 pb-3">
            <MkRatesFooter rates={card.exchange_rates_snapshot} />
          </div>

          {/* Футер — статус + PDF */}
          <div className="px-6 py-4 border-t border-gray-100 dark:border-gray-700 flex items-center justify-between">
            <span className={`badge font-medium ${STATUS_BADGE[card.status] ?? "bg-gray-100 text-gray-600"}`}>
              {STATUS_LABEL[card.status] ?? card.status}
            </span>
            {/* PDF-экспорт пока не реализован на бэке (501). Гейтим кнопку,
                чтобы она не выглядела рабочей и не вела в ошибку. */}
            <button
              type="button"
              disabled
              title="Экспорт в PDF появится позже"
              className="btn-secondary text-sm opacity-50 cursor-not-allowed inline-flex items-center"
            >
              <i className="bi bi-download mr-1.5" aria-hidden="true" />
              Скачать PDF
              <span className="badge bg-gray-100 dark:bg-gray-700 text-gray-500 text-[10px] ml-2">
                Скоро
              </span>
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
