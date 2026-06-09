"use client";

import { useState } from "react";
import useSWR, { mutate } from "swr";
import { Modal } from "@/components/Modal";
import { DatePicker } from "@/components/ui/DatePicker";
import { api, fetcher } from "@/lib/api";
import type { FinLegalEntity, FinAct, FinActDetail } from "@/lib/types";
import { InvoiceLineEditor, type LineFormData } from "./InvoiceLineEditor";

function defaultLine(): LineFormData {
  return { _id: crypto.randomUUID(), name: "", qty: "1", unit_price: "0", vat_rate_id: null, sort_order: 0 };
}

interface Props {
  act?: FinActDetail | null;
  onClose: () => void;
  onSuccess?: (act: FinAct) => void;
}

export function ActFormModal({ act, onClose, onSuccess }: Props) {
  const isEdit = act != null;
  const { data: entities = [] } = useSWR<FinLegalEntity[]>("/api/finance/legal-entities", fetcher);
  const today = new Date().toISOString().slice(0, 10);

  const { data: counterparties } = useSWR<{ id: number; name: string }[]>(
    "/api/counterparties?limit=200",
    fetcher
  );

  const [legalEntityId, setLegalEntityId] = useState(
    act ? String(act.legal_entity_id) : ""
  );
  const [counterpartyId, setCounterpartyId] = useState(
    act ? String(act.counterparty_company_id) : ""
  );
  const [actDate, setActDate] = useState(act?.act_date ?? today);
  const [currency, setCurrency] = useState(act?.currency ?? "RUB");
  const [purpose, setPurpose] = useState(act?.purpose ?? "");
  const [invoiceId, setInvoiceId] = useState(act?.invoice_id ? String(act.invoice_id) : "");
  const [lines, setLines] = useState<LineFormData[]>(
    act?.lines?.length
      ? act.lines.map((l) => ({
          _id: crypto.randomUUID(),
          name: l.name,
          qty: String(l.qty),
          unit_price: String(l.unit_price),
          vat_rate_id: l.vat_rate_id,
          sort_order: l.sort_order,
        }))
      : [defaultLine()]
  );
  const [error, setError] = useState("");
  const [submitting, setSubmitting] = useState(false);

  // При выборе юрлица в режиме создания подставляем его функциональную валюту.
  // Пользователь может сменить вручную. При редактировании валюту не трогаем.
  function handleLegalEntityChange(value: string) {
    setLegalEntityId(value);
    if (isEdit) return;
    const ent = entities.find((e) => String(e.id) === value);
    if (ent?.functional_currency) setCurrency(ent.functional_currency);
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError("");

    if (!legalEntityId || !counterpartyId || !actDate || !currency) {
      setError("Заполните обязательные поля: юрлицо, контрагент, дата, валюта");
      return;
    }
    if (lines.some((l) => !l.name.trim())) {
      setError("Укажите наименование для каждой позиции");
      return;
    }

    setSubmitting(true);
    try {
      const body = {
        legal_entity_id: parseInt(legalEntityId),
        counterparty_company_id: parseInt(counterpartyId),
        act_date: actDate,
        currency,
        purpose: purpose || null,
        invoice_id: invoiceId ? parseInt(invoiceId) : null,
        lines: lines.map((l, i) => ({
          name: l.name,
          qty: l.qty,
          unit_price: l.unit_price,
          vat_rate_id: l.vat_rate_id,
          sort_order: i,
        })),
      };

      const result = isEdit
        ? await api<FinAct>(`/api/finance/acts/${act!.id}`, { method: "PATCH", body })
        : await api<FinAct>("/api/finance/acts", { method: "POST", body });

      await mutate("/api/finance/acts");
      if (isEdit) await mutate(`/api/finance/acts/${act!.id}`);
      onSuccess?.(result);
      onClose();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Ошибка при сохранении");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal
      open
      title={isEdit ? "Редактировать акт" : "Новый акт выполненных работ"}
      onClose={onClose}
      width="xl"
    >
      <form onSubmit={handleSubmit}>
        <div className="p-5 space-y-4">
          {/* Пометка об отсутствии проводки */}
          <div className="bg-info/10 dark:bg-blue-900/20 rounded-lg p-3 text-sm flex items-start gap-2">
            <i className="bi bi-info-circle text-info mt-0.5" />
            <span className="text-gray-700 dark:text-gray-300">
              Акт — документ подтверждения выполнения работ/услуг. Финансовую проводку{" "}
              <strong>не создаёт</strong>. Для списания используйте инвойс.
            </span>
          </div>

          <div className="border rounded-lg p-4 space-y-4 dark:border-gray-700">
            <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-300">Основные данные</h3>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label className="label">Юрлицо *</label>
                <select
                  className="input w-full"
                  value={legalEntityId}
                  onChange={(e) => handleLegalEntityChange(e.target.value)}
                >
                  <option value="">— Выберите юрлицо —</option>
                  {entities.map((e) => (
                    <option key={e.id} value={e.id}>
                      {e.name}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="label">Контрагент *</label>
                <select
                  className="input w-full"
                  value={counterpartyId}
                  onChange={(e) => setCounterpartyId(e.target.value)}
                >
                  <option value="">— Выберите контрагента —</option>
                  {counterparties?.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <DatePicker
                  label="Дата акта *"
                  value={actDate}
                  onChange={(v) => setActDate(v ?? "")}
                  required
                />
              </div>

              <div>
                <label className="label">Валюта *</label>
                <select
                  className="input w-full"
                  value={currency}
                  onChange={(e) => setCurrency(e.target.value)}
                >
                  {!["RUB", "KZT", "USD", "EUR"].includes(currency) && currency && (
                    <option value={currency}>{currency}</option>
                  )}
                  <option value="RUB">RUB</option>
                  <option value="KZT">KZT</option>
                  <option value="USD">USD</option>
                  <option value="EUR">EUR</option>
                </select>
              </div>

              <div>
                <label className="label">Связанный инвойс</label>
                <input
                  type="number"
                  className="input w-full"
                  placeholder="ID инвойса (необязательно)"
                  value={invoiceId}
                  onChange={(e) => setInvoiceId(e.target.value)}
                />
              </div>

              <div>
                <label className="label">Назначение / описание</label>
                <input
                  type="text"
                  className="input w-full"
                  placeholder="Оказание услуг по договору…"
                  value={purpose}
                  onChange={(e) => setPurpose(e.target.value)}
                />
              </div>
            </div>
          </div>

          <div className="border rounded-lg p-4 dark:border-gray-700">
            <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">Позиции</h3>
            <InvoiceLineEditor lines={lines} onChange={setLines} />
          </div>

          {error && (
            <p className="text-sm text-danger bg-red-50 dark:bg-red-900/20 rounded p-2">{error}</p>
          )}
        </div>

        <div className="flex justify-end gap-2 px-5 py-4 border-t border-gray-100 dark:border-gray-700">
          <button type="button" className="btn-ghost" onClick={onClose} disabled={submitting}>
            Отмена
          </button>
          <button type="submit" className="btn-primary" disabled={submitting}>
            {submitting
              ? isEdit ? "Сохранение…" : "Создание…"
              : isEdit ? "Сохранить" : "Создать акт"}
          </button>
        </div>
      </form>
    </Modal>
  );
}
