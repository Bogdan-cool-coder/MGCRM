"use client";

import { useState } from "react";
import useSWR from "swr";
import { Modal } from "@/components/Modal";
import { UserSelect } from "@/components/UserSelect";
import { api, ApiError, fetcher } from "@/lib/api";
import {
  type EmployeeListItem,
  type TransferCategory,
  type User,
  TRANSFER_CATEGORY_LABELS,
} from "@/lib/types";

interface Props {
  open: boolean;
  employee: EmployeeListItem | null;
  onClose: () => void;
  onSuccess: (toName: string) => void;
}

const ALL_CATEGORIES: TransferCategory[] = [
  "contacts",
  "deals",
  "tasks_assignee",
  "tasks_creator",
  "approvals",
  "configs",
];

const DEFAULT_CHECKED: TransferCategory[] = ["contacts", "deals"];

export function RightsTransferModal({ open, employee, onClose, onSuccess }: Props) {
  const [toUserId, setToUserId] = useState("");
  const [categories, setCategories] = useState<TransferCategory[]>([...DEFAULT_CHECKED]);
  const [reason, setReason] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const { data: allUsers } = useSWR<User[]>(open ? "/users" : null, fetcher);

  function toggleCategory(cat: TransferCategory) {
    setCategories((prev) =>
      prev.includes(cat) ? prev.filter((c) => c !== cat) : [...prev, cat]
    );
  }

  async function handleTransfer() {
    if (!employee || !toUserId || categories.length === 0) return;
    setSubmitting(true);
    setError(null);
    try {
      const toName = allUsers?.find((u) => String(u.id) === toUserId)?.full_name ?? "сотруднику";
      await api("/admin/rights-transfers", {
        method: "POST",
        body: {
          from_user_id: employee.id,
          to_user_id: Number(toUserId),
          categories,
          reason: reason.trim() || null,
        },
      });
      onSuccess(toName);
      onClose();
    } catch (err) {
      setError(err instanceof ApiError ? String((err.detail as Record<string, unknown>)?.detail ?? err.message) : "Что-то пошло не так. Попробуй ещё раз.");
    } finally {
      setSubmitting(false);
    }
  }

  const isValid = !!toUserId && categories.length > 0;

  if (!employee) return null;

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={`Передача прав: ${employee.full_name}`}
      width="md"
      footer={
        <>
          <button className="btn-ghost" onClick={onClose} disabled={submitting}>Отмена</button>
          <button
            className="btn-primary"
            onClick={handleTransfer}
            disabled={submitting || !isValid}
          >
            {submitting ? "Передаём…" : "Передать права"}
          </button>
        </>
      }
    >
      <div className="space-y-5">
        <div>
          <label className="label">Кому передать <span className="text-danger">*</span></label>
          <UserSelect
            value={toUserId}
            onChange={setToUserId}
            placeholder="Выбери получателя"
          />
        </div>

        <div>
          <div className="label mb-2">Что передать (выбери категории)</div>
          <div className="space-y-1">
            {ALL_CATEGORIES.map((cat) => (
              <label
                key={cat}
                className="flex items-center gap-2 py-2 px-2 rounded cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700"
              >
                <input
                  type="checkbox"
                  className="mr-1"
                  checked={categories.includes(cat)}
                  onChange={() => toggleCategory(cat)}
                />
                <span className="text-sm text-gray-700 dark:text-gray-300">
                  {TRANSFER_CATEGORY_LABELS[cat]}
                </span>
              </label>
            ))}
          </div>
        </div>

        <div>
          <label className="label">Причина передачи</label>
          <textarea
            className="input"
            rows={3}
            placeholder="Опционально: укажи причину передачи прав…"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
          />
        </div>

        {error && (
          <div className="text-danger text-sm bg-danger/10 px-3 py-2 rounded">{error}</div>
        )}
      </div>
    </Modal>
  );
}
