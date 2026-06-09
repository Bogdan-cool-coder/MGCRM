"use client";

import { useState } from "react";
import useSWR, { mutate } from "swr";
import { Modal } from "@/components/Modal";
import { DatePicker } from "@/components/ui/DatePicker";
import { api, fetcher } from "@/lib/api";
import type { FinLegalEntity, FinVendorBill, FinVendorBillDetail } from "@/lib/types";
import { InvoiceLineEditor, type LineFormData } from "./InvoiceLineEditor";

function defaultLine(): LineFormData {
  return {
    _id: crypto.randomUUID(),
    name: "",
    qty: "1",
    unit_price: "0",
    vat_rate_id: null,
    sort_order: 0,
    expense_account_code: "5990",
  };
}

interface Props {
  bill?: FinVendorBillDetail | null;
  onClose: () => void;
  onSuccess?: (bill: FinVendorBill) => void;
}

export function VendorBillFormModal({ bill, onClose, onSuccess }: Props) {
  const isEdit = bill != null;
  const { data: entities = [] } = useSWR<FinLegalEntity[]>("/api/finance/legal-entities", fetcher);
  const today = new Date().toISOString().slice(0, 10);

  const { data: counterparties } = useSWR<{ id: number; name: string }[]>(
    "/api/counterparties?limit=200",
    fetcher
  );

  const [legalEntityId, setLegalEntityId] = useState(
    bill ? String(bill.legal_entity_id) : ""
  );
  const [supplierId, setSupplierId] = useState(
    bill ? String(bill.supplier_company_id) : ""
  );
  const [billNo, setBillNo] = useState(bill?.bill_no ?? "");
  const [billDate, setBillDate] = useState(bill?.bill_date ?? today);
  const [dueDate, setDueDate] = useState(bill?.due_date ?? "");
  const [currency, setCurrency] = useState(bill?.currency ?? "RUB");
  const [purpose, setPurpose] = useState(bill?.purpose ?? "");
  const [lines, setLines] = useState<LineFormData[]>(
    bill?.lines?.length
      ? bill.lines.map((l) => ({
          _id: crypto.randomUUID(),
          name: l.name,
          qty: String(l.qty),
          unit_price: String(l.unit_price),
          vat_rate_id: l.vat_rate_id,
          sort_order: l.sort_order,
          expense_account_code: l.expense_account_code ?? "5990",
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

    if (!legalEntityId || !supplierId || !billDate || !currency) {
      setError("Заполните обязательные поля: юрлицо, поставщик, дата, валюта");
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
        supplier_company_id: parseInt(supplierId),
        bill_no: billNo || null,
        bill_date: billDate,
        due_date: dueDate || null,
        currency,
        purpose: purpose || null,
        lines: lines.map((l, i) => ({
          name: l.name,
          qty: l.qty,
          unit_price: l.unit_price,
          vat_rate_id: l.vat_rate_id,
          expense_account_code: l.expense_account_code || null,
          sort_order: i,
        })),
      };

      const result = isEdit
        ? await api<FinVendorBill>(`/api/finance/vendor-bills/${bill!.id}`, {
            method: "PATCH",
            body,
          })
        : await api<FinVendorBill>("/api/finance/vendor-bills", { method: "POST", body });

      await mutate("/api/finance/vendor-bills");
      if (isEdit) await mutate(`/api/finance/vendor-bills/${bill!.id}`);
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
      title={isEdit ? "Редактировать счёт поставщика" : "Новый счёт поставщика"}
      onClose={onClose}
      width="xl"
    >
      <form onSubmit={handleSubmit}>
        <div className="p-5 space-y-4">
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
                <label className="label">Поставщик *</label>
                <select
                  className="input w-full"
                  value={supplierId}
                  onChange={(e) => setSupplierId(e.target.value)}
                >
                  <option value="">— Выберите поставщика —</option>
                  {counterparties?.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="label">№ счёта поставщика</label>
                <input
                  type="text"
                  className="input w-full"
                  placeholder="Например: INV-2025-001"
                  value={billNo}
                  onChange={(e) => setBillNo(e.target.value)}
                />
              </div>

              <div>
                <DatePicker
                  label="Дата счёта *"
                  value={billDate}
                  onChange={(v) => setBillDate(v ?? "")}
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

              <div className="sm:col-span-2">
                <label className="label">Назначение</label>
                <input
                  type="text"
                  className="input w-full"
                  placeholder="Услуги по договору…"
                  value={purpose}
                  onChange={(e) => setPurpose(e.target.value)}
                />
              </div>
            </div>
          </div>

          <div className="border rounded-lg p-4 dark:border-gray-700">
            <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">Позиции</h3>
            <InvoiceLineEditor
              lines={lines}
              onChange={setLines}
              showAccountCode
              accountCodeLabel="Счёт расх."
              accountCodeField="expense_account_code"
            />
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
              : isEdit ? "Сохранить" : "Создать счёт"}
          </button>
        </div>
      </form>
    </Modal>
  );
}
