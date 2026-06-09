"use client";

import { useEffect, useState } from "react";
import { Modal } from "@/components/Modal";
import { api, ApiError } from "@/lib/api";
import { mutate } from "swr";
import type { PageKey } from "@/lib/types";

interface SaveSegmentModalProps {
  open: boolean;
  pageKey: PageKey;
  currentFilterJson: Record<string, unknown>;
  filterSummary: string[];
  onClose: () => void;
  onSaved: () => void;
}

export function SaveSegmentModal({
  open,
  pageKey,
  currentFilterJson,
  filterSummary,
  onClose,
  onSaved,
}: SaveSegmentModalProps) {
  const [name, setName] = useState("");
  const [isPinned, setIsPinned] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (open) {
      setName("");
      setIsPinned(true);
      setError(null);
    }
  }, [open]);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!name.trim()) { setError("Введите название"); return; }
    setError(null);
    setSaving(true);
    try {
      await api("/saved-filters", {
        method: "POST",
        body: {
          name: name.trim(),
          page_key: pageKey,
          filter_json: currentFilterJson,
          is_pinned: isPinned,
        },
      });
      // Invalidate all saved-filters SWR keys
      await mutate((key) => typeof key === "string" && key.startsWith("/saved-filters"), undefined, { revalidate: true });
      onSaved();
      onClose();
    } catch (err) {
      if (err instanceof ApiError) {
        const d = (err.detail as { detail?: string })?.detail;
        setError(typeof d === "string" ? d : "Не удалось сохранить сегмент");
      } else {
        setError("Не удалось сохранить сегмент");
      }
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      open={open}
      title="Сохранить сегмент"
      onClose={onClose}
      width="sm"
      footer={
        <>
          <button type="button" onClick={onClose} className="btn-ghost" disabled={saving}>
            Отмена
          </button>
          <button
            type="submit"
            form="save-segment-form"
            className="btn-primary"
            disabled={saving}
          >
            {saving ? "Сохраняем…" : "Сохранить"}
          </button>
        </>
      }
    >
      <form id="save-segment-form" onSubmit={handleSubmit} className="space-y-4">
        {error && (
          <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">{error}</div>
        )}

        <div>
          <label className="label">Название *</label>
          <input
            className="input w-full"
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder="Горячие лиды KZ"
            autoFocus
          />
        </div>

        <label className="flex items-center gap-2 text-sm cursor-pointer">
          <input
            type="checkbox"
            checked={isPinned}
            onChange={(e) => setIsPinned(e.target.checked)}
            className="w-4 h-4 accent-primary"
          />
          Закрепить в боковой панели (быстрый доступ)
        </label>

        {/* Filter summary */}
        <div>
          <div className="text-xs uppercase tracking-wide text-gray-500 mb-1.5">Текущие фильтры:</div>
          {filterSummary.length > 0 ? (
            <ul className="text-sm text-gray-700 space-y-0.5">
              {filterSummary.map((s, i) => (
                <li key={i} className="flex items-start gap-1.5">
                  <span className="text-gray-400">·</span>
                  {s}
                </li>
              ))}
            </ul>
          ) : (
            <div className="text-sm text-gray-400">Фильтры не применены</div>
          )}
        </div>
      </form>
    </Modal>
  );
}
