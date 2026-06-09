"use client";

import { useCallback, useEffect, useState } from "react";
import { Modal } from "@/components/Modal";
import { api, ApiError } from "@/lib/api";
import type {
  AIPrefillSource,
  DealPrefill,
  DealPrefillSuggestion,
  LeadPrefill,
} from "@/lib/types";
import { PrefillSuggestionRow } from "./PrefillSuggestionRow";

type Step = "step1" | "loading" | "step2" | "empty" | "error";

interface AIPrefillModalProps {
  open: boolean;
  onClose: () => void;
  entityType: "lead" | "deal";
  entityId: number;
  /** Вызывается после успешного PATCH — обычно mutate() SWR ключа. */
  onApplied: () => void;
}

const SOURCE_OPTIONS: { value: AIPrefillSource; label: string; recommended?: boolean }[] = [
  { value: "all", label: "Все источники", recommended: true },
  { value: "activities", label: "История активностей (звонки, встречи, задачи)" },
  { value: "notes", label: "Заметки и сообщения" },
];

const PERIOD_OPTIONS: { value: number; label: string }[] = [
  { value: 7, label: "Последние 7 дней" },
  { value: 30, label: "Последние 30 дней" },
  { value: 90, label: "Последние 90 дней" },
  { value: 0, label: "Всё время" },
];

export function AIPrefillModal({
  open,
  onClose,
  entityType,
  entityId,
  onApplied,
}: AIPrefillModalProps) {
  const [step, setStep] = useState<Step>("step1");
  const [source, setSource] = useState<AIPrefillSource>("all");
  const [periodDays, setPeriodDays] = useState<number>(30);
  const [result, setResult] = useState<DealPrefill | LeadPrefill | null>(null);
  const [checked, setChecked] = useState<Set<string>>(new Set());
  const [applying, setApplying] = useState(false);
  const [applyError, setApplyError] = useState<string | null>(null);
  const [fetchError, setFetchError] = useState<string | null>(null);

  useEffect(() => {
    if (!open) {
      // сброс при закрытии
      setStep("step1");
      setResult(null);
      setChecked(new Set());
      setApplyError(null);
      setFetchError(null);
      setApplying(false);
    }
  }, [open]);

  const fetchSuggestions = useCallback(async () => {
    setStep("loading");
    setFetchError(null);
    try {
      const endpoint = `/${entityType}s/${entityId}/ai-prefill`;
      const data = await api<DealPrefill | LeadPrefill>(endpoint, {
        method: "POST",
        query: { source, period_days: periodDays },
      });
      setResult(data);
      // По дефолту чекаем всё с high и medium confidence
      const defaultChecked = new Set(
        data.suggestions
          .filter((s) => s.confidence === "high" || s.confidence === "medium")
          .map((s) => s.field),
      );
      setChecked(defaultChecked);
      setStep(data.suggestions.length > 0 ? "step2" : "empty");
    } catch (e) {
      if (e instanceof ApiError) {
        if (e.status === 503) {
          setFetchError("AI не настроен. Обратитесь к администратору.");
        } else if (e.status === 502) {
          setFetchError("AI временно недоступен. Попробуйте ещё раз через минуту.");
        } else {
          const detail = (e.detail as { detail?: string })?.detail ?? e.message;
          setFetchError(detail);
        }
      } else {
        setFetchError("Не удалось выполнить анализ");
      }
      setStep("error");
    }
  }, [entityType, entityId, source, periodDays]);

  const applySelected = useCallback(async () => {
    if (!result) return;
    setApplying(true);
    setApplyError(null);
    const payload: Record<string, unknown> = {};
    result.suggestions
      .filter((s) => checked.has(s.field))
      .forEach((s) => {
        payload[s.field] = s.suggested_value;
      });
    try {
      await api(`/${entityType}s/${entityId}`, {
        method: "PATCH",
        body: payload,
      });
      onApplied();
      onClose();
    } catch (e) {
      if (e instanceof ApiError) {
        const detail = (e.detail as { detail?: string })?.detail ?? e.message;
        setApplyError(detail);
      } else {
        setApplyError("Не удалось применить изменения");
      }
      setApplying(false);
    }
  }, [result, entityType, entityId, checked, onApplied, onClose]);

  const toggleField = (field: string, isChecked: boolean) => {
    setChecked((prev) => {
      const next = new Set(prev);
      if (isChecked) next.add(field);
      else next.delete(field);
      return next;
    });
  };

  const subtitle =
    step === "step1" || step === "error"
      ? "Шаг 1 из 2 · Выбери источник данных"
      : step === "step2"
        ? "Шаг 2 из 2 · Выбери, что применить"
        : undefined;

  // ── Footer ────────────────────────────────────────────────────────────
  let footer: React.ReactNode = null;
  if (step === "step1" || step === "error") {
    footer = (
      <>
        <button onClick={onClose} className="btn-ghost" disabled={applying}>
          Отмена
        </button>
        <button onClick={fetchSuggestions} className="btn-primary">
          <i className="bi bi-stars" /> Анализировать
        </button>
      </>
    );
  } else if (step === "step2") {
    const count = checked.size;
    footer = (
      <>
        <button
          onClick={() => setStep("step1")}
          className="btn-secondary"
          disabled={applying}
        >
          <i className="bi bi-arrow-left" /> Назад
        </button>
        <button
          onClick={applySelected}
          className="btn-primary"
          disabled={applying || count === 0}
        >
          {applying ? (
            <>
              <i className="bi bi-arrow-repeat animate-spin" /> Применяем…
            </>
          ) : (
            `Применить выбранные (${count})`
          )}
        </button>
      </>
    );
  } else if (step === "empty") {
    footer = (
      <button onClick={onClose} className="btn-ghost">
        Закрыть
      </button>
    );
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      width="md"
      title="AI предзаполнение"
      description={subtitle}
      footer={footer}
    >
      {(step === "step1" || step === "error") && (
        <div className="space-y-5">
          {/* Источник */}
          <div>
            <label className="label mb-2 block">Источник данных</label>
            <div className="space-y-1">
              {SOURCE_OPTIONS.map((opt) => (
                <label
                  key={opt.value}
                  className="flex items-center gap-2 cursor-pointer py-1"
                >
                  <input
                    type="radio"
                    name="ai-prefill-source"
                    checked={source === opt.value}
                    onChange={() => setSource(opt.value)}
                    className="accent-primary"
                  />
                  <span className="text-sm dark:text-gray-200">{opt.label}</span>
                  {opt.recommended && (
                    <span className="badge text-xs bg-primary/10 text-primary ml-1">
                      рекомендуется
                    </span>
                  )}
                </label>
              ))}
            </div>
          </div>

          {/* Период */}
          <div>
            <label className="label mb-2 block">Период</label>
            <select
              className="input w-full"
              value={periodDays}
              onChange={(e) => setPeriodDays(Number(e.target.value))}
            >
              {PERIOD_OPTIONS.map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </select>
          </div>

          {step === "error" && fetchError && (
            <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">
              Не удалось выполнить анализ: {fetchError}
            </div>
          )}
        </div>
      )}

      {step === "loading" && (
        <div className="flex flex-col items-center justify-center py-16 text-center">
          <i className="bi bi-arrow-repeat animate-spin text-3xl text-primary mb-4" />
          <p className="font-medium dark:text-gray-200">
            AI читает историю переписки...
          </p>
          <p className="text-sm text-gray-500 mt-1">
            Анализируем выбранный период
          </p>
        </div>
      )}

      {step === "empty" && (
        <div className="flex flex-col items-center text-center py-12">
          <i className="bi bi-chat-dots text-gray-300 text-5xl mb-4" />
          <p className="font-medium dark:text-gray-200">
            Недостаточно данных для анализа
          </p>
          <p className="text-sm text-gray-500 max-w-md mt-1">
            Добавьте хотя бы одну активность или сообщение, чтобы AI мог
            проанализировать переписку
          </p>
        </div>
      )}

      {step === "step2" && result && (
        <div>
          {result.summary && (
            <div className="bg-gray-50 dark:bg-gray-900 text-sm italic px-3 py-2 rounded mb-4 text-gray-700 dark:text-gray-300">
              «{result.summary}»
            </div>
          )}
          {result.suggestions.length === 0 ? (
            <p className="text-sm text-gray-500 text-center py-6">
              AI не выделил предложений
            </p>
          ) : (
            result.suggestions.map((s: DealPrefillSuggestion) => (
              <PrefillSuggestionRow
                key={s.field}
                suggestion={s}
                checked={checked.has(s.field)}
                onToggle={(c) => toggleField(s.field, c)}
              />
            ))
          )}
          {applyError && (
            <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded mt-3">
              Не удалось применить: {applyError}
            </div>
          )}
        </div>
      )}
    </Modal>
  );
}
