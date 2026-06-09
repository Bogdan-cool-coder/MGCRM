"use client";

import { useEffect, useState } from "react";
import { Modal } from "@/components/Modal";
import { UserSelect } from "@/components/UserSelect";
import { DatePicker } from "@/components/ui/DatePicker";
import { api, ApiError } from "@/lib/api";
import { type VacationType, VACATION_TYPE_LABELS } from "@/lib/types";

interface Props {
  open: boolean;
  onClose: () => void;
  onSuccess: () => void;
}

const VACATION_TYPE_OPTIONS = (Object.entries(VACATION_TYPE_LABELS) as [VacationType, string][]).map(
  ([v, l]) => ({ value: v, label: l })
);

export function VacationCreateModal({ open, onClose, onSuccess }: Props) {
  const [vacationType, setVacationType] = useState<VacationType>("vacation");
  const [startDate, setStartDate] = useState("");
  const [endDate, setEndDate] = useState("");
  const [substituteId, setSubstituteId] = useState("");
  const [notes, setNotes] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [dateError, setDateError] = useState<string | null>(null);

  useEffect(() => {
    if (open) {
      setVacationType("vacation");
      setStartDate("");
      setEndDate("");
      setSubstituteId("");
      setNotes("");
      setError(null);
      setDateError(null);
    }
  }, [open]);

  function validate(): boolean {
    if (!startDate || !endDate) {
      setError("Заполни даты отпуска");
      return false;
    }
    if (endDate < startDate) {
      setDateError("Дата окончания не может быть раньше начала");
      return false;
    }
    setDateError(null);
    return true;
  }

  async function handleSubmit() {
    if (!validate()) return;
    setSubmitting(true);
    setError(null);
    try {
      await api("/me/vacations", {
        method: "POST",
        body: {
          vacation_type: vacationType,
          start_date: startDate,
          end_date: endDate,
          substitute_user_id: substituteId ? Number(substituteId) : null,
          notes: notes.trim() || null,
        },
      });
      onSuccess();
      onClose();
    } catch (err) {
      setError(err instanceof ApiError ? String((err.detail as Record<string, unknown>)?.detail ?? err.message) : "Что-то пошло не так. Попробуй ещё раз.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Новый отпуск"
      width="md"
      footer={
        <>
          <button className="btn-ghost" onClick={onClose} disabled={submitting}>Отмена</button>
          <button
            className="btn-primary"
            onClick={handleSubmit}
            disabled={submitting || !startDate || !endDate}
          >
            {submitting ? "Отправляем…" : "Подать заявку"}
          </button>
        </>
      }
    >
      <div className="space-y-4">
        {/* Vacation type */}
        <div>
          <label className="label">Тип отпуска <span className="text-danger">*</span></label>
          <select
            className="input"
            value={vacationType}
            onChange={(e) => setVacationType(e.target.value as VacationType)}
          >
            {VACATION_TYPE_OPTIONS.map((opt) => (
              <option key={opt.value} value={opt.value}>{opt.label}</option>
            ))}
          </select>
        </div>

        {/* Dates */}
        <div className="grid grid-cols-2 gap-3">
          <div>
            <DatePicker
              label="Начало *"
              value={startDate}
              onChange={(v) => { setStartDate(v ?? ""); setDateError(null); }}
              required
            />
          </div>
          <div>
            <DatePicker
              label="Конец *"
              value={endDate}
              onChange={(v) => { setEndDate(v ?? ""); setDateError(null); }}
              minDate={startDate || undefined}
              required
            />
          </div>
        </div>
        {dateError && (
          <div className="text-danger text-sm">{dateError}</div>
        )}

        {/* Substitute */}
        <div>
          <label className="label">Substitute</label>
          <UserSelect
            value={substituteId}
            onChange={setSubstituteId}
            placeholder="Кто заменит"
          />
        </div>

        {/* Info */}
        <div className="bg-info/10 text-info text-sm rounded p-3">
          <i className="bi bi-info-circle mr-2" />
          При наступлении отпуска новые задачи будут автоматически назначаться substitute
        </div>

        {/* Notes */}
        <div>
          <label className="label">Заметки</label>
          <textarea
            className="input"
            rows={3}
            placeholder="Опционально…"
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
          />
        </div>

        {error && (
          <div className="text-danger text-sm bg-danger/10 px-3 py-2 rounded">{error}</div>
        )}
      </div>
    </Modal>
  );
}
