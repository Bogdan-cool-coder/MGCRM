"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import useSWR from "swr";
import { Modal } from "@/components/Modal";
import { DatePicker } from "@/components/ui/DatePicker";
import { api, fetcher } from "@/lib/api";
import type { FinLegalEntity, FinMoneyAccount, FinRegistry } from "@/lib/types";

function today(): string {
  return new Date().toISOString().slice(0, 10);
}

interface Props {
  open: boolean;
  onClose: () => void;
}

export function RegistryCreateModal({ open, onClose }: Props) {
  const router = useRouter();
  const [legalEntityId, setLegalEntityId] = useState("");
  const [sourceAccountId, setSourceAccountId] = useState("");
  const [registryDate, setRegistryDate] = useState(today());
  const [title, setTitle] = useState("");
  const [comment, setComment] = useState("");
  const [error, setError] = useState("");
  const [submitting, setSubmitting] = useState(false);

  const { data: legalEntities } = useSWR<FinLegalEntity[]>("/api/finance/legal-entities", fetcher);
  const { data: accounts } = useSWR<FinMoneyAccount[]>("/api/finance/money-accounts", fetcher);

  // Filter accounts by selected legal entity
  const filteredAccounts = (accounts ?? []).filter(
    (a) => !legalEntityId || a.legal_entity_id === parseInt(legalEntityId)
  );

  function handleClose() {
    setLegalEntityId("");
    setSourceAccountId("");
    setRegistryDate(today());
    setTitle("");
    setComment("");
    setError("");
    onClose();
  }

  async function handleSubmit() {
    setError("");
    if (!legalEntityId || !sourceAccountId || !registryDate) {
      setError("Укажи юрлицо, счёт и дату реестра");
      return;
    }
    setSubmitting(true);
    try {
      const result = await api<FinRegistry>("/api/finance/registries", {
        method: "POST",
        body: {
          legal_entity_id: parseInt(legalEntityId),
          source_account_id: parseInt(sourceAccountId),
          registry_date: registryDate,
          title: title || null,
          comment: comment || null,
        },
      });
      handleClose();
      router.push(`/finance/registries/${result.id}`);
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message || "Не удалось создать реестр");
      } else {
        setError("Не удалось создать реестр");
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal
      open={open}
      title="Новый реестр платежей"
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
            {submitting ? "Создание..." : "Создать реестр"}
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
          <label className="label">Юрлицо *</label>
          <select
            className="input"
            value={legalEntityId}
            onChange={(e) => {
              setLegalEntityId(e.target.value);
              setSourceAccountId("");
            }}
          >
            <option value="">Выбери юрлицо</option>
            {(legalEntities ?? []).map((le) => (
              <option key={le.id} value={String(le.id)}>{le.name}</option>
            ))}
          </select>
        </div>

        <div>
          <label className="label">Счёт списания *</label>
          <select
            className="input"
            value={sourceAccountId}
            onChange={(e) => setSourceAccountId(e.target.value)}
            disabled={!legalEntityId}
          >
            <option value="">Выбери счёт</option>
            {filteredAccounts.map((a) => (
              <option key={a.id} value={String(a.id)}>
                {a.name} ({a.currency})
              </option>
            ))}
          </select>
        </div>

        <div>
          <DatePicker
            label="Дата реестра *"
            value={registryDate}
            onChange={(v) => setRegistryDate(v ?? "")}
            required
          />
        </div>

        <div>
          <label className="label">Название</label>
          <input
            type="text"
            className="input"
            placeholder="Например: Зарплата январь 2026"
            value={title}
            onChange={(e) => setTitle(e.target.value)}
          />
        </div>

        <div>
          <label className="label">Комментарий</label>
          <textarea
            className="input"
            rows={2}
            value={comment}
            onChange={(e) => setComment(e.target.value)}
          />
        </div>
      </div>
    </Modal>
  );
}
