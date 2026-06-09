"use client";

import { useState, useEffect } from "react";
import { mutate } from "swr";
import { api, ApiError } from "@/lib/api";
import type { Sequence, SequenceStep } from "@/lib/types";
import { Modal } from "@/components/Modal";
import { StepsBuilder } from "./StepsBuilder";

interface Props {
  open: boolean;
  onClose: () => void;
  /** При передаче sequence — режим редактирования */
  sequence?: Sequence | null;
}

interface FormState {
  name: string;
  description: string;
  is_active: boolean;
  steps: SequenceStep[];
}

const EMPTY: FormState = {
  name: "",
  description: "",
  is_active: true,
  steps: [],
};

function fromSequence(s: Sequence): FormState {
  return {
    name: s.name,
    description: s.description ?? "",
    is_active: s.is_active,
    steps: Array.isArray(s.steps_json) ? s.steps_json : [],
  };
}

export function SequenceFormModal({ open, onClose, sequence }: Props) {
  const isEdit = Boolean(sequence);
  const [form, setForm] = useState<FormState>(EMPTY);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Сброс формы при открытии/закрытии
  useEffect(() => {
    if (open) {
      setForm(sequence ? fromSequence(sequence) : EMPTY);
      setError(null);
    }
  }, [open, sequence]);

  function validate(): string | null {
    if (!form.name.trim()) return "Название обязательно";
    return null;
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    const v = validate();
    if (v) { setError(v); return; }

    setSubmitting(true);
    try {
      const body = {
        name: form.name.trim(),
        description: form.description.trim() || null,
        is_active: form.is_active,
        steps_json: form.steps,
      };

      if (isEdit && sequence) {
        await api(`/sequences/${sequence.id}`, { method: "PATCH", body });
      } else {
        await api("/sequences", { method: "POST", body });
      }

      await mutate((key: unknown) => typeof key === "string" && key.startsWith("/sequences"));
      onClose();
    } catch (err) {
      setError(
        err instanceof ApiError
          ? String((err.detail as { detail?: string })?.detail ?? err.message)
          : "Не удалось сохранить",
      );
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open={open} onClose={onClose} title={isEdit ? "Редактировать последовательность" : "Новая последовательность"}>
      <form onSubmit={(e) => { void submit(e); }} className="space-y-4">
        {error && (
          <div className="text-sm text-danger bg-danger/10 border border-danger/30 px-3 py-2 rounded-md">
            {error}
          </div>
        )}

        <div>
          <label className="label">Название <span className="text-danger">*</span></label>
          <input
            className="input"
            type="text"
            value={form.name}
            onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
            placeholder="Например, «Онбординг нового лида»"
            autoFocus
          />
        </div>

        <div>
          <label className="label">Описание</label>
          <textarea
            className="input"
            rows={2}
            value={form.description}
            onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))}
            placeholder="Что делает эта последовательность и когда применяется"
          />
        </div>

        <label className="flex items-center gap-2 cursor-pointer">
          <input
            type="checkbox"
            checked={form.is_active}
            onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.checked }))}
          />
          <span>Активна</span>
        </label>

        <div className="border-t border-gray-200 pt-4">
          <div className="text-sm font-medium text-gray-700 mb-3">Шаги</div>
          <StepsBuilder
            steps={form.steps}
            onChange={(steps) => setForm((f) => ({ ...f, steps }))}
          />
        </div>

        <div className="flex justify-end gap-2 pt-2">
          <button type="button" className="btn-secondary" onClick={onClose} disabled={submitting}>
            Отмена
          </button>
          <button type="submit" className="btn-primary" disabled={submitting}>
            {submitting ? "Сохранение…" : isEdit ? "Сохранить" : "Создать"}
          </button>
        </div>
      </form>
    </Modal>
  );
}
