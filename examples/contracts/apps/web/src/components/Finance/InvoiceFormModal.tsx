"use client";

import { useState } from "react";
import useSWR, { mutate } from "swr";
import { Modal } from "@/components/Modal";
import { DatePicker } from "@/components/ui/DatePicker";
import { api, fetcher } from "@/lib/api";
import type { FinLegalEntity, FinInvoice, FinInvoiceDetail } from "@/lib/types";
import { InvoiceLineEditor, type LineFormData } from "./InvoiceLineEditor";

function defaultLine(): LineFormData {
  return { _id: crypto.randomUUID(), name: "", qty: "1", unit_price: "0", vat_rate_id: null, sort_order: 0 };
}

interface Props {
  /** null = создать, otherwise = редактировать */
  invoice?: FinInvoiceDetail | null;
  onClose: () => void;
  onSuccess?: (inv: FinInvoice) => void;
}

export function InvoiceFormModal({ invoice, onClose, onSuccess }: Props) {
  const isEdit = invoice != null;
  const { data: entities = [] } = useSWR<FinLegalEntity[]>("/api/finance/legal-entities", fetcher);

  const today = new Date().toISOString().slice(0, 10);

  const [legalEntityId, setLegalEntityId] = useState(
    invoice ? String(invoice.legal_entity_id) : ""
  );
  const [counterpartyId, setCounterpartyId] = useState(
    invoice ? String(invoice.counterparty_company_id) : ""
  );
  const [issueDate, setIssueDate] = useState(invoice?.issue_date ?? today);
  const [dueDate, setDueDate] = useState(invoice?.due_date ?? "");
  const [currency, setCurrency] = useState(invoice?.currency ?? "RUB");
  const [purpose, setPurpose] = useState(invoice?.purpose ?? "");
  const [lines, setLines] = useState<LineFormData[]>(
    invoice?.lines?.length
      ? invoice.lines.map((l) => ({
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

  // Simple counterparty search
  const { data: counterparties } = useSWR<{ id: number; name: string }[]>(
    "/api/counterparties?limit=200",
    fetcher
  );

  // При выборе юрлица в режиме создания подставляем его функциональную валюту
  // (KZ → KZT и т.п.). Пользователь может сменить вручную. При редактировании
  // валюту инвойса не трогаем.
  function handleLegalEntityChange(value: string) {
    setLegalEntityId(value);
    if (isEdit) return;
    const ent = entities.find((e) => String(e.id) === value);
    if (ent?.functional_currency) setCurrency(ent.functional_currency);
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError("");

    if (!legalEntityId || !counterpartyId || !issueDate || !currency) {
      setError("Заполните обязательные поля: юрлицо, контрагент, дата, валюта");
      return;
    }
    const hasEmptyName = lines.some((l) => !l.name.trim());
    if (hasEmptyName) {
      setError("Укажи наименование для каждой позиции");
      return;
    }

    setSubmitting(true);
    try {
      const body = {
        legal_entity_id: parseInt(legalEntityId),
        counterparty_company_id: parseInt(counterpartyId),
        issue_date: issueDate,
        due_date: dueDate || null,
        currency,
        purpose: purpose || null,
        lines: lines.map((l, i) => ({
          name: l.name,
          qty: l.qty,
          unit_price: l.unit_price,
          vat_rate_id: l.vat_rate_id,
          sort_order: i,
        })),
      };

      const result = isEdit
        ? await api<FinInvoice>(`/api/finance/invoices/${invoice!.id}`, {
            method: "PATCH",
            body,
          })
        : await api<FinInvoice>("/api/finance/invoices", { method: "POST", body });

      await mutate("/api/finance/invoices");
      if (isEdit) await mutate(`/api/finance/invoices/${invoice!.id}`);
      onSuccess?.(result);
      onClose();
    } catch (err) {
      const msg = err instanceof Error ? err.message : "Ошибка при сохранении";
      setError(msg);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal
      open
      title={isEdit ? "Редактировать инвойс" : "Новый инвойс"}
      onClose={onClose}
      width="xl"
    >
      <form onSubmit={handleSubmit}>
        <div className="p-5 space-y-4">
          {/* Шапка */}
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
                  label="Дата выставления *"
                  value={issueDate}
                  onChange={(v) => setIssueDate(v ?? "")}
                  required
                />
              </div>

              <div>
                <DatePicker
                  label="Срок оплаты"
                  value={dueDate}
                  onChange={(v) => setDueDate(v ?? "")}
                  clearable
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
                <label className="label">Назначение платежа</label>
                <input
                  type="text"
                  className="input w-full"
                  placeholder="За услуги по договору №…"
                  value={purpose}
                  onChange={(e) => setPurpose(e.target.value)}
                />
              </div>
            </div>
          </div>

          {/* Позиции */}
          <div className="border rounded-lg p-4 dark:border-gray-700">
            <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">
              Позиции
            </h3>
            <InvoiceLineEditor
              lines={lines}
              onChange={setLines}
              showAccountCode={false}
            />
          </div>

          {error && (
            <p className="text-sm text-danger bg-red-50 dark:bg-red-900/20 rounded p-2">
              {error}
            </p>
          )}
        </div>

        <div className="flex justify-end gap-2 px-5 py-4 border-t border-gray-100 dark:border-gray-700">
          <button type="button" className="btn-ghost" onClick={onClose} disabled={submitting}>
            Отмена
          </button>
          <button type="submit" className="btn-primary" disabled={submitting}>
            {submitting
              ? isEdit
                ? "Сохранение…"
                : "Создание…"
              : isEdit
              ? "Сохранить"
              : "Создать инвойс"}
          </button>
        </div>
      </form>
    </Modal>
  );
}
