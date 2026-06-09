"use client";

import { useState } from "react";
import useSWR, { mutate as globalMutate } from "swr";
import { PageHeader } from "@/components/PageHeader";
import { RoleGate } from "@/components/RoleGate";
import { Modal } from "@/components/Modal";
import { useToast } from "@/components/ui/Toast";
import { api, ApiError, fetcher } from "@/lib/api";
import type { FinPeriodLock, FinLegalEntity, UserRole, User } from "@/lib/types";

const ALLOWED_ROLES: UserRole[] = ["cfo", "admin"];

function monthLabel(year: number, month: number): string {
  const d = new Date(year, month - 1, 1);
  return d.toLocaleString("ru-RU", { month: "long", year: "numeric" });
}

// Generate last 6 months + current + next 3 = 10 total
function generatePeriods(): { year: number; month: number }[] {
  const now = new Date();
  const periods: { year: number; month: number }[] = [];
  for (let i = -6; i <= 3; i++) {
    const d = new Date(now.getFullYear(), now.getMonth() + i, 1);
    periods.push({ year: d.getFullYear(), month: d.getMonth() + 1 });
  }
  return periods;
}

function extractErrMsg(err: unknown): string {
  if (err instanceof ApiError) {
    const d = err.detail;
    if (typeof d === "object" && d !== null && "detail" in d) return String((d as Record<string, unknown>)["detail"]);
    if (typeof d === "string") return d;
  }
  return "Ошибка операции";
}

type ConfirmMode = "lock" | "unlock";

function TableSkeleton() {
  return (
    <>
      {Array.from({ length: 6 }).map((_, i) => (
        <tr key={i} className="border-b border-gray-100 dark:border-gray-800 animate-pulse">
          {[40, 24, 32, 20].map((w, j) => (
            <td key={j} className="px-4 py-3">
              <div className={`h-4 bg-gray-100 dark:bg-gray-800 rounded`} style={{ width: `${w * 4}px` }} />
            </td>
          ))}
        </tr>
      ))}
    </>
  );
}

export default function PeriodLockPage() {
  const { toast } = useToast();
  const [entityId, setEntityId] = useState("");
  const [confirmMode, setConfirmMode] = useState<ConfirmMode | null>(null);
  const [confirmPeriod, setConfirmPeriod] = useState<{ year: number; month: number } | null>(null);
  const [actioning, setActioning] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);

  const { data: entities } = useSWR<FinLegalEntity[]>("/api/finance/legal-entities", fetcher);
  const { data: users } = useSWR<User[]>("/users", fetcher);

  const swrKey = `/api/finance/periods?${entityId ? `entity=${entityId}` : ""}`;
  const { data: locks, isLoading, error } = useSWR<FinPeriodLock[]>(swrKey, fetcher);

  const periods = generatePeriods();

  function isLocked(year: number, month: number): FinPeriodLock | undefined {
    if (!locks) return undefined;
    return locks.find((l) => l.year === year && l.month === month && (!entityId || l.legal_entity_id === Number(entityId)));
  }

  function openConfirm(mode: ConfirmMode, period: { year: number; month: number }) {
    setConfirmMode(mode);
    setConfirmPeriod(period);
    setActionError(null);
  }

  function closeConfirm() {
    setConfirmMode(null);
    setConfirmPeriod(null);
    setActionError(null);
  }

  async function handleLock() {
    if (!confirmPeriod || !entityId) {
      setActionError("Выберите юрлицо");
      return;
    }
    setActioning(true);
    setActionError(null);
    try {
      await api("/finance/periods/lock", {
        method: "POST",
        body: {
          legal_entity_id: Number(entityId),
          year: confirmPeriod.year,
          month: confirmPeriod.month,
        },
      });
      await globalMutate(swrKey);
      toast.success(`Период ${monthLabel(confirmPeriod.year, confirmPeriod.month)} закрыт`);
      closeConfirm();
    } catch (err) {
      setActionError(extractErrMsg(err));
    } finally {
      setActioning(false);
    }
  }

  async function handleUnlock() {
    if (!confirmPeriod || !entityId) {
      setActionError("Выберите юрлицо");
      return;
    }
    setActioning(true);
    setActionError(null);
    try {
      await api("/finance/periods/lock", {
        method: "DELETE",
        body: {
          legal_entity_id: Number(entityId),
          year: confirmPeriod.year,
          month: confirmPeriod.month,
        },
      });
      await globalMutate(swrKey);
      toast.success(`Период ${monthLabel(confirmPeriod.year, confirmPeriod.month)} открыт`);
      closeConfirm();
    } catch (err) {
      setActionError(extractErrMsg(err));
    } finally {
      setActioning(false);
    }
  }

  return (
    <RoleGate
      allowed={ALLOWED_ROLES}
      fallback={
        <div className="p-8 text-center flex flex-col items-center gap-3">
          <i className="bi bi-lock text-4xl text-gray-300 dark:text-gray-600" />
          <p className="text-sm text-gray-500 dark:text-gray-400">
            Этот раздел доступен только CFO и администратору
          </p>
        </div>
      }
    >
      <div className="flex flex-col h-full">
        <PageHeader
          title="Закрытие периодов"
          description="Управление закрытыми бухгалтерскими периодами"
        />

        <div className="p-6 flex flex-col gap-4">
          {/* Фильтр юрлицо */}
          <div className="card p-4">
            <div className="flex items-center gap-3">
              <label className="label shrink-0">Юрлицо</label>
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
            </div>
          </div>

          {/* Предупреждение */}
          <div className="flex items-start gap-2 p-3 rounded-lg bg-yellow-50 dark:bg-yellow-900/10 border border-yellow-200 dark:border-yellow-800/50">
            <i className="bi bi-exclamation-triangle text-warning mt-0.5 shrink-0" />
            <p className="text-sm text-gray-700 dark:text-gray-300">
              Закрытый период нельзя изменить без явного открытия.
              Все попытки провести операции в закрытый период вернут ошибку.
            </p>
          </div>

          {/* Таблица периодов */}
          <div className="card overflow-hidden">
            {error ? (
              <p className="p-4 text-sm text-danger">Не удалось загрузить периоды</p>
            ) : (
              <table className="w-full text-sm">
                <thead className="bg-gray-50 dark:bg-gray-800/60 border-b border-gray-200 dark:border-gray-700">
                  <tr>
                    <th className="text-left px-4 py-2.5 text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Период</th>
                    <th className="text-left px-4 py-2.5 text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Статус</th>
                    <th className="text-left px-4 py-2.5 text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Закрыт кем</th>
                    <th className="px-4 py-2.5 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Действие</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                  {isLoading ? (
                    <TableSkeleton />
                  ) : (
                    periods.map(({ year, month }) => {
                      const lock = isLocked(year, month);
                      const locked = Boolean(lock);
                      const label = monthLabel(year, month);
                      const now = new Date();
                      const isFuture = new Date(year, month - 1, 1) > new Date(now.getFullYear(), now.getMonth(), 1);

                      return (
                        <tr key={`${year}-${month}`} className="hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors">
                          <td className="px-4 py-3 text-sm font-medium text-gray-700 dark:text-gray-300 capitalize">
                            {label}
                          </td>
                          <td className="px-4 py-3">
                            <span className={[
                              "inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium",
                              locked
                                ? "bg-danger/10 text-danger"
                                : "bg-gray-100 text-gray-500 dark:bg-gray-700/60 dark:text-gray-400",
                            ].join(" ")}>
                              <span className="w-1.5 h-1.5 rounded-full bg-current opacity-70" />
                              {locked ? "Закрыт" : "Открыт"}
                            </span>
                          </td>
                          <td className="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                            {lock?.locked_by_user_id != null
                              ? users?.find((u) => u.id === lock.locked_by_user_id)?.email ?? `#${lock.locked_by_user_id}`
                              : "—"}
                          </td>
                          <td className="px-4 py-3 text-right">
                            {!entityId ? (
                              <span className="text-xs text-gray-300 dark:text-gray-600 italic">выберите юрлицо</span>
                            ) : isFuture ? (
                              <span className="text-xs text-gray-300 dark:text-gray-600">—</span>
                            ) : locked ? (
                              <button
                                className="btn-ghost text-xs text-danger"
                                onClick={() => openConfirm("unlock", { year, month })}
                              >
                                <i className="bi bi-unlock mr-1" />
                                Открыть
                              </button>
                            ) : (
                              <button
                                className="btn-secondary text-xs"
                                onClick={() => openConfirm("lock", { year, month })}
                              >
                                <i className="bi bi-lock mr-1" />
                                Закрыть
                              </button>
                            )}
                          </td>
                        </tr>
                      );
                    })
                  )}
                </tbody>
              </table>
            )}
          </div>
        </div>
      </div>

      {/* Модал закрытия */}
      <Modal
        open={confirmMode === "lock"}
        title={confirmPeriod ? `Закрыть ${monthLabel(confirmPeriod.year, confirmPeriod.month)}?` : "Закрыть период?"}
        onClose={closeConfirm}
        width="sm"
        footer={
          <>
            <button className="btn-ghost" onClick={closeConfirm} disabled={actioning}>Отмена</button>
            <button className="btn-primary" onClick={handleLock} disabled={actioning}>
              {actioning ? "Закрываю..." : "Закрыть период"}
            </button>
          </>
        }
      >
        <p className="text-sm text-gray-700 dark:text-gray-300">
          После закрытия ни одна операция этого периода не пройдёт.
          Для открытия потребуется роль CFO.
        </p>
        {actionError && <p className="text-sm text-danger mt-2">{actionError}</p>}
      </Modal>

      {/* Модал открытия */}
      <Modal
        open={confirmMode === "unlock"}
        title={confirmPeriod ? `Открыть ${monthLabel(confirmPeriod.year, confirmPeriod.month)}?` : "Открыть период?"}
        onClose={closeConfirm}
        width="sm"
        footer={
          <>
            <button className="btn-ghost" onClick={closeConfirm} disabled={actioning}>Отмена</button>
            <button className="btn-secondary" onClick={handleUnlock} disabled={actioning}>
              {actioning ? "Открываю..." : "Открыть период"}
            </button>
          </>
        }
      >
        <p className="text-sm text-gray-700 dark:text-gray-300">
          <i className="bi bi-exclamation-triangle text-danger mr-1" />
          <strong>Осторожно:</strong> это позволит изменять проводки за закрытый период.
        </p>
        {actionError && <p className="text-sm text-danger mt-2">{actionError}</p>}
      </Modal>
    </RoleGate>
  );
}
