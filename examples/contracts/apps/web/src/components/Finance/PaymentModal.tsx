"use client";

import { useState, useEffect } from "react";
import useSWR, { mutate as globalMutate } from "swr";
import { Modal } from "@/components/Modal";
import { CurrencySelect } from "@/components/Currency/CurrencySelect";
import { AllocationEditor, type AllocationLine } from "./AllocationEditor";
import { OperationStatusBadge } from "./OperationStatusBadge";
import { MoneyCell } from "./MoneyCell";
import { DatePicker } from "@/components/ui/DatePicker";
import { api, ApiError, fetcher } from "@/lib/api";
import { useMe } from "@/lib/auth";
import { formatAmount, formatCurrency } from "@/lib/format";
import type {
  FinDirection,
  FinLegalEntity,
  FinMoneyAccount,
  FinOpType,
  FinCashflowCategory,
  FinVatRate,
  FinOperation,
  FinAllocation,
} from "@/lib/types";

const DIRECTION_OPTIONS: { value: FinDirection; label: string; icon: string }[] = [
  { value: "in",       label: "Приход",  icon: "bi-arrow-up-circle" },
  { value: "out",      label: "Расход",  icon: "bi-arrow-down-circle" },
  { value: "transfer", label: "Перевод", icon: "bi-arrow-left-right" },
];

function extractErrorMessage(err: unknown): string {
  if (err instanceof ApiError) {
    const d = err.detail;
    if (typeof d === "string") {
      if (d.includes("FxRateMissing")) return "Нет курса валюты на выбранную дату. Введите курс в разделе «Курсы валют».";
      if (d.includes("PeriodLocked")) return "Период закрыт. Обратись к CFO для открытия.";
      if (d.includes("UnbalancedEntry")) return "Проводка несбалансирована: Σ Дт ≠ Σ Кт.";
      return d;
    }
    if (d && typeof d === "object" && "detail" in d) {
      const detail = (d as Record<string, unknown>).detail;
      if (typeof detail === "string") return detail;
    }
  }
  if (err instanceof Error) return err.message;
  return "Произошла ошибка. Попробуй снова.";
}

interface Props {
  open: boolean;
  onClose: () => void;
  /** Prefill fields when opened from accounts page */
  prefilledAccountId?: number;
  /** View existing operation (read or draft edit) */
  operationId?: number;
  onSuccess?: () => void;
}

export function PaymentModal({ open, onClose, prefilledAccountId, operationId, onSuccess }: Props) {
  const { user } = useMe();
  const canPost = user?.role === "admin" || user?.role === "accountant" || user?.role === "cfo" || user?.role === "director";

  const { data: entities } = useSWR<FinLegalEntity[]>("/api/finance/legal-entities", fetcher);
  const { data: accounts } = useSWR<FinMoneyAccount[]>("/api/finance/money-accounts", fetcher);
  const { data: opTypes } = useSWR<FinOpType[]>("/api/finance/op-types", fetcher);
  const { data: categories } = useSWR<FinCashflowCategory[]>("/api/finance/categories", fetcher);

  // Fetch existing operation if editing
  const { data: existingOp } = useSWR<FinOperation>(
    operationId ? `/api/finance/operations/${operationId}` : null,
    fetcher
  );

  // Разнесение существующей операции (backend не инлайнит его в op).
  const { data: existingAlloc } = useSWR<FinAllocation[]>(
    operationId ? `/api/finance/operations/${operationId}/allocations` : null,
    fetcher
  );

  const [direction, setDirection] = useState<FinDirection>("in");
  const [entityId, setEntityId] = useState("");
  const [opTypeId, setOpTypeId] = useState("");
  const [accountFromId, setAccountFromId] = useState(prefilledAccountId ? String(prefilledAccountId) : "");
  const [accountToId, setAccountToId] = useState(prefilledAccountId ? String(prefilledAccountId) : "");
  const [amount, setAmount] = useState("");
  const [currency, setCurrency] = useState("KZT");
  const [toAmount, setToAmount] = useState("");
  const [opDate, setOpDate] = useState(() => new Date().toISOString().slice(0, 10));
  const [purpose, setPurpose] = useState("");
  const [vatRateId, setVatRateId] = useState("");
  const [vatAmount, setVatAmount] = useState("");
  const [categoryId, setCategoryId] = useState("");
  const [useSplit, setUseSplit] = useState(false);
  const [allocations, setAllocations] = useState<AllocationLine[]>([{ cashflow_category_id: "", amount: "" }]);

  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState("");
  const [confirmReverse, setConfirmReverse] = useState(false);

  // Load vat rates for selected entity
  const selectedEntity = entities?.find((e) => String(e.id) === entityId);

  // Резолв имён существующей операции по FK-id (для read-only режима).
  const existEntityName = existingOp ? entities?.find((e) => e.id === existingOp.legal_entity_id)?.name ?? null : null;
  const existOpTypeName = existingOp?.op_type_id != null ? opTypes?.find((t) => t.id === existingOp.op_type_id)?.name ?? null : null;
  const existAccFromName = existingOp?.account_from_id != null ? accounts?.find((a) => a.id === existingOp.account_from_id)?.name ?? null : null;
  const existAccToName = existingOp?.account_to_id != null ? accounts?.find((a) => a.id === existingOp.account_to_id)?.name ?? null : null;
  const existCatName = existingOp?.cashflow_category_id != null ? categories?.find((c) => c.id === existingOp.cashflow_category_id)?.name ?? null : null;
  const allocCatName = (id: number | null) => (id != null ? categories?.find((c) => c.id === id)?.name ?? null : null);
  const { data: vatRates } = useSWR<FinVatRate[]>(
    selectedEntity?.vat_enabled && entityId
      ? `/api/finance/vat-rates?legal_entity_id=${entityId}&is_active=true`
      : null,
    fetcher
  );

  // Populate from existing operation
  useEffect(() => {
    if (existingOp) {
      setDirection(existingOp.direction);
      setEntityId(String(existingOp.legal_entity_id));
      setOpTypeId(String(existingOp.op_type_id));
      setAccountFromId(existingOp.account_from_id ? String(existingOp.account_from_id) : "");
      setAccountToId(existingOp.account_to_id ? String(existingOp.account_to_id) : "");
      setAmount(String(existingOp.amount));
      setCurrency(existingOp.currency);
      setToAmount(existingOp.to_amount ? String(existingOp.to_amount) : "");
      setOpDate(existingOp.op_date.slice(0, 10));
      setPurpose(existingOp.purpose ?? "");
      setVatRateId(existingOp.vat_rate_id ? String(existingOp.vat_rate_id) : "");
      setVatAmount(existingOp.vat_amount ? String(existingOp.vat_amount) : "");
      setCategoryId(existingOp.cashflow_category_id ? String(existingOp.cashflow_category_id) : "");
    }
  }, [existingOp]);

  // Подтянуть существующее разнесение в форму split.
  useEffect(() => {
    if (existingAlloc && existingAlloc.length > 0) {
      setUseSplit(true);
      setAllocations(existingAlloc.map((a) => ({
        cashflow_category_id: a.cashflow_category_id ? String(a.cashflow_category_id) : "",
        amount: String(a.amount),
      })));
    }
  }, [existingAlloc]);

  // Reset partial state on direction change
  function handleDirectionChange(d: FinDirection) {
    setDirection(d);
    setCategoryId("");
    setVatRateId("");
    setVatAmount("");
    setUseSplit(false);
    setAllocations([{ cashflow_category_id: "", amount: "" }]);
    if (d === "in") setAccountFromId("");
    if (d === "out") setAccountToId("");
    setError("");
  }

  // Auto-compute VAT
  const amountNum = parseFloat(amount) || 0;
  const vatRate = vatRates?.find((v) => String(v.id) === vatRateId);
  const vatAmountComputed = vatRate ? Math.round(amountNum * vatRate.rate_pct / (100 + vatRate.rate_pct) * 100) / 100 : 0;
  const amountNet = vatRateId ? amountNum - (parseFloat(vatAmount) || vatAmountComputed) : null;

  // Filter accounts by direction
  const fromAccounts = accounts ?? [];
  const toAccounts = accounts ?? [];

  // Filter op types by direction (op_type.direction: in|out|transfer|none)
  const filteredOpTypes = opTypes?.filter((t) => t.direction === direction) ?? [];

  // Filter categories by direction (category.direction: inflow|outflow|both)
  const filteredCats = categories?.filter((c) => {
    if (direction === "in") return c.direction === "inflow" || c.direction === "both";
    if (direction === "out") return c.direction === "outflow" || c.direction === "both";
    return false;
  }) ?? [];

  // Split balance check
  const splitSum = allocations.reduce((acc, l) => acc + (parseFloat(l.amount) || 0), 0);
  const splitBalanced = Math.abs(splitSum - amountNum) < 0.01;

  // Validation
  function isValid(): boolean {
    if (!entityId || !opTypeId || !opDate || !amount || parseFloat(amount) <= 0) return false;
    if (direction === "in" && !accountToId) return false;
    if (direction === "out" && !accountFromId) return false;
    if (direction === "transfer" && (!accountFromId || !accountToId)) return false;
    if (direction === "transfer" && accountFromId === accountToId) return false;
    if (useSplit && !splitBalanced) return false;
    return true;
  }

  async function buildBody(draft: boolean) {
    const body: Record<string, unknown> = {
      direction,
      legal_entity_id: parseInt(entityId),
      op_type_id: parseInt(opTypeId),
      amount: parseFloat(amount),
      currency,
      op_date: opDate,
    };
    if (purpose) body.purpose = purpose;
    if (accountFromId) body.account_from_id = parseInt(accountFromId);
    if (accountToId) body.account_to_id = parseInt(accountToId);
    if (toAmount && direction === "transfer") body.to_amount = parseFloat(toAmount);
    if (vatRateId) body.vat_rate_id = parseInt(vatRateId);
    if (vatAmount) body.vat_amount = parseFloat(vatAmount);
    if (amountNet != null) body.amount_net = amountNet;
    if (!useSplit && categoryId) body.cashflow_category_id = parseInt(categoryId);
    return body;
  }

  async function handleSaveDraft() {
    setSubmitting(true);
    setError("");
    try {
      const body = await buildBody(true);
      if (operationId) {
        await api(`/api/finance/operations/${operationId}`, { method: "PATCH", body });
      } else {
        // Черновик: auto_post=false (иначе backend сразу проведёт операцию).
        const op = await api<FinOperation>("/api/finance/operations", { method: "POST", body, query: { auto_post: false } });
        if (useSplit && op?.id) {
          await api(`/api/finance/operations/${op.id}/allocations`, {
            method: "PUT",
            body: { items: allocations.map((a) => ({ cashflow_category_id: parseInt(a.cashflow_category_id), amount: parseFloat(a.amount) })) },
          });
        }
      }
      await globalMutate("/api/finance/operations");
      onSuccess?.();
      onClose();
    } catch (e) {
      setError(extractErrorMessage(e));
    } finally {
      setSubmitting(false);
    }
  }

  async function handlePost() {
    setSubmitting(true);
    setError("");
    try {
      const body = await buildBody(false);
      let opId = operationId;
      if (!opId) {
        // Создаём черновик (auto_post=false), затем split → явный /post ниже (split до проводки).
        const op = await api<FinOperation>("/api/finance/operations", { method: "POST", body, query: { auto_post: false } });
        opId = op?.id;
      } else {
        await api(`/api/finance/operations/${operationId}`, { method: "PATCH", body });
      }
      if (opId) {
        if (useSplit) {
          await api(`/api/finance/operations/${opId}/allocations`, {
            method: "PUT",
            body: { items: allocations.map((a) => ({ cashflow_category_id: parseInt(a.cashflow_category_id), amount: parseFloat(a.amount) })) },
          });
        }
        await api(`/api/finance/operations/${opId}/post`, { method: "POST" });
      }
      await globalMutate("/api/finance/operations");
      onSuccess?.();
      onClose();
    } catch (e) {
      setError(extractErrorMessage(e));
    } finally {
      setSubmitting(false);
    }
  }

  async function handleReverse() {
    if (!operationId) return;
    setSubmitting(true);
    setError("");
    try {
      // Сторно ОПЕРАЦИИ по её id (НЕ /entries/{operationId}: id операции ≠ id проводки).
      await api(`/api/finance/operations/${operationId}/reverse`, { method: "POST", body: {} });
      await globalMutate("/api/finance/operations");
      onSuccess?.();
      onClose();
    } catch (e) {
      setError(extractErrorMessage(e));
    } finally {
      setSubmitting(false);
      setConfirmReverse(false);
    }
  }

  const isViewMode = existingOp?.status === "posted" || existingOp?.status === "reversed";
  const isReadOnly = isViewMode;
  const modalTitle = existingOp
    ? `Операция ${existingOp.number ?? `#${existingOp.id}`}`
    : "Новая операция";

  const footerNode = (
    <div className="flex items-center justify-between w-full">
      <button type="button" onClick={onClose} className="btn-ghost" disabled={submitting}>
        {isReadOnly ? "Закрыть" : "Отмена"}
      </button>
      {!isReadOnly && (
        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={handleSaveDraft}
            className="btn-secondary"
            disabled={submitting}
          >
            {submitting ? "Сохраняем…" : "Сохранить черновик"}
          </button>
          {canPost && (
            <button
              type="button"
              onClick={handlePost}
              className="btn-primary"
              disabled={submitting || !isValid()}
            >
              {submitting ? "Проводим…" : "Провести"}
            </button>
          )}
        </div>
      )}
      {isReadOnly && existingOp?.status === "posted" && canPost && (
        <button
          type="button"
          onClick={() => setConfirmReverse(true)}
          className="btn-secondary text-danger"
          disabled={submitting}
        >
          <i className="bi bi-arrow-counterclockwise mr-1" />
          Сторнировать
        </button>
      )}
    </div>
  );

  return (
    <>
      <Modal open={open} title={modalTitle} onClose={onClose} width="lg" footer={footerNode}>
        {existingOp && (
          <div className="mb-4 flex items-center gap-2">
            <OperationStatusBadge status={existingOp.status} />
            <span className="text-sm text-gray-500">{existingOp.op_date?.slice(0, 10)}</span>
          </div>
        )}

        {/* Direction selector */}
        {!isReadOnly && (
          <div className="flex gap-2 mb-5">
            {DIRECTION_OPTIONS.map((opt) => (
              <button
                key={opt.value}
                type="button"
                onClick={() => handleDirectionChange(opt.value)}
                className={`flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                  direction === opt.value
                    ? "bg-primary text-white"
                    : "bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600"
                }`}
              >
                <i className={`bi ${opt.icon}`} />
                {opt.label}
              </button>
            ))}
          </div>
        )}

        {isReadOnly && existingOp && (
          <div className="mb-4">
            <span className="text-sm font-medium text-gray-700 dark:text-gray-300">
              {direction === "in" ? "↑ Приход" : direction === "out" ? "↓ Расход" : "⇄ Перевод"}
            </span>
          </div>
        )}

        {/* Main section */}
        <div className="border border-gray-200 dark:border-gray-700 rounded-lg p-4 space-y-4 mb-4">
          <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-300">Основное</h3>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="label">Юрлицо <span className="text-danger">*</span></label>
              {isReadOnly ? (
                <p className="text-sm text-gray-800 dark:text-gray-200">{existEntityName ?? "—"}</p>
              ) : (
                <select className="input" value={entityId} onChange={(e) => setEntityId(e.target.value)} disabled={submitting}>
                  <option value="">Выберите…</option>
                  {entities?.map((e) => (
                    <option key={e.id} value={String(e.id)}>{e.name}</option>
                  ))}
                </select>
              )}
            </div>
            <div>
              <label className="label">Тип операции <span className="text-danger">*</span></label>
              {isReadOnly ? (
                <p className="text-sm text-gray-800 dark:text-gray-200">{existOpTypeName ?? "—"}</p>
              ) : (
                <select className="input" value={opTypeId} onChange={(e) => setOpTypeId(e.target.value)} disabled={submitting}>
                  <option value="">Выберите…</option>
                  {filteredOpTypes.map((t) => (
                    <option key={t.id} value={String(t.id)}>{t.name}</option>
                  ))}
                </select>
              )}
            </div>
          </div>

          <div className="grid grid-cols-2 gap-4">
            {(direction === "out" || direction === "transfer") && (
              <div>
                <label className="label">Счёт списания <span className="text-danger">*</span></label>
                {isReadOnly ? (
                  <p className="text-sm text-gray-800 dark:text-gray-200">{existAccFromName ?? "—"}</p>
                ) : (
                  <select className="input" value={accountFromId} onChange={(e) => setAccountFromId(e.target.value)} disabled={submitting}>
                    <option value="">Выберите…</option>
                    {fromAccounts.map((a) => (
                      <option key={a.id} value={String(a.id)}>{a.name} ({a.currency})</option>
                    ))}
                  </select>
                )}
              </div>
            )}
            {(direction === "in" || direction === "transfer") && (
              <div>
                <label className="label">Счёт зачисления <span className="text-danger">*</span></label>
                {isReadOnly ? (
                  <p className="text-sm text-gray-800 dark:text-gray-200">{existAccToName ?? "—"}</p>
                ) : (
                  <select className="input" value={accountToId} onChange={(e) => setAccountToId(e.target.value)} disabled={submitting}>
                    <option value="">Выберите…</option>
                    {toAccounts.filter((a) => direction !== "transfer" || String(a.id) !== accountFromId).map((a) => (
                      <option key={a.id} value={String(a.id)}>{a.name} ({a.currency})</option>
                    ))}
                  </select>
                )}
              </div>
            )}
          </div>

          <div className="grid grid-cols-3 gap-4">
            <div>
              <label className="label">Сумма <span className="text-danger">*</span></label>
              {isReadOnly ? (
                existingOp && <MoneyCell amount={existingOp.amount} currency={existingOp.currency} direction={existingOp.direction} />
              ) : (
                <input
                  type="number"
                  min="0.01"
                  step="0.01"
                  className="input"
                  placeholder="0.00"
                  value={amount}
                  onChange={(e) => setAmount(e.target.value)}
                  disabled={submitting}
                />
              )}
            </div>
            <div>
              <CurrencySelect label="Валюта" value={currency} onChange={setCurrency} disabled={submitting || isReadOnly} />
            </div>
            <div>
              <label className="label">Дата <span className="text-danger">*</span></label>
              {isReadOnly ? (
                <p className="text-sm text-gray-800 dark:text-gray-200">{existingOp?.op_date?.slice(0, 10)}</p>
              ) : (
                <DatePicker
                  value={opDate}
                  onChange={(v) => setOpDate(v ?? "")}
                  disabled={submitting}
                  required
                />
              )}
            </div>
          </div>

          {direction === "transfer" && !isReadOnly && (
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="label">Получено (сумма в валюте счёта-получателя)</label>
                <input
                  type="number"
                  min="0.01"
                  step="0.01"
                  className="input"
                  placeholder="Если отличается от суммы"
                  value={toAmount}
                  onChange={(e) => setToAmount(e.target.value)}
                  disabled={submitting}
                />
              </div>
            </div>
          )}

          <div>
            <label className="label">Назначение платежа</label>
            {isReadOnly ? (
              <p className="text-sm text-gray-800 dark:text-gray-200">{existingOp?.purpose ?? "—"}</p>
            ) : (
              <textarea
                rows={2}
                className="input resize-none"
                placeholder="Укажи назначение платежа…"
                value={purpose}
                onChange={(e) => setPurpose(e.target.value)}
                disabled={submitting}
              />
            )}
          </div>
        </div>

        {/* VAT section — only for vat_enabled entities and non-transfer */}
        {selectedEntity?.vat_enabled && direction !== "transfer" && (
          <div className="border border-gray-200 dark:border-gray-700 rounded-lg p-4 space-y-4 mb-4">
            <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-300">НДС</h3>
            <div className="grid grid-cols-3 gap-4">
              <div>
                <label className="label">Ставка НДС</label>
                {isReadOnly ? (
                  <p className="text-sm text-gray-800 dark:text-gray-200">
                    {vatRates?.find((v) => String(v.id) === vatRateId)?.name ?? "—"}
                  </p>
                ) : (
                  <select className="input" value={vatRateId} onChange={(e) => { setVatRateId(e.target.value); setVatAmount(""); }} disabled={submitting}>
                    <option value="">Без НДС</option>
                    {vatRates?.map((v) => (
                      <option key={v.id} value={String(v.id)}>{v.name} ({v.rate_pct}%)</option>
                    ))}
                  </select>
                )}
              </div>
              {vatRateId && (
                <>
                  <div>
                    <label className="label">Сумма НДС</label>
                    {isReadOnly ? (
                      <p className="text-sm text-gray-800 dark:text-gray-200">{existingOp?.vat_amount ?? vatAmountComputed}</p>
                    ) : (
                      <input
                        type="number"
                        min="0"
                        step="0.01"
                        className="input"
                        value={vatAmount || vatAmountComputed}
                        onChange={(e) => setVatAmount(e.target.value)}
                        disabled={submitting}
                      />
                    )}
                  </div>
                  <div>
                    <label className="label">Сумма без НДС</label>
                    <p className="input bg-gray-50 dark:bg-gray-700 text-gray-600 dark:text-gray-400 text-sm">
                      {formatAmount(amountNet)}
                    </p>
                  </div>
                </>
              )}
            </div>
            <p className="text-xs text-gray-400">НДС-проводка появится в Ф5. Сейчас сохраняется справочно.</p>
          </div>
        )}

        {/* Cashflow category / split */}
        {direction !== "transfer" && (
          <div className="border border-gray-200 dark:border-gray-700 rounded-lg p-4 space-y-3 mb-4">
            <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-300">Статья ДДС</h3>
            {!isReadOnly && (
              <div className="flex gap-4 text-sm">
                <label className="flex items-center gap-2 cursor-pointer">
                  <input type="radio" checked={!useSplit} onChange={() => setUseSplit(false)} className="accent-primary" />
                  Одна статья
                </label>
                <label className="flex items-center gap-2 cursor-pointer">
                  <input type="radio" checked={useSplit} onChange={() => setUseSplit(true)} className="accent-primary" />
                  Разнести по нескольким
                </label>
              </div>
            )}

            {!useSplit ? (
              isReadOnly ? (
                <p className="text-sm text-gray-800 dark:text-gray-200">{existCatName ?? "—"}</p>
              ) : (
                <select className="input" value={categoryId} onChange={(e) => setCategoryId(e.target.value)} disabled={submitting}>
                  <option value="">Без статьи</option>
                  {filteredCats.map((c) => (
                    <option key={c.id} value={String(c.id)}>{c.name}</option>
                  ))}
                </select>
              )
            ) : (
              !isReadOnly && (
                <AllocationEditor
                  lines={allocations}
                  onChange={setAllocations}
                  totalAmount={amountNum}
                  currency={currency}
                  flowHint={direction === "in" ? "inflow" : "outflow"}
                />
              )
            )}

            {isReadOnly && existingAlloc && existingAlloc.length > 0 && (
              <div className="space-y-1">
                {existingAlloc.map((a) => (
                  <div key={a.id} className="flex justify-between text-sm">
                    <span className="text-gray-600 dark:text-gray-400">{allocCatName(a.cashflow_category_id) ?? "—"}</span>
                    <span className="tabular-nums font-medium">{formatCurrency(a.amount, existingOp?.currency)}</span>
                  </div>
                ))}
              </div>
            )}
          </div>
        )}

        {error && (
          <p className="text-sm text-danger mt-2 p-3 bg-red-50 dark:bg-red-900/20 rounded-md">
            <i className="bi bi-exclamation-triangle mr-1" />
            {error}
          </p>
        )}
      </Modal>

      {/* Confirm reverse dialog */}
      {confirmReverse && (
        <div className="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
          <div className="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full p-6">
            <h3 className="text-h5 mb-2 dark:text-gray-100">Создать сторно-операцию?</h3>
            <p className="text-sm text-gray-700 dark:text-gray-300 mb-5">
              Проводка будет зеркально отменена в текущем периоде.
            </p>
            <div className="flex justify-end gap-2">
              <button onClick={() => setConfirmReverse(false)} className="btn-ghost" disabled={submitting}>Отмена</button>
              <button onClick={handleReverse} className="btn-secondary text-danger" disabled={submitting}>
                <i className="bi bi-arrow-counterclockwise mr-1" />
                {submitting ? "Сторнируем…" : "Сторнировать"}
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}
