"use client";

import { useState } from "react";
import { api } from "@/lib/api";
import { useSWRConfig } from "swr";
import type { CommissionRule } from "@/lib/types";

interface Props {
  onSaved?: () => void;
  editRule?: CommissionRule | null;
  inModal?: boolean;
}

export function CommissionRuleForm({ onSaved, editRule, inModal }: Props) {
  const { mutate } = useSWRConfig();

  const [name, setName] = useState(editRule?.name ?? "");
  const [ratePct, setRatePct] = useState(editRule?.rate_pct != null ? String(editRule.rate_pct) : "");
  const [base, setBase] = useState(editRule?.base ?? "new_income_payments");
  const [scope, setScope] = useState(editRule?.scope ?? "personal");
  const [firstPaymentOnly, setFirstPaymentOnly] = useState(editRule?.first_payment_only ?? true);
  const [requiresContract, setRequiresContract] = useState(editRule?.requires_signed_contract ?? true);
  const [amountMustMatch, setAmountMustMatch] = useState(editRule?.amount_must_match_plan ?? false);
  const [payoutTiming, setPayoutTiming] = useState(editRule?.payout_timing ?? "immediately");
  const [payoutNote, setPayoutNote] = useState(editRule?.payout_note ?? "");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!name.trim() || !ratePct) return;
    setSubmitting(true);
    setError(null);
    try {
      const body = {
        name: name.trim(),
        rate_pct: Number(ratePct),
        base,
        scope,
        first_payment_only: firstPaymentOnly,
        requires_signed_contract: requiresContract,
        amount_must_match_plan: amountMustMatch,
        payout_timing: payoutTiming,
        payout_note: payoutNote || null,
      };

      if (editRule) {
        await api(`/admin/commission-rules/${editRule.id}`, { method: "PATCH", body });
      } else {
        await api("/admin/commission-rules", { method: "POST", body });
      }
      mutate("/admin/commission-rules");
      onSaved?.();
    } catch {
      setError("Не удалось сохранить правило. Проверьте данные.");
    } finally {
      setSubmitting(false);
    }
  }

  const formId = inModal ? "commission-rule-form" : undefined;

  return (
    <form id={formId} onSubmit={handleSubmit} className="space-y-4">
      <div>
        <label className="label">Название правила *</label>
        <input
          type="text"
          className="input"
          value={name}
          onChange={(e) => setName(e.target.value)}
          placeholder="10% от новых поступлений"
          required
        />
      </div>

      <div>
        <label className="label">Ставка, % *</label>
        <input
          type="number"
          className="input"
          value={ratePct}
          onChange={(e) => setRatePct(e.target.value)}
          min={0.01}
          max={100}
          step={0.01}
          placeholder="10"
          required
        />
      </div>

      <div>
        <label className="label">База расчёта</label>
        <select className="input" value={base} onChange={(e) => setBase(e.target.value)}>
          <option value="new_income_payments">Новые поступления</option>
          <option value="all_payments">Любые поступления</option>
        </select>
      </div>

      <div>
        <label className="label">Scope</label>
        <select className="input" value={scope} onChange={(e) => setScope(e.target.value)}>
          <option value="personal">Только личные сделки</option>
          <option value="all">Все сделки</option>
        </select>
      </div>

      <div className="space-y-2">
        <label className="flex items-center gap-2 cursor-pointer text-sm">
          <input
            type="checkbox"
            className="rounded border-gray-300 text-primary"
            checked={firstPaymentOnly}
            onChange={(e) => setFirstPaymentOnly(e.target.checked)}
          />
          Только первый платёж от клиента
        </label>
        <label className="flex items-center gap-2 cursor-pointer text-sm">
          <input
            type="checkbox"
            className="rounded border-gray-300 text-primary"
            checked={requiresContract}
            onChange={(e) => setRequiresContract(e.target.checked)}
          />
          Требуется подписанный договор
        </label>
        <label className="flex items-center gap-2 cursor-pointer text-sm">
          <input
            type="checkbox"
            className="rounded border-gray-300 text-primary"
            checked={amountMustMatch}
            onChange={(e) => setAmountMustMatch(e.target.checked)}
          />
          Сумма должна совпадать с планом платежа
        </label>
      </div>

      <div>
        <label className="label">Момент выплаты</label>
        <select className="input" value={payoutTiming} onChange={(e) => setPayoutTiming(e.target.value)}>
          <option value="immediately">Сразу</option>
          <option value="end_of_month">В конце месяца</option>
          <option value="end_of_quarter">В конце квартала</option>
        </select>
      </div>

      <div>
        <label className="label">Примечание о выплате</label>
        <textarea
          className="input"
          rows={2}
          value={payoutNote}
          onChange={(e) => setPayoutNote(e.target.value)}
          placeholder="Необязательно..."
        />
      </div>

      {error && <p className="text-sm text-danger">{error}</p>}

      {!inModal && (
        <button type="submit" className="btn-primary" disabled={submitting}>
          {submitting ? "Сохранение..." : editRule ? "Сохранить" : "Создать правило"}
        </button>
      )}
    </form>
  );
}
