"use client";

import { useState } from "react";
import { Modal } from "@/components/Modal";
import { CurrencySelect } from "./CurrencySelect";
import { DatePicker } from "@/components/ui/DatePicker";
import { api } from "@/lib/api";
import type { CurrencyRate } from "@/lib/types";

interface Props {
  open: boolean;
  onClose: () => void;
  onSaved: () => void;
  editRate?: CurrencyRate | null;
}

function todayStr() {
  return new Date().toISOString().slice(0, 10);
}

export function ManualRateModal({ open, onClose, onSaved, editRate }: Props) {
  const [fromCurrency, setFromCurrency] = useState(editRate?.from_currency ?? "RUB");
  const [toCurrency, setToCurrency] = useState(editRate?.to_currency ?? "UZS");
  const [rate, setRate] = useState(editRate ? String(editRate.rate) : "");
  const [rateDate, setRateDate] = useState(editRate?.rate_date ?? todayStr());
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!rate || !rateDate) return;
    setSubmitting(true);
    setError(null);
    try {
      if (editRate) {
        await api(`/admin/currency-rates/${editRate.id}`, {
          method: "PATCH",
          body: { from_currency: fromCurrency, to_currency: toCurrency, rate: Number(rate), rate_date: rateDate },
        });
      } else {
        await api("/admin/currency-rates", {
          method: "POST",
          body: { from_currency: fromCurrency, to_currency: toCurrency, rate: Number(rate), rate_date: rateDate },
        });
      }
      onSaved();
      onClose();
    } catch {
      setError("Не удалось сохранить курс. Проверьте данные.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal
      open={open}
      title={editRate ? "Редактировать курс" : "Добавить курс вручную"}
      onClose={onClose}
      footer={
        <>
          <button type="button" onClick={onClose} className="btn-ghost">Отмена</button>
          <button
            form="manual-rate-form"
            type="submit"
            className="btn-primary"
            disabled={submitting}
          >
            {submitting ? "Сохранение..." : "Сохранить"}
          </button>
        </>
      }
    >
      <form id="manual-rate-form" onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-2 gap-4">
          <div>
            <CurrencySelect label="Из валюты" value={fromCurrency} onChange={setFromCurrency} />
          </div>
          <div>
            <CurrencySelect label="В валюту" value={toCurrency} onChange={setToCurrency} />
          </div>
        </div>

        <div>
          <label className="label">Курс *</label>
          <input
            type="number"
            className="input"
            value={rate}
            onChange={(e) => setRate(e.target.value)}
            step="0.00000001"
            min="0"
            required
          />
        </div>

        <div>
          <DatePicker
            label="Дата *"
            value={rateDate}
            onChange={(v) => setRateDate(v ?? "")}
            required
          />
        </div>

        {error && <p className="text-sm text-danger">{error}</p>}
      </form>
    </Modal>
  );
}
