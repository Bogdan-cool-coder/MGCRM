"use client";

import { useState } from "react";
import useSWR, { mutate } from "swr";
import { Modal } from "@/components/Modal";
import { DatePicker } from "@/components/ui/DatePicker";
import { api, fetcher } from "@/lib/api";
import type { FinMoneyAccount, FinOpType } from "@/lib/types";

interface Props {
  open: boolean;
  requestId: number;
  onClose: () => void;
}

function today(): string {
  return new Date().toISOString().slice(0, 10);
}

export function RequestFulfillModal({ open, requestId, onClose }: Props) {
  const [accountFromId, setAccountFromId] = useState("");
  const [opDate, setOpDate] = useState(today());
  const [opTypeId, setOpTypeId] = useState("");
  const [autoPost, setAutoPost] = useState(true);
  const [error, setError] = useState("");
  const [submitting, setSubmitting] = useState(false);

  const { data: accounts } = useSWR<FinMoneyAccount[]>("/api/finance/money-accounts", fetcher);
  const { data: opTypes } = useSWR<FinOpType[]>("/api/finance/op-types", fetcher);

  const outTypes = (opTypes ?? []).filter((t) => t.direction === "out" && !t.is_archived);

  function handleClose() {
    setAccountFromId("");
    setOpDate(today());
    setOpTypeId("");
    setAutoPost(true);
    setError("");
    onClose();
  }

  async function handleSubmit() {
    setError("");
    if (!accountFromId) {
      setError("Выбери счёт списания");
      return;
    }
    setSubmitting(true);
    try {
      await api(`/api/finance/requests/${requestId}/fulfill`, {
        method: "POST",
        body: {
          account_from_id: parseInt(accountFromId),
          op_date: opDate || null,
          op_type_id: opTypeId ? parseInt(opTypeId) : null,
          auto_post: autoPost,
        },
      });
      await mutate(`/api/finance/requests/${requestId}`);
      await mutate(`/api/finance/requests/${requestId}/approval`);
      handleClose();
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message || "Не удалось исполнить заявку");
      } else {
        setError("Не удалось исполнить заявку");
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal
      open={open}
      title="Исполнить заявку"
      onClose={handleClose}
      width="md"
      footer={
        <>
          <button type="button" className="btn-ghost" onClick={handleClose}>
            Отмена
          </button>
          <button
            type="button"
            className="btn-primary"
            disabled={submitting}
            onClick={handleSubmit}
          >
            {submitting ? "Исполнение..." : "Исполнить"}
          </button>
        </>
      }
    >
      <div className="space-y-4">
        {error && (
          <div className="text-danger text-sm p-3 bg-red-50 dark:bg-red-900/20 rounded">
            {error}
          </div>
        )}

        <div>
          <label className="label">Счёт списания *</label>
          <select
            className="input"
            value={accountFromId}
            onChange={(e) => setAccountFromId(e.target.value)}
          >
            <option value="">Выбери счёт</option>
            {(accounts ?? []).map((a) => (
              <option key={a.id} value={String(a.id)}>
                {a.name} ({a.currency})
              </option>
            ))}
          </select>
        </div>

        <div>
          <DatePicker
            label="Дата операции"
            value={opDate}
            onChange={(v) => setOpDate(v ?? "")}
          />
        </div>

        <div>
          <label className="label">Тип операции</label>
          <select
            className="input"
            value={opTypeId}
            onChange={(e) => setOpTypeId(e.target.value)}
          >
            <option value="">Авто (из заявки)</option>
            {outTypes.map((t) => (
              <option key={t.id} value={String(t.id)}>{t.name}</option>
            ))}
          </select>
        </div>

        <label className="flex items-center gap-2 cursor-pointer">
          <input
            type="checkbox"
            checked={autoPost}
            onChange={(e) => setAutoPost(e.target.checked)}
            className="w-4 h-4 rounded border-gray-300"
          />
          <span className="text-sm dark:text-gray-200">Провести сразу после исполнения</span>
        </label>
      </div>
    </Modal>
  );
}
