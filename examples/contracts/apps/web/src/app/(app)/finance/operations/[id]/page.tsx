"use client";

import { useState } from "react";
import Link from "next/link";
import useSWR, { mutate as globalMutate } from "swr";
import { RoleGate } from "@/components/RoleGate";
import { Modal } from "@/components/Modal";
import { OperationStatusBadge } from "@/components/Finance/OperationStatusBadge";
import { DirectionBadge } from "@/components/Finance/DirectionBadge";
import { MoneyCell } from "@/components/Finance/MoneyCell";
import { formatCurrency } from "@/lib/format";
import { AllocationEditor, type AllocationLine } from "@/components/Finance/AllocationEditor";
import { EmptyState } from "@/components/EmptyState";
import { useToast } from "@/components/ui/Toast";
import { api, ApiError, fetcher } from "@/lib/api";
import { useMe } from "@/lib/auth";
import { formatDate } from "@/lib/dates";
import type {
  FinOperation,
  FinAllocation,
  FinLegalEntity,
  FinOpType,
  FinMoneyAccount,
  FinCashflowCategory,
} from "@/lib/types";

const FINANCE_ROLES = ["accountant", "cfo", "director", "admin"] as const;

interface CompanyOption {
  id: number;
  name: string;
}

const DIRECTION_LABELS: Record<string, string> = {
  in: "Приход",
  out: "Расход",
  transfer: "Перевод",
};

const DIRECTION_ICONS: Record<string, string> = {
  in: "bi-arrow-down-circle",
  out: "bi-arrow-up-circle",
  transfer: "bi-arrow-left-right",
};

interface Props {
  params: { id: string };
}

export default function OperationDetailPage({ params }: Props) {
  const { id } = params;
  const { user } = useMe();
  const { toast } = useToast();

  const { data: op, error, isLoading, mutate } = useSWR<FinOperation>(
    `/api/finance/operations/${id}`,
    fetcher
  );

  const { data: allocData, mutate: mutateAlloc } = useSWR<FinAllocation[]>(
    `/api/finance/operations/${id}/allocations`,
    fetcher
  );

  const { data: entities } = useSWR<FinLegalEntity[]>("/api/finance/legal-entities", fetcher);
  const { data: opTypes } = useSWR<FinOpType[]>("/api/finance/op-types", fetcher);
  const { data: accounts } = useSWR<FinMoneyAccount[]>("/api/finance/money-accounts", fetcher);
  const { data: cats } = useSWR<FinCashflowCategory[]>("/api/finance/categories", fetcher);
  const { data: companies } = useSWR<CompanyOption[]>("/api/companies?limit=500", fetcher);

  const canPost = user?.role === "admin" || user?.role === "accountant" || user?.role === "cfo" || user?.role === "director";
  const canCreate = user?.role === "admin" || user?.role === "accountant" || user?.role === "cfo";

  const [posting, setPosting] = useState(false);
  const [reversing, setReversing] = useState(false);
  const [confirmReverse, setConfirmReverse] = useState(false);
  const [postError, setPostError] = useState("");

  const [splitOpen, setSplitOpen] = useState(false);
  const [splitLines, setSplitLines] = useState<AllocationLine[]>([{ cashflow_category_id: "", amount: "" }]);
  const [splitSaving, setSplitSaving] = useState(false);
  const [splitError, setSplitError] = useState("");

  function extractError(e: unknown): string {
    if (e instanceof ApiError) {
      const d = e.detail;
      if (typeof d === "string") {
        if (d.includes("FxRateMissing")) return "Нет курса валюты на выбранную дату.";
        if (d.includes("PeriodLocked")) return "Период закрыт. Обратись к CFO.";
        return d;
      }
    }
    return "Ошибка операции. Попробуй снова.";
  }

  async function handlePost() {
    setPosting(true);
    setPostError("");
    try {
      await api(`/api/finance/operations/${id}/post`, { method: "POST" });
      await mutate();
      await globalMutate("/api/finance/operations");
      toast.success("Операция проведена");
    } catch (e) {
      const msg = extractError(e);
      setPostError(msg);
      toast.error("Не удалось провести", msg);
    } finally {
      setPosting(false);
    }
  }

  async function handleReverse() {
    setReversing(true);
    setPostError("");
    try {
      await api(`/api/finance/operations/${id}/reverse`, { method: "POST", body: {} });
      await mutate();
      await globalMutate("/api/finance/operations");
      toast.success("Сторно-операция создана");
    } catch (e) {
      const msg = extractError(e);
      setPostError(msg);
      toast.error("Не удалось сторнировать", msg);
    } finally {
      setReversing(false);
      setConfirmReverse(false);
    }
  }

  async function handleSaveSplit() {
    const valid = splitLines.every((l) => l.cashflow_category_id && parseFloat(l.amount) > 0);
    if (!valid) { setSplitError("Заполни все строки."); return; }
    setSplitSaving(true);
    setSplitError("");
    try {
      await api(`/api/finance/operations/${id}/allocations`, {
        method: "PUT",
        body: { items: splitLines.map((l) => ({ cashflow_category_id: parseInt(l.cashflow_category_id), amount: parseFloat(l.amount) })) },
      });
      await mutate();
      await mutateAlloc();
      setSplitOpen(false);
      toast.success("Разнесение сохранено");
    } catch (e) {
      const msg = extractError(e);
      setSplitError(msg);
      toast.error("Ошибка разнесения", msg);
    } finally {
      setSplitSaving(false);
    }
  }

  if (isLoading) {
    return (
      <RoleGate allowed={[...FINANCE_ROLES]}>
        <div className="p-6 space-y-4 animate-pulse">
          <div className="h-6 bg-gray-100 dark:bg-gray-800 rounded w-48" />
          <div className="h-40 bg-gray-100 dark:bg-gray-800 rounded-2xl" />
          <div className="h-56 bg-gray-100 dark:bg-gray-800 rounded-2xl" />
        </div>
      </RoleGate>
    );
  }

  if (error || !op) {
    return (
      <RoleGate allowed={[...FINANCE_ROLES]}>
        <div className="p-6">
          <EmptyState
            icon="bi-exclamation-triangle"
            title="Операция не найдена"
            description="Операция не существует или у вас нет доступа"
            cta={
              <Link href="/finance/operations" className="btn-ghost">
                <i className="bi bi-arrow-left mr-1" /> Операции
              </Link>
            }
          />
        </div>
      </RoleGate>
    );
  }

  const allocations: FinAllocation[] = allocData ?? [];
  const splitSum = allocations.reduce((acc, a) => acc + a.amount, 0);
  const splitBalanced = Math.abs(splitSum - op.amount) < 0.01;
  const canRaznesti = canCreate && op.status === "posted";
  const hasFullSplit = allocations.length > 0 && splitBalanced;

  const entityName = entities?.find((e) => e.id === op.legal_entity_id)?.name ?? null;
  const opTypeName = op.op_type_id != null ? opTypes?.find((t) => t.id === op.op_type_id)?.name ?? null : null;
  const counterpartyName = op.counterparty_company_id != null ? companies?.find((c) => c.id === op.counterparty_company_id)?.name ?? null : null;
  const accountFromName = op.account_from_id != null ? accounts?.find((a) => a.id === op.account_from_id)?.name ?? null : null;
  const accountToName = op.account_to_id != null ? accounts?.find((a) => a.id === op.account_to_id)?.name ?? null : null;
  const categoryName = op.cashflow_category_id != null ? cats?.find((c) => c.id === op.cashflow_category_id)?.name ?? null : null;
  const allocCatName = (catId: number | null) => (catId != null ? cats?.find((c) => c.id === catId)?.name ?? null : null);

  return (
    <RoleGate allowed={[...FINANCE_ROLES]}>
      <div className="flex flex-col h-full">
        {/* Hero v2 */}
        <div className="border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-8 py-5">
          <div className="flex items-center gap-2 mb-2">
            <Link
              href="/finance/operations"
              className="text-sm text-gray-500 dark:text-gray-400 hover:text-primary dark:hover:text-blue-400 flex items-center gap-1 transition-colors"
            >
              <i className="bi bi-arrow-left" /> Операции
            </Link>
          </div>
          <div className="flex items-start gap-4">
            <div className="h-10 w-10 rounded-xl bg-primary/10 dark:bg-primary/20 grid place-items-center shrink-0">
              <i className={`bi ${DIRECTION_ICONS[op.direction] ?? "bi-arrow-left-right"} text-lg text-primary dark:text-blue-400`} />
            </div>
            <div className="flex-1 min-w-0">
              <div className="flex items-center gap-3 flex-wrap">
                <h1 className="text-h3 dark:text-gray-100">
                  Операция {op.number ?? `#${op.id}`}
                </h1>
                <OperationStatusBadge status={op.status} />
              </div>
              <p className="text-sm text-gray-500 dark:text-gray-400 mt-1 flex items-center gap-2 flex-wrap">
                <MoneyCell amount={op.amount} currency={op.currency} direction={op.direction} />
                <span className="text-gray-300 dark:text-gray-600">·</span>
                <span className="inline-flex items-center gap-1">
                  <DirectionBadge direction={op.direction} />
                  {DIRECTION_LABELS[op.direction] ?? op.direction}
                </span>
                {op.purpose && (
                  <>
                    <span className="text-gray-300 dark:text-gray-600">·</span>
                    <span className="truncate max-w-xs">{op.purpose}</span>
                  </>
                )}
              </p>
            </div>
          </div>
        </div>

        <div className="p-6 flex-1 overflow-y-auto">
          <div className="grid grid-cols-3 gap-5">
            {/* Main data */}
            <div className="col-span-2 space-y-4">
              <div className="card rounded-2xl shadow-elev-1 p-5">
                <h2 className="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-4">
                  Основные данные
                </h2>
                <dl className="grid grid-cols-2 gap-x-6 gap-y-3.5">
                  <div>
                    <dt className="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-0.5">Юрлицо</dt>
                    <dd className="text-sm font-medium text-gray-800 dark:text-gray-200">{entityName ?? "—"}</dd>
                  </div>
                  <div>
                    <dt className="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-0.5">Тип операции</dt>
                    <dd className="text-sm font-medium text-gray-800 dark:text-gray-200">{opTypeName ?? "—"}</dd>
                  </div>
                  <div>
                    <dt className="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-0.5">Контрагент</dt>
                    <dd className="text-sm font-medium text-gray-800 dark:text-gray-200">{counterpartyName ?? "—"}</dd>
                  </div>
                  <div>
                    <dt className="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-0.5">Дата</dt>
                    <dd className="text-sm font-medium text-gray-800 dark:text-gray-200">{formatDate(op.op_date)}</dd>
                  </div>
                  {op.account_from_id && (
                    <div>
                      <dt className="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-0.5">Счёт списания</dt>
                      <dd className="text-sm font-medium text-gray-800 dark:text-gray-200">{accountFromName ?? "—"}</dd>
                    </div>
                  )}
                  {op.account_to_id && (
                    <div>
                      <dt className="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-0.5">Счёт зачисления</dt>
                      <dd className="text-sm font-medium text-gray-800 dark:text-gray-200">{accountToName ?? "—"}</dd>
                    </div>
                  )}
                  <div>
                    <dt className="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-0.5">Сумма</dt>
                    <dd className="text-sm font-medium">
                      <MoneyCell amount={op.amount} currency={op.currency} direction={op.direction} />
                    </dd>
                  </div>
                  {op.purpose && (
                    <div className="col-span-2">
                      <dt className="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-0.5">Назначение</dt>
                      <dd className="text-sm font-medium text-gray-800 dark:text-gray-200">{op.purpose}</dd>
                    </div>
                  )}
                  {op.vat_amount != null && (
                    <>
                      <div>
                        <dt className="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-0.5">НДС</dt>
                        <dd className="text-sm font-medium text-gray-800 dark:text-gray-200">
                          {formatCurrency(op.vat_amount, op.currency)}
                        </dd>
                      </div>
                      <div>
                        <dt className="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-0.5">Без НДС</dt>
                        <dd className="text-sm font-medium text-gray-800 dark:text-gray-200">
                          {op.amount_net != null ? formatCurrency(op.amount_net, op.currency) : "—"}
                        </dd>
                      </div>
                    </>
                  )}
                </dl>
              </div>

              {/* Allocations */}
              {allocations.length > 0 && (
                <div className="card rounded-2xl shadow-elev-1 p-5">
                  <h2 className="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-4">
                    Разнесение по статьям
                  </h2>
                  <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                      <thead>
                        <tr className="border-b border-gray-100 dark:border-gray-800">
                          <th className="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 text-left py-2">Статья</th>
                          <th className="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 text-right py-2">Сумма</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                        {allocations.map((a) => (
                          <tr key={a.id}>
                            <td className="py-2.5 text-gray-700 dark:text-gray-300">
                              {allocCatName(a.cashflow_category_id) ?? "—"}
                            </td>
                            <td className="py-2.5 text-right tabular-nums font-semibold text-gray-800 dark:text-gray-200">
                              {formatCurrency(a.amount, op.currency)}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                  {/* Total bar */}
                  <div className={`flex items-center justify-between pt-3 mt-1 border-t border-gray-100 dark:border-gray-800 text-xs font-medium ${splitBalanced ? "text-success" : "text-danger"}`}>
                    <span>Итого</span>
                    <span className="tabular-nums">
                      {formatCurrency(splitSum, op.currency)} / {formatCurrency(op.amount, op.currency)}
                      {splitBalanced && <span className="ml-1.5 text-success/70">● сбалансировано</span>}
                    </span>
                  </div>
                </div>
              )}

              {/* Inline allocation editor */}
              {splitOpen && canRaznesti && (
                <div className="card rounded-2xl shadow-elev-1 p-5">
                  <h2 className="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-4">
                    Разнести подробнее
                  </h2>
                  <AllocationEditor
                    lines={splitLines}
                    onChange={setSplitLines}
                    totalAmount={op.amount}
                    currency={op.currency}
                    flowHint={op.direction === "in" ? "inflow" : op.direction === "out" ? "outflow" : undefined}
                  />
                  {splitError && (
                    <p className="text-sm text-danger mt-2">{splitError}</p>
                  )}
                  <div className="flex gap-2 mt-4">
                    <button className="btn-ghost" onClick={() => setSplitOpen(false)} disabled={splitSaving}>Отмена</button>
                    <button className="btn-primary" onClick={handleSaveSplit} disabled={splitSaving}>
                      {splitSaving ? "Сохранение…" : "Сохранить"}
                    </button>
                  </div>
                </div>
              )}
            </div>

            {/* Right sidebar */}
            <div className="space-y-4">
              {/* Actions */}
              <div className="card rounded-2xl shadow-elev-1 p-5">
                <h2 className="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-4">
                  Действия
                </h2>
                <div className="space-y-2">
                  {canPost && (op.status === "planned" || op.status === "to_pay") && (
                    <button
                      type="button"
                      className="btn-primary w-full"
                      onClick={handlePost}
                      disabled={posting}
                    >
                      <i className="bi bi-check-circle mr-1" />
                      {posting ? "Проводим…" : "Провести"}
                    </button>
                  )}
                  {canRaznesti && !hasFullSplit && (
                    <button
                      type="button"
                      className="btn-secondary w-full"
                      onClick={() => { setSplitOpen(true); setSplitLines([{ cashflow_category_id: "", amount: "" }]); }}
                    >
                      <i className="bi bi-diagram-2 mr-1" /> Разнести
                    </button>
                  )}
                  {canPost && op.status === "posted" && (
                    <button
                      type="button"
                      className="btn-secondary w-full text-danger"
                      onClick={() => setConfirmReverse(true)}
                      disabled={reversing}
                    >
                      <i className="bi bi-arrow-counterclockwise mr-1" />
                      Сторнировать
                    </button>
                  )}
                  {postError && (
                    <p className="text-xs text-danger mt-1">{postError}</p>
                  )}
                </div>
              </div>

              {/* Category */}
              {categoryName && (
                <div className="card rounded-2xl shadow-elev-1 p-5">
                  <h2 className="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">
                    Статья ДДС
                  </h2>
                  <p className="text-sm text-gray-800 dark:text-gray-200">{categoryName}</p>
                  {canRaznesti && !splitOpen && !hasFullSplit && (
                    <button
                      type="button"
                      className="text-sm text-primary dark:text-blue-400 hover:underline mt-2.5 flex items-center gap-1 transition-colors"
                      onClick={() => setSplitOpen(true)}
                    >
                      <i className="bi bi-plus" /> Разнести подробнее
                    </button>
                  )}
                </div>
              )}
            </div>
          </div>
        </div>
      </div>

      {/* Confirm reverse — через Modal */}
      <Modal
        open={confirmReverse}
        title="Создать сторно-операцию?"
        onClose={() => setConfirmReverse(false)}
        width="sm"
        footer={
          <>
            <button onClick={() => setConfirmReverse(false)} className="btn-ghost" disabled={reversing}>
              Отмена
            </button>
            <button onClick={handleReverse} className="btn-secondary text-danger" disabled={reversing}>
              <i className="bi bi-arrow-counterclockwise mr-1" />
              {reversing ? "Сторнируем…" : "Сторнировать"}
            </button>
          </>
        }
      >
        <p className="text-sm text-gray-600 dark:text-gray-300">
          Проводка будет зеркально отменена в текущем периоде. Действие необратимо.
        </p>
      </Modal>
    </RoleGate>
  );
}
