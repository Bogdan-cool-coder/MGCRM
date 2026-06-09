"use client";

import { useState } from "react";
import useSWR from "swr";
import { Modal } from "@/components/Modal";
import { api, ApiError, fetcher } from "@/lib/api";
import { LOST_REASON_PRESETS } from "@/lib/types";
import type { LostReasonItem } from "@/lib/types";

interface LostReasonModalProps {
  open: boolean;
  dealId: number;
  targetStageId: number;
  onClose: () => void;
  onConfirmed: () => void;
}

export function LostReasonModal({ open, dealId, targetStageId, onClose, onConfirmed }: LostReasonModalProps) {
  const [reason, setReason] = useState("");
  const [selectedReasonId, setSelectedReasonId] = useState<number | null>(null);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Загружаем реестр причин
  const { data: lostReasons, error: reasonsError } = useSWR<LostReasonItem[]>(
    open ? "/deals/lost-reasons" : null,
    fetcher
  );

  // Активные причины из реестра
  const registryReasons = (lostReasons ?? []).filter((r) => r.is_active);

  // Используем пресеты как fallback если реестр пуст или ошибка
  const usePresets = !lostReasons || reasonsError || registryReasons.length === 0;

  function handlePreset(preset: string) {
    setReason((prev) => {
      const trimmed = prev.trim();
      if (!trimmed) return preset;
      if (trimmed === preset) return prev;
      return `${trimmed} · ${preset}`;
    });
    setSelectedReasonId(null);
  }

  function handleRegistrySelect(item: LostReasonItem) {
    setSelectedReasonId((prev) => prev === item.id ? null : item.id);
    // Если выбрали из реестра — можно дополнить комментарием
    setReason("");
  }

  async function handleConfirm() {
    const hasRegistrySelection = selectedReasonId != null;
    const hasTextReason = reason.trim().length > 0;

    if (!hasRegistrySelection && !hasTextReason) return;

    setSaving(true);
    setError(null);
    try {
      const body: Record<string, unknown> = { stage_id: targetStageId };

      if (selectedReasonId != null) {
        body.lost_reason_id = selectedReasonId;
        // Если есть дополнительный комментарий
        if (reason.trim()) body.lost_reason = reason.trim();
      } else {
        body.lost_reason = reason.trim();
      }

      // Бэк требует причину при переводе в is_lost (422 если её нет). Передаём
      // lost_reason/lost_reason_id прямо в move — без fallback-move без причины.
      await api(`/deals/${dealId}/move`, { method: "POST", body });
      onConfirmed();
    } catch (err) {
      setError(
        err instanceof ApiError
          ? String((err.detail as { detail?: string })?.detail ?? err.message)
          : "Не удалось перевести сделку"
      );
    } finally {
      setSaving(false);
    }
  }

  function handleClose() {
    setReason("");
    setSelectedReasonId(null);
    setError(null);
    onClose();
  }

  const canConfirm = selectedReasonId != null || reason.trim().length > 0;

  return (
    <Modal
      open={open}
      title="Причина проигрыша"
      onClose={handleClose}
      width="sm"
      footer={
        <>
          <button className="btn-secondary" onClick={handleClose} disabled={saving}>
            Отмена
          </button>
          <button
            className="btn-primary disabled:opacity-50"
            onClick={handleConfirm}
            disabled={saving || !canConfirm}
          >
            {saving ? "Сохранение…" : "Подтвердить"}
          </button>
        </>
      }
    >
      <div className="space-y-4">
        {error && (
          <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">{error}</div>
        )}

        <p className="text-sm text-gray-600 dark:text-gray-400">
          Укажите причину, чтобы анализировать проигрыши и улучшать воронку.
        </p>

        {/* Реестр причин (если загружен и не пуст) */}
        {!usePresets && (
          <div>
            <label className="label">Причина</label>
            <div className="flex flex-wrap gap-2">
              {registryReasons.map((item) => (
                <button
                  key={item.id}
                  type="button"
                  onClick={() => handleRegistrySelect(item)}
                  className={`px-2.5 py-1 text-xs rounded-full border transition-colors ${
                    selectedReasonId === item.id
                      ? "bg-primary text-white border-primary"
                      : "border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:border-primary hover:text-primary dark:hover:text-primary"
                  }`}
                >
                  {item.name}
                </button>
              ))}
            </div>
          </div>
        )}

        {/* Fallback пресеты если реестр пуст или ошибка */}
        {usePresets && (
          <div>
            <label className="label">Быстрый выбор</label>
            <div className="flex flex-wrap gap-2">
              {LOST_REASON_PRESETS.map((preset) => (
                <button
                  key={preset}
                  type="button"
                  onClick={() => handlePreset(preset)}
                  className={`px-2.5 py-1 text-xs rounded-full border transition-colors ${
                    reason.includes(preset)
                      ? "bg-primary text-white border-primary"
                      : "border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:border-primary hover:text-primary dark:hover:text-primary"
                  }`}
                >
                  {preset}
                </button>
              ))}
            </div>
          </div>
        )}

        <div>
          <label className="label">
            {selectedReasonId != null ? "Дополнительный комментарий" : (
              <>Комментарий {usePresets && <span className="text-danger">*</span>}</>
            )}
          </label>
          <textarea
            className="input min-h-[80px]"
            placeholder={
              selectedReasonId != null
                ? "Можно добавить детали (необязательно)…"
                : "Опишите причину подробнее…"
            }
            value={reason}
            onChange={(e) => setReason(e.target.value)}
          />
        </div>
      </div>
    </Modal>
  );
}
