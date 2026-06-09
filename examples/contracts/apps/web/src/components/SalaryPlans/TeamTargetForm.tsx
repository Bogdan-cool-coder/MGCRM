"use client";

import { useState } from "react";
import { api } from "@/lib/api";
import { useSWRConfig } from "swr";
import { AmountWithConversion } from "@/components/Currency/AmountWithConversion";
import type { TeamTarget } from "@/lib/types";

interface Props {
  onSaved?: () => void;
  editTarget?: TeamTarget | null;
  inModal?: boolean;
}

const MONTHS_RU = [
  "Январь", "Февраль", "Март", "Апрель", "Май", "Июнь",
  "Июль", "Август", "Сентябрь", "Октябрь", "Ноябрь", "Декабрь",
];

export function TeamTargetForm({ onSaved, editTarget, inModal }: Props) {
  const { mutate } = useSWRConfig();
  const now = new Date();

  const [year, setYear] = useState(String(editTarget?.year ?? now.getFullYear()));
  const [month, setMonth] = useState(String(editTarget?.month ?? now.getMonth() + 1));
  const [metric, setMetric] = useState(editTarget?.metric ?? "new_income");
  const [targetAmount, setTargetAmount] = useState<number | "">(editTarget?.target_amount ?? "");
  const [targetCurrency, setTargetCurrency] = useState(editTarget?.target_currency ?? "KZT");
  const [bonusPool, setBonusPool] = useState<number | "">(editTarget?.bonus_pool_amount ?? "");
  const [bonusCurrency, setBonusCurrency] = useState(editTarget?.bonus_pool_currency ?? "KZT");
  const [bonusPerManager, setBonusPerManager] = useState(editTarget?.bonus_per_extra_manager != null ? String(editTarget.bonus_per_extra_manager) : "");
  const [minThreshold, setMinThreshold] = useState(editTarget?.min_threshold_pct != null ? String(editTarget.min_threshold_pct) : "");
  const [proportionalPct, setProportionalPct] = useState(editTarget?.proportional_pct != null ? String(editTarget.proportional_pct) : "");
  const [equalPct, setEqualPct] = useState(editTarget?.equal_pct != null ? String(editTarget.equal_pct) : "");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const propNum = Number(proportionalPct);
  const equalNum = Number(equalPct);
  // splitValid true если оба поля заполнены и сумма = 100, или оба пустые (не валидируем пока не заполнено)
  const bothFilled = proportionalPct !== "" && equalPct !== "";
  const splitValid = !bothFilled || propNum + equalNum === 100;

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!splitValid) {
      setError("Сумма пропорции должна быть 100%");
      return;
    }
    setSubmitting(true);
    setError(null);
    try {
      const body = {
        year: Number(year),
        month: Number(month),
        metric,
        target_amount: Number(targetAmount),
        target_currency: targetCurrency,
        bonus_pool_amount: Number(bonusPool),
        bonus_pool_currency: bonusCurrency,
        bonus_per_extra_manager: Number(bonusPerManager),
        min_threshold_pct: Number(minThreshold),
        proportional_pct: propNum,
        equal_pct: equalNum,
      };

      if (editTarget) {
        await api(`/admin/team-targets/${editTarget.id}`, { method: "PATCH", body });
      } else {
        await api("/admin/team-targets", { method: "POST", body });
      }
      mutate("/admin/team-targets");
      onSaved?.();
    } catch {
      setError("Не удалось сохранить цель. Проверьте данные.");
    } finally {
      setSubmitting(false);
    }
  }

  const formId = inModal ? "team-target-form" : undefined;

  return (
    <form id={formId} onSubmit={handleSubmit} className="space-y-4">
      <div className="grid grid-cols-2 gap-3">
        <div>
          <label className="label">Год *</label>
          <input
            type="number"
            className="input"
            value={year}
            onChange={(e) => setYear(e.target.value)}
            min={2024}
            max={2030}
            required
          />
        </div>
        <div>
          <label className="label">Месяц *</label>
          <select className="input" value={month} onChange={(e) => setMonth(e.target.value)}>
            {MONTHS_RU.map((m, i) => (
              <option key={i + 1} value={i + 1}>{m}</option>
            ))}
          </select>
        </div>
      </div>

      <div>
        <label className="label">Метрика</label>
        <select className="input" value={metric} onChange={(e) => setMetric(e.target.value)}>
          <option value="new_income">Новые поступления</option>
          <option value="ftm">FTM встречи</option>
        </select>
      </div>

      <AmountWithConversion
        label="Плановая сумма *"
        value={targetAmount}
        currency={targetCurrency}
        onValueChange={setTargetAmount}
        onCurrencyChange={setTargetCurrency}
        required
      />

      <AmountWithConversion
        label="Пул командного бонуса"
        value={bonusPool}
        currency={bonusCurrency}
        onValueChange={setBonusPool}
        onCurrencyChange={setBonusCurrency}
      />

      <div>
        <label className="label">Бонус за доп. менеджера</label>
        <input
          type="number"
          className="input"
          value={bonusPerManager}
          onChange={(e) => setBonusPerManager(e.target.value)}
          min={0}
          placeholder="100000"
        />
      </div>

      <div>
        <label className="label">Мин. порог выполнения, %</label>
        <input
          type="number"
          className="input"
          value={minThreshold}
          onChange={(e) => setMinThreshold(e.target.value)}
          min={0}
          max={100}
          placeholder="80"
        />
      </div>

      <div className="grid grid-cols-2 gap-3">
        <div>
          <label className="label">Пропорционально, %</label>
          <input
            type="number"
            className={`input ${!splitValid ? "border-danger" : ""}`}
            value={proportionalPct}
            onChange={(e) => setProportionalPct(e.target.value)}
            min={0}
            max={100}
            placeholder="60"
          />
        </div>
        <div>
          <label className="label">Поровну, %</label>
          <input
            type="number"
            className={`input ${!splitValid ? "border-danger" : ""}`}
            value={equalPct}
            onChange={(e) => setEqualPct(e.target.value)}
            min={0}
            max={100}
            placeholder="40"
          />
        </div>
      </div>
      {!splitValid && (
        <p className="text-xs text-danger">Сумма пропорции должна равняться 100%</p>
      )}

      {error && <p className="text-sm text-danger">{error}</p>}

      {!inModal && (
        <button type="submit" className="btn-primary" disabled={submitting || !splitValid}>
          {submitting ? "Сохранение..." : editTarget ? "Сохранить" : "Создать цель"}
        </button>
      )}
    </form>
  );
}
