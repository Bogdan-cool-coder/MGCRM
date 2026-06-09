"use client";

import { useState } from "react";
import useSWR from "swr";
import { Modal } from "@/components/Modal";
import { api, ApiError, fetcher } from "@/lib/api";
import type { MeetingQuestion } from "@/lib/types";

interface MeetingReportModalProps {
  dealId: number;
  onClose: () => void;
  onSaved: () => void;
}

export function MeetingReportModal({ dealId, onClose, onSaved }: MeetingReportModalProps) {
  const { data: questions, isLoading } = useSWR<MeetingQuestion[]>(
    "/deals/meeting-questions?is_active=true",
    fetcher
  );

  const [answers, setAnswers] = useState<Record<number, string>>({});
  const [comment, setComment] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  const activeQuestions = (questions ?? []).filter((q) => q.is_active !== false);

  function setAnswer(questionId: number, value: string) {
    setAnswers((prev) => ({ ...prev, [questionId]: value }));
  }

  async function handleSave() {
    setSaving(true);
    setError(null);
    try {
      const answersArray = Object.entries(answers)
        .filter(([, val]) => val.trim() !== "")
        .map(([qid, val]) => ({ question_id: Number(qid), answer: val }));
      await api(`/deals/${dealId}/meeting-report`, {
        method: "POST",
        body: { answers: answersArray, comment: comment.trim() || null },
      });
      setSaved(true);
      setTimeout(() => {
        onSaved();
        onClose();
      }, 1500);
    } catch (err) {
      setError(
        err instanceof ApiError
          ? String((err.detail as { detail?: string })?.detail ?? err.message)
          : "Не удалось сохранить отчёт"
      );
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      open
      title="Отчёт по встрече"
      description="Заполните вопросы встречи — отчёт попадёт в ленту активностей компании"
      onClose={onClose}
      width="md"
      footer={
        saved ? undefined : (
          <>
            <button className="btn-ghost" onClick={onClose}>Отмена</button>
            <button
              className="btn-primary disabled:opacity-50"
              onClick={handleSave}
              disabled={saving}
            >
              {saving ? "Сохранение…" : "Сохранить отчёт"}
            </button>
          </>
        )
      }
    >
      {saved ? (
        <div className="py-8 text-center">
          <i className="bi bi-check-circle-fill text-success text-4xl block mb-3" />
          <div className="text-base font-medium text-gray-800 dark:text-gray-200 mb-1">
            Отчёт сохранён
          </div>
          <p className="text-sm text-gray-500 dark:text-gray-400">
            Отчёт по встрече добавлен в ленту активностей компании.
          </p>
        </div>
      ) : (
        <div className="space-y-5">
          {error && (
            <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded flex items-center gap-2">
              <i className="bi bi-exclamation-triangle shrink-0" />
              {error}
            </div>
          )}

          {isLoading && (
            <div className="space-y-4">
              {[1, 2, 3].map((i) => (
                <div key={i} className="space-y-1.5">
                  <div className="h-4 bg-gray-200 dark:bg-gray-700 rounded w-1/3 animate-pulse" />
                  <div className="h-9 bg-gray-100 dark:bg-gray-700 rounded animate-pulse" />
                </div>
              ))}
            </div>
          )}

          {!isLoading && activeQuestions.length === 0 && (
            <div className="rounded-lg bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 px-4 py-3 text-sm text-gray-500 dark:text-gray-400 flex items-start gap-2">
              <i className="bi bi-info-circle shrink-0 mt-0.5" />
              <span>
                Вопросы встречи не настроены.{" "}
                <a href="/admin/pipelines" className="text-primary hover:underline">
                  Настроить в параметрах воронки →
                </a>
              </span>
            </div>
          )}

          {!isLoading &&
            activeQuestions.map((q, idx) => (
              <div key={q.id}>
                <label className="label">
                  <span className="text-gray-500 mr-1 text-xs">{idx + 1}.</span>
                  {q.text}
                </label>
                {q.kind === "select" ? (
                  <select
                    className="input"
                    value={answers[q.id] ?? ""}
                    onChange={(e) => setAnswer(q.id, e.target.value)}
                  >
                    <option value="">— выберите —</option>
                    {(q.options ?? []).map((opt) => (
                      <option key={opt.id} value={opt.text}>
                        {opt.text}
                      </option>
                    ))}
                  </select>
                ) : (
                  <textarea
                    className="input"
                    rows={2}
                    value={answers[q.id] ?? ""}
                    onChange={(e) => setAnswer(q.id, e.target.value)}
                    placeholder="Ваш ответ…"
                  />
                )}
              </div>
            ))}

          {/* Свободный комментарий — показывается всегда */}
          {!isLoading && (
            <div>
              <label className="label">
                {activeQuestions.length > 0
                  ? "Дополнительный комментарий"
                  : "Комментарий по встрече"}
              </label>
              <textarea
                className="input"
                rows={3}
                value={comment}
                onChange={(e) => setComment(e.target.value)}
                placeholder="Итоги встречи, договорённости, следующие шаги…"
              />
            </div>
          )}
        </div>
      )}
    </Modal>
  );
}
