"use client";

import { useState, useMemo } from "react";
import useSWR from "swr";
import { Modal } from "@/components/Modal";
import { api, ApiError, fetcher } from "@/lib/api";
import type { Deal } from "@/lib/types";
import { formatCurrency } from "@/lib/format";

interface Props {
  open: boolean;
  callId: number | null;
  activityId: number | null;
  onClose: () => void;
  onAttached: () => void;
}

export function AttachToDealModal({ open, callId, activityId, onClose, onAttached }: Props) {
  const { data: deals } = useSWR<Deal[]>(open ? "/deals" : null, fetcher);
  const [search, setSearch] = useState("");
  const [selectedDealId, setSelectedDealId] = useState<number | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const filtered = useMemo(() => {
    if (!deals) return [];
    if (!search.trim()) return deals.slice(0, 20);
    const q = search.toLowerCase();
    return deals.filter((d) => d.title.toLowerCase().includes(q)).slice(0, 20);
  }, [deals, search]);

  async function handleAttach() {
    if (!selectedDealId || (!callId && !activityId)) return;
    setSubmitting(true);
    setError(null);
    try {
      if (activityId) {
        await api(`/integrations/calldown/calls/${callId}/attach-deal`, {
          method: "PATCH",
          body: { deal_id: selectedDealId },
        });
      }
      setSelectedDealId(null);
      setSearch("");
      onAttached();
      onClose();
    } catch (err) {
      setError(
        err instanceof ApiError
          ? String((err.detail as { detail?: string })?.detail ?? err.message)
          : "Не удалось прикрепить сделку"
      );
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal
      open={open}
      title="Прикрепить к сделке"
      onClose={onClose}
      width="sm"
      footer={
        <>
          <button className="btn-ghost" onClick={onClose} disabled={submitting}>
            Отмена
          </button>
          <button
            className="btn-primary"
            onClick={handleAttach}
            disabled={submitting || !selectedDealId}
          >
            {submitting ? "Прикрепляем…" : "Прикрепить"}
          </button>
        </>
      }
    >
      <div className="space-y-4">
        {error && (
          <div className="rounded-md bg-danger/10 text-danger px-4 py-2 text-sm">{error}</div>
        )}
        <div>
          <label className="label">Поиск сделки</label>
          <input
            className="input"
            placeholder="Поиск по названию сделки или контрагенту"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>
        {!deals && (
          <div className="space-y-2 animate-pulse">
            {[1, 2, 3].map((i) => <div key={i} className="h-10 bg-gray-100 dark:bg-gray-700 rounded" />)}
          </div>
        )}
        {filtered.length > 0 && (
          <div className="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden max-h-60 overflow-y-auto">
            {filtered.map((deal) => (
              <button
                key={deal.id}
                onClick={() => setSelectedDealId(deal.id)}
                className={
                  "w-full text-left px-4 py-3 text-sm flex items-center gap-2 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors border-b border-gray-100 dark:border-gray-700 last:border-b-0 " +
                  (selectedDealId === deal.id ? "bg-primary/5 text-primary" : "")
                }
              >
                {selectedDealId === deal.id && <i className="bi bi-check-lg text-primary shrink-0" />}
                <div className="flex-1 min-w-0">
                  <div className="font-medium truncate">{deal.title}</div>
                  {deal.amount && (
                    <div className="text-xs text-gray-500 dark:text-gray-400">
                      {formatCurrency(deal.amount, deal.currency)}
                    </div>
                  )}
                </div>
              </button>
            ))}
          </div>
        )}
        {deals && filtered.length === 0 && (
          <p className="text-sm text-gray-500 dark:text-gray-400 text-center py-4">
            Нет подходящих сделок
          </p>
        )}
      </div>
    </Modal>
  );
}
