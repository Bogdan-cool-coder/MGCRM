"use client";

import { useState, useMemo, useRef, useEffect } from "react";
import Link from "next/link";
import useSWR, { mutate as globalMutate } from "swr";
import { PageHeader } from "@/components/PageHeader";
import { RoleGate } from "@/components/RoleGate";
import { EmptyState } from "@/components/EmptyState";
import { Modal } from "@/components/Modal";
import { formatCurrency, formatAmount } from "@/lib/format";
import { FinTableSkeleton } from "@/components/Finance/FinTableSkeleton";
import { api, ApiError, fetcher } from "@/lib/api";
import { useMe } from "@/lib/auth";
import type {
  FinLegalEntity,
  FinRevenueSchedule,
  FinRevenueRecognitionRun,
  FinRevenueReverse,
  UserRole,
} from "@/lib/types";

const FINANCE_ROLES: UserRole[] = ["accountant", "cfo", "director", "admin"];
// Роли с capability recognize_revenue (прогон/сторно). Должно совпадать с backend.
const RECOGNIZE_ROLES: UserRole[] = ["accountant", "cfo", "admin"];

const STATUS_BADGES: Record<string, string> = {
  scheduled: "badge-neutral",
  recognized: "badge-success",
  skipped:    "badge-warning",
  reversed:   "badge-danger",
};

const STATUS_LABELS: Record<string, string> = {
  scheduled:  "Запланирована",
  recognized: "Признана",
  skipped:    "Пропущена",
  reversed:   "Сторнирована",
};

const STATUS_FILTERS = ["scheduled", "recognized", "skipped", "reversed"] as const;

function monthLabel(year: number, month: number): string {
  return new Date(year, month - 1, 1).toLocaleString("ru-RU", { month: "long", year: "numeric" });
}

function extractErrMsg(err: unknown): string {
  if (err instanceof ApiError) {
    const d = err.detail;
    if (typeof d === "object" && d !== null && "detail" in d) return String((d as Record<string, unknown>)["detail"]);
    if (typeof d === "string") return d;
  }
  return "Ошибка операции";
}

function lastTwelvePeriods(): { year: number; month: number }[] {
  const now = new Date();
  const out: { year: number; month: number }[] = [];
  for (let i = 0; i < 12; i++) {
    const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
    out.push({ year: d.getFullYear(), month: d.getMonth() + 1 });
  }
  return out;
}

export default function RevenueRecognitionPage() {
  const { user } = useMe();
  const canRecognize = user != null && RECOGNIZE_ROLES.includes(user.role);
  const wrapRef = useRef<HTMLDivElement>(null);

  const [entityId, setEntityId] = useState("");
  const [statusFilter, setStatusFilter] = useState("");

  // прогон признания
  const periods = lastTwelvePeriods();
  const [runOpen, setRunOpen] = useState(false);
  const [runPeriodIdx, setRunPeriodIdx] = useState(0);
  const [running, setRunning] = useState(false);
  const [runError, setRunError] = useState<string | null>(null);
  const [runResult, setRunResult] = useState<FinRevenueRecognitionRun | null>(null);

  // сторно строки
  const [reverseTarget, setReverseTarget] = useState<FinRevenueSchedule | null>(null);
  const [reversing, setReversing] = useState(false);
  const [reverseError, setReverseError] = useState<string | null>(null);

  const { data: entities } = useSWR<FinLegalEntity[]>("/api/finance/legal-entities", fetcher);

  const scheduleUrl = useMemo(() => {
    if (!entityId) return null;
    const p = new URLSearchParams({ legal_entity_id: entityId });
    if (statusFilter) p.set("status", statusFilter);
    return `/api/finance/revenue-recognition/schedule?${p}`;
  }, [entityId, statusFilter]);

  const { data: rows, isLoading, error } = useSWR<FinRevenueSchedule[]>(scheduleUrl, fetcher);

  // Scroll-shadow
  useEffect(() => {
    const el = wrapRef.current;
    if (!el) return;
    const onScroll = () => el.classList.toggle("scrolled", el.scrollTop > 4);
    el.addEventListener("scroll", onScroll, { passive: true });
    return () => el.removeEventListener("scroll", onScroll);
  }, []);

  async function handleRun() {
    if (!entityId) {
      setRunError("Выберите юрлицо");
      return;
    }
    const period = periods[runPeriodIdx];
    setRunning(true);
    setRunError(null);
    setRunResult(null);
    try {
      const res = await api<FinRevenueRecognitionRun>("/finance/revenue-recognition/run", {
        method: "POST",
        body: {
          legal_entity_id: Number(entityId),
          year: period.year,
          month: period.month,
        },
      });
      setRunResult(res);
      if (scheduleUrl) await globalMutate(scheduleUrl);
    } catch (err) {
      setRunError(extractErrMsg(err));
    } finally {
      setRunning(false);
    }
  }

  async function handleReverse() {
    if (!reverseTarget) return;
    setReversing(true);
    setReverseError(null);
    try {
      await api<FinRevenueReverse>(
        `/finance/revenue-recognition/schedule/${reverseTarget.id}/reverse`,
        { method: "POST", body: {} },
      );
      if (scheduleUrl) await globalMutate(scheduleUrl);
      setReverseTarget(null);
    } catch (err) {
      setReverseError(extractErrMsg(err));
    } finally {
      setReversing(false);
    }
  }

  const entity = entities?.find((e) => String(e.id) === entityId);
  const currency = entity?.functional_currency ?? "";

  return (
    <RoleGate allowed={FINANCE_ROLES}>
      <div className="flex flex-col h-full">
        <PageHeader
          title="Признание выручки"
          description="План признания MRR помесячно по подпискам (по начислению, независимо от оплаты)"
          actions={
            <div className="flex items-center gap-2">
              <Link href="/finance/reports" className="btn-ghost text-sm">
                <i className="bi bi-arrow-left mr-1" />
                К отчётам
              </Link>
              {canRecognize && (
                <button
                  type="button"
                  className="btn-primary text-sm"
                  onClick={() => {
                    setRunResult(null);
                    setRunError(null);
                    setRunOpen(true);
                  }}
                  disabled={!entityId}
                  title={!entityId ? "Сначала выберите юрлицо" : undefined}
                >
                  <i className="bi bi-play-circle mr-1.5" />
                  Признать за период
                </button>
              )}
            </div>
          }
        />

        <div className="p-6 flex flex-col gap-4">
          {/* Фильтры */}
          <div className="card p-4 flex flex-wrap items-center gap-3">
            <label className="text-sm text-gray-600 dark:text-gray-400 shrink-0">Юрлицо:</label>
            <select
              className="input text-sm max-w-xs"
              value={entityId}
              onChange={(e) => setEntityId(e.target.value)}
            >
              <option value="">Выберите юрлицо...</option>
              {entities?.map((e) => (
                <option key={e.id} value={e.id}>{e.name}</option>
              ))}
            </select>

            <label className="text-sm text-gray-600 dark:text-gray-400 shrink-0 ml-2">Статус:</label>
            <select
              className="input text-sm max-w-[180px]"
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value)}
            >
              <option value="">Все</option>
              {STATUS_FILTERS.map((s) => (
                <option key={s} value={s}>{STATUS_LABELS[s]}</option>
              ))}
            </select>
          </div>

          {!entityId && (
            <EmptyState
              icon="bi-calendar-check"
              title="Выберите юрлицо"
              description="Выберите юрлицо для просмотра плана признания выручки"
            />
          )}

          {entityId && error && (
            <div className="card p-6 text-center text-danger">
              <i className="bi bi-exclamation-triangle mr-2" />
              Не удалось загрузить план признания
            </div>
          )}

          {entityId && (isLoading || rows) && (
            <div className="card overflow-hidden">
              <div
                ref={wrapRef}
                className="fin-table-wrap overflow-x-auto max-h-[60vh] overflow-y-auto"
              >
                <table className="w-full text-sm">
                  <thead className="fin-thead-shadow bg-gray-50 dark:bg-gray-800/60 border-b border-gray-200 dark:border-gray-700 sticky top-0 z-10">
                    <tr>
                      <th className="text-left px-4 py-2.5 text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Период</th>
                      <th className="text-left px-4 py-2.5 text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Подписка</th>
                      <th className="text-right px-4 py-2.5 text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Нетто</th>
                      <th className="text-right px-4 py-2.5 text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">НДС</th>
                      <th className="text-left px-4 py-2.5 text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Статус</th>
                      <th className="px-4 py-2.5 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Действие</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                    {isLoading && !rows ? (
                      <FinTableSkeleton rows={6} cols={["15%", "12%", "12%", "10%", "14%", "10%"]} />
                    ) : rows && rows.length === 0 ? (
                      <tr>
                        <td colSpan={6} className="py-12 text-center">
                          <EmptyState
                            icon="bi-inbox"
                            title="Строк не найдено"
                            description="Строк плана признания не найдено"
                          />
                        </td>
                      </tr>
                    ) : (
                      rows?.map((r) => {
                        const badgeCls = STATUS_BADGES[r.status] ?? "badge-neutral";
                        const statusLabel = STATUS_LABELS[r.status] ?? r.status;
                        return (
                          <tr key={r.id} className="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                            <td className="px-4 py-2.5 text-gray-700 dark:text-gray-300 capitalize whitespace-nowrap">
                              {monthLabel(r.period_year, r.period_month)}
                            </td>
                            <td className="px-4 py-2.5 text-gray-600 dark:text-gray-400 font-mono text-xs">
                              #{r.subscription_id}
                            </td>
                            <td className="px-4 py-2.5 text-right tabular-nums text-gray-800 dark:text-gray-200 font-medium">
                              {formatCurrency(r.amount_net, r.currency || currency)}
                            </td>
                            <td className="px-4 py-2.5 text-right tabular-nums text-gray-500 dark:text-gray-400">
                              {r.vat_amount > 0 ? formatAmount(r.vat_amount) : <span className="text-gray-300 dark:text-gray-600">—</span>}
                            </td>
                            <td className="px-4 py-2.5">
                              <span className={`badge ${badgeCls}`}>
                                {statusLabel}
                              </span>
                            </td>
                            <td className="px-4 py-2.5 text-right">
                              {canRecognize && r.status === "recognized" ? (
                                <button
                                  type="button"
                                  className="btn-ghost text-xs text-danger"
                                  onClick={() => {
                                    setReverseError(null);
                                    setReverseTarget(r);
                                  }}
                                >
                                  <i className="bi bi-arrow-counterclockwise mr-1" />
                                  Сторно
                                </button>
                              ) : (
                                <span className="text-xs text-gray-300 dark:text-gray-600">—</span>
                              )}
                            </td>
                          </tr>
                        );
                      })
                    )}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          <div className="card p-4 flex items-start gap-3 bg-gray-50 dark:bg-gray-900/50 border-gray-200 dark:border-gray-700">
            <i className="bi bi-info-circle text-info mt-0.5" />
            <div className="text-sm text-gray-600 dark:text-gray-400">
              Признание постит проводку начисления (Дт дебиторка / Кт выручка / Кт НДС) —
              выручка попадает в P&L «по начислению». Оплата гасит дебиторку отдельной проводкой.
              Сторно признанной строки делает корректировку в текущем открытом периоде.
            </div>
          </div>
        </div>
      </div>

      {/* Модалка: прогон признания за период */}
      <Modal
        open={runOpen}
        title="Признать выручку за период"
        onClose={() => setRunOpen(false)}
        width="sm"
        footer={
          <>
            <button className="btn-ghost" onClick={() => setRunOpen(false)} disabled={running}>
              Закрыть
            </button>
            <button className="btn-primary" onClick={handleRun} disabled={running}>
              {running ? "Признаю..." : "Признать"}
            </button>
          </>
        }
      >
        <div className="flex flex-col gap-3">
          <div>
            <label className="label">Период</label>
            <select
              className="input text-sm"
              value={runPeriodIdx}
              onChange={(e) => setRunPeriodIdx(Number(e.target.value))}
              disabled={running}
            >
              {periods.map((p, idx) => (
                <option key={`${p.year}-${p.month}`} value={idx}>
                  {monthLabel(p.year, p.month)}
                </option>
              ))}
            </select>
          </div>
          <p className="text-xs text-gray-500 dark:text-gray-400">
            Будут сгенерированы строки плана по активным подпискам {entity ? <strong>{entity.name}</strong> : null} и
            проведено начисление выручки. Повторный прогон не задваивает уже признанное.
          </p>
          {runResult && (
            <div className="rounded-md border border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/10 p-3 text-sm text-gray-700 dark:text-gray-300">
              <div className="font-medium text-green-700 dark:text-green-400 mb-1">
                Прогон за {runResult.period} выполнен
              </div>
              <ul className="text-xs space-y-0.5">
                <li>Обработано подписок: {runResult.processed}</li>
                <li>Новых строк плана: {runResult.scheduled}</li>
                <li>Признано: {runResult.recognized}</li>
                <li>Уже было признано: {runResult.existing}</li>
                <li>Пропущено: {runResult.skipped}</li>
              </ul>
            </div>
          )}
          {runError && <p className="text-sm text-danger">{runError}</p>}
        </div>
      </Modal>

      {/* Модалка: сторно строки признания */}
      <Modal
        open={reverseTarget != null}
        title="Сторнировать признание?"
        onClose={() => setReverseTarget(null)}
        width="sm"
        footer={
          <>
            <button className="btn-ghost" onClick={() => setReverseTarget(null)} disabled={reversing}>
              Отмена
            </button>
            <button className="btn-secondary text-danger" onClick={handleReverse} disabled={reversing}>
              {reversing ? "Сторнирую..." : "Сторнировать"}
            </button>
          </>
        }
      >
        <p className="text-sm text-gray-700 dark:text-gray-300">
          <i className="bi bi-exclamation-triangle text-danger mr-1" />
          Признанная выручка не стирается — будет проведена сторно-проводка в текущем открытом
          периоде, статус строки станет «Сторнирована».
        </p>
        {reverseTarget && (
          <p className="text-xs text-gray-500 dark:text-gray-400 mt-2">
            Период: {monthLabel(reverseTarget.period_year, reverseTarget.period_month)},
            подписка #{reverseTarget.subscription_id}, сумма {formatCurrency(reverseTarget.amount_net, reverseTarget.currency)}.
          </p>
        )}
        {reverseError && <p className="text-sm text-danger mt-2">{reverseError}</p>}
      </Modal>
    </RoleGate>
  );
}
