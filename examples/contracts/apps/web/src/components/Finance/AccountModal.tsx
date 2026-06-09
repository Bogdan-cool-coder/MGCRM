"use client";

import { useState, useEffect } from "react";
import useSWR, { mutate as globalMutate } from "swr";
import { Modal } from "@/components/Modal";
import { CurrencySelect } from "@/components/Currency/CurrencySelect";
import { api, ApiError, fetcher } from "@/lib/api";
import type { FinLegalEntity, FinMoneyAccount, FinAccountType, FinAccountGl } from "@/lib/types";

const ACCOUNT_TYPES: { value: FinAccountType; label: string }[] = [
  { value: "bank",      label: "Банк" },
  { value: "cash",      label: "Касса" },
  { value: "acquiring", label: "Эквайринг" },
  { value: "ewallet",   label: "Кошелёк" },
];

// Дефолтный GL-код денежного счёта под тип (план счетов Ф0: 1010/1020/1030/1040).
const DEFAULT_GL_CODE_BY_TYPE: Record<FinAccountType, string> = {
  cash:      "1010",
  bank:      "1020",
  acquiring: "1030",
  ewallet:   "1040",
};

interface Props {
  open: boolean;
  onClose: () => void;
  existing?: FinMoneyAccount | null;
  onSuccess?: () => void;
}

export function AccountModal({ open, onClose, existing, onSuccess }: Props) {
  const { data: entities } = useSWR<FinLegalEntity[]>("/api/finance/legal-entities", fetcher);
  const { data: glAccounts } = useSWR<FinAccountGl[]>("/api/finance/chart-of-accounts", fetcher);
  const moneyGl = (glAccounts ?? []).filter((g) => g.is_money);

  const [entityId, setEntityId] = useState("");
  const [name, setName] = useState("");
  const [accountType, setAccountType] = useState<FinAccountType>("bank");
  const [glAccountId, setGlAccountId] = useState("");
  const [currency, setCurrency] = useState("KZT");
  const [initialBalance, setInitialBalance] = useState("0");
  const [isActive, setIsActive] = useState(true);

  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    if (existing) {
      setEntityId(String(existing.legal_entity_id));
      setName(existing.name);
      setAccountType(existing.account_type);
      setGlAccountId(String(existing.gl_account_id));
      setCurrency(existing.currency);
      setInitialBalance(String(existing.initial_balance ?? 0));
      setIsActive(existing.is_active);
    } else {
      setEntityId(entities?.[0] ? String(entities[0].id) : "");
      setName("");
      setAccountType("bank");
      setGlAccountId("");
      setCurrency("KZT");
      setInitialBalance("0");
      setIsActive(true);
    }
    setError("");
  }, [existing, open, entities]);

  // Для нового счёта — авто-выбор GL по типу (1010/1020/1030/1040), если ещё не выбран.
  useEffect(() => {
    if (existing || glAccountId) return;
    const code = DEFAULT_GL_CODE_BY_TYPE[accountType];
    const match = moneyGl.find((g) => g.code === code);
    if (match) setGlAccountId(String(match.id));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [accountType, existing, glAccounts]);

  async function handleSubmit() {
    if (!entityId || !name.trim()) {
      setError("Заполни обязательные поля.");
      return;
    }
    if (!existing && !glAccountId) {
      setError("Выберите GL-счёт (денежный).");
      return;
    }
    setSubmitting(true);
    setError("");
    try {
      if (existing) {
        // PATCH — только изменяемые поля (name/account_type/is_active).
        await api(`/api/finance/money-accounts/${existing.id}`, {
          method: "PATCH",
          body: { name: name.trim(), account_type: accountType, is_active: isActive },
        });
      } else {
        await api("/api/finance/money-accounts", {
          method: "POST",
          body: {
            legal_entity_id: parseInt(entityId),
            gl_account_id: parseInt(glAccountId),
            name: name.trim(),
            account_type: accountType,
            currency,
            initial_balance: parseFloat(initialBalance) || 0,
          },
        });
      }
      await globalMutate("/api/finance/money-accounts");
      onSuccess?.();
      onClose();
    } catch (e) {
      if (e instanceof ApiError) {
        const d = e.detail;
        setError(typeof d === "string" ? d : "Не удалось сохранить счёт.");
      } else {
        setError("Не удалось сохранить счёт.");
      }
    } finally {
      setSubmitting(false);
    }
  }

  const initialBalanceNum = parseFloat(initialBalance) || 0;

  const footer = (
    <>
      <button type="button" onClick={onClose} className="btn-ghost" disabled={submitting}>Отмена</button>
      <button type="button" onClick={handleSubmit} className="btn-primary" disabled={submitting || !entityId || !name.trim()}>
        {submitting ? "Сохраняем…" : existing ? "Сохранить" : "Создать"}
      </button>
    </>
  );

  return (
    <Modal
      open={open}
      title={existing ? "Редактировать счёт" : "Создать счёт"}
      onClose={onClose}
      footer={footer}
    >
      <div className="space-y-4">
        <div>
          <label className="label">Юрлицо <span className="text-danger">*</span></label>
          <select className="input" value={entityId} onChange={(e) => setEntityId(e.target.value)} disabled={submitting}>
            <option value="">Выберите…</option>
            {entities?.map((e) => (
              <option key={e.id} value={String(e.id)}>{e.name}</option>
            ))}
          </select>
        </div>

        <div>
          <label className="label">Название <span className="text-danger">*</span></label>
          <input
            type="text"
            className="input"
            placeholder="БЦК — основной р/с"
            value={name}
            onChange={(e) => setName(e.target.value)}
            disabled={submitting}
          />
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className="label">Тип</label>
            <select className="input" value={accountType} onChange={(e) => setAccountType(e.target.value as FinAccountType)} disabled={submitting}>
              {ACCOUNT_TYPES.map((t) => (
                <option key={t.value} value={t.value}>{t.label}</option>
              ))}
            </select>
          </div>
          <div>
            <CurrencySelect label="Валюта" value={currency} onChange={setCurrency} disabled={submitting || !!existing} />
          </div>
        </div>

        <div>
          <label className="label">GL-счёт (денежный) <span className="text-danger">*</span></label>
          <select
            className="input"
            value={glAccountId}
            onChange={(e) => setGlAccountId(e.target.value)}
            disabled={submitting || !!existing}
          >
            <option value="">Выберите GL-счёт…</option>
            {moneyGl.map((g) => (
              <option key={g.id} value={String(g.id)}>{g.code} — {g.name}</option>
            ))}
          </select>
          {existing && (
            <p className="text-xs text-gray-400 mt-1">GL-счёт и валюту нельзя изменить после создания.</p>
          )}
        </div>

        <div>
          <label className="label">Начальный остаток</label>
          <input
            type="number"
            min="0"
            step="0.01"
            className="input"
            value={initialBalance}
            onChange={(e) => setInitialBalance(e.target.value)}
            disabled={submitting || !!existing}
          />
          {initialBalanceNum > 0 && !existing && (
            <p className="text-xs text-yellow-600 dark:text-yellow-400 mt-1">
              <i className="bi bi-exclamation-triangle mr-1" />
              Будет создана проводка «Начальный остаток» на счёт 3900.
            </p>
          )}
          {existing && (
            <p className="text-xs text-gray-400 mt-1">Начальный остаток нельзя изменить после создания.</p>
          )}
        </div>

        <div className="flex items-center gap-2">
          <input
            type="checkbox"
            id="acc-active"
            checked={isActive}
            onChange={(e) => setIsActive(e.target.checked)}
            disabled={submitting}
            className="accent-primary"
          />
          <label htmlFor="acc-active" className="text-sm text-gray-700 dark:text-gray-300 cursor-pointer">
            Активен
          </label>
        </div>

        {error && (
          <p className="text-sm text-danger">
            <i className="bi bi-exclamation-triangle mr-1" />{error}
          </p>
        )}
      </div>
    </Modal>
  );
}
