"use client";

import { useState } from "react";
import { Modal } from "@/components/Modal";
import { api, ApiError } from "@/lib/api";
import { type RightsTransfer, TRANSFER_CATEGORY_LABELS } from "@/lib/types";

interface Props {
  open: boolean;
  transfer: RightsTransfer | null;
  onClose: () => void;
  onSuccess: () => void;
}

export function RightsTransferRevertModal({ open, transfer, onClose, onSuccess }: Props) {
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleRevert() {
    if (!transfer) return;
    setSubmitting(true);
    setError(null);
    try {
      await api(`/admin/rights-transfers/${transfer.id}/revert`, { method: "POST" });
      onSuccess();
      onClose();
    } catch (err) {
      setError(err instanceof ApiError ? String((err.detail as Record<string, unknown>)?.detail ?? err.message) : "Что-то пошло не так. Попробуй ещё раз.");
    } finally {
      setSubmitting(false);
    }
  }

  if (!transfer) return null;

  const categoriesText = transfer.categories
    .map((c) => TRANSFER_CATEGORY_LABELS[c])
    .join(", ");

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Откатить передачу прав?"
      width="sm"
      footer={
        <>
          <button className="btn-ghost" onClick={onClose} disabled={submitting}>Отмена</button>
          <button
            className="bg-danger hover:bg-danger/90 text-white rounded-md px-4 py-2 text-sm font-medium transition-colors disabled:opacity-60"
            onClick={handleRevert}
            disabled={submitting}
          >
            {submitting ? "Откатываем…" : "Откатить"}
          </button>
        </>
      }
    >
      <div className="space-y-3">
        <p className="text-sm text-gray-700 dark:text-gray-300">
          Восстановится прежний owner для{" "}
          <span className="font-semibold text-primary">{transfer.items_count}</span> записей
          ({categoriesText}).
        </p>
        {error && (
          <div className="text-danger text-sm bg-danger/10 px-3 py-2 rounded">{error}</div>
        )}
      </div>
    </Modal>
  );
}
