"use client";

import { useState } from "react";
import useSWR from "swr";
import { Modal } from "@/components/Modal";
import { api, ApiError, fetcher } from "@/lib/api";
import type { MeetingQuestion, MeetingQuestionOption } from "@/lib/types";

interface QuestionFormState {
  id?: number;
  text: string;
  kind: "text" | "select";
  sort_order: string;
  is_active: boolean;
}

interface OptionFormState {
  id?: number;
  text: string;
  sort_order: string;
}

export function MeetingQuestionAdmin() {
  const { data, mutate } = useSWR<MeetingQuestion[]>("/deals/meeting-questions", fetcher);
  const [form, setForm] = useState<QuestionFormState | null>(null);
  const [expandedId, setExpandedId] = useState<number | null>(null);
  const [optionForm, setOptionForm] = useState<{ questionId: number; form: OptionFormState } | null>(null);
  const [deleting, setDeleting] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

  const items = data ?? [];

  async function saveQuestion() {
    if (!form || !form.text.trim()) {
      setError("Текст вопроса обязателен");
      return;
    }
    setError(null);
    try {
      const body = {
        text: form.text.trim(),
        kind: form.kind,
        sort_order: Number(form.sort_order) || 0,
        is_active: form.is_active,
      };
      if (form.id) {
        await api(`/deals/meeting-questions/${form.id}`, { method: "PATCH", body });
      } else {
        await api("/deals/meeting-questions", { method: "POST", body });
      }
      await mutate();
      setForm(null);
    } catch (e) {
      setError(
        e instanceof ApiError
          ? String((e.detail as { detail?: string })?.detail ?? e.message)
          : "Ошибка сохранения"
      );
    }
  }

  async function removeQuestion(id: number) {
    if (!confirm("Удалить вопрос?")) return;
    setDeleting(id);
    try {
      await api(`/deals/meeting-questions/${id}`, { method: "DELETE" });
      await mutate();
    } catch {
      // ignore
    } finally {
      setDeleting(null);
    }
  }

  async function saveOption(questionId: number, opt: OptionFormState) {
    if (!opt.text.trim()) return;
    try {
      const body = { text: opt.text.trim(), sort_order: Number(opt.sort_order) || 0 };
      if (opt.id) {
        await api(`/deals/meeting-questions/${questionId}/options/${opt.id}`, {
          method: "PATCH",
          body,
        });
      } else {
        await api(`/deals/meeting-questions/${questionId}/options`, {
          method: "POST",
          body,
        });
      }
      await mutate();
      setOptionForm(null);
    } catch {
      // ignore
    }
  }

  async function removeOption(questionId: number, optionId: number) {
    try {
      await api(`/deals/meeting-questions/${questionId}/options/${optionId}`, {
        method: "DELETE",
      });
      await mutate();
    } catch {
      // ignore
    }
  }

  return (
    <div>
      <div className="flex items-center justify-between mb-4">
        <div>
          <h3 className="text-base font-semibold text-gray-800 dark:text-gray-100">
            Вопросы встречи
          </h3>
          <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
            Заполняются менеджером при создании отчёта по встрече
          </p>
        </div>
        <button
          className="btn-primary text-sm"
          onClick={() =>
            setForm({
              text: "",
              kind: "text",
              sort_order: String(items.length * 10),
              is_active: true,
            })
          }
        >
          <i className="bi bi-plus mr-1" />Добавить
        </button>
      </div>

      {items.length === 0 && !data && (
        <div className="py-6 text-center text-gray-400 text-sm">Загрузка…</div>
      )}
      {items.length === 0 && data && (
        <div className="py-6 text-center text-gray-400 text-sm">
          <i className="bi bi-chat-square-text text-2xl block mb-2" />
          Нет вопросов
        </div>
      )}

      <div className="space-y-2">
        {items.map((q) => (
          <div key={q.id} className="border border-gray-200 dark:border-gray-700 rounded-lg">
            <div className="flex items-center gap-2 px-3 py-2.5">
              <span
                className={`text-xs px-1.5 py-0.5 rounded font-mono ${
                  q.kind === "select"
                    ? "bg-primary/10 text-primary"
                    : "bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400"
                }`}
              >
                {q.kind === "select" ? "select" : "text"}
              </span>
              <span className="flex-1 text-sm text-gray-800 dark:text-gray-200 truncate">
                {q.text}
              </span>
              {q.is_active === false && (
                <span className="text-xs text-gray-400 shrink-0">выкл</span>
              )}
              {q.kind === "select" && (
                <button
                  className="btn-ghost text-xs p-1 text-gray-500"
                  title="Варианты ответов"
                  onClick={() => setExpandedId(expandedId === q.id ? null : q.id)}
                >
                  <i className={`bi ${expandedId === q.id ? "bi-chevron-up" : "bi-chevron-down"}`} />
                </button>
              )}
              <button
                className="btn-ghost text-xs p-1"
                onClick={() =>
                  setForm({
                    id: q.id,
                    text: q.text,
                    kind: q.kind,
                    sort_order: String(q.sort_order),
                    is_active: q.is_active,
                  })
                }
              >
                <i className="bi bi-pencil" />
              </button>
              <button
                className="btn-ghost text-xs p-1 text-danger"
                disabled={deleting === q.id}
                onClick={() => void removeQuestion(q.id)}
              >
                <i className={deleting === q.id ? "bi bi-hourglass" : "bi bi-trash"} />
              </button>
            </div>

            {/* Options inline (for select kind) */}
            {q.kind === "select" && expandedId === q.id && (
              <div className="border-t border-gray-100 dark:border-gray-700 px-4 pb-3 pt-2">
                <div className="text-xs font-medium text-gray-500 dark:text-gray-400 mb-2">
                  Варианты ответов
                </div>
                <div className="space-y-1 mb-2">
                  {(q.options ?? []).map((opt) => (
                    <OptionRow
                      key={opt.id}
                      option={opt}
                      questionId={q.id}
                      onEdit={() =>
                        setOptionForm({
                          questionId: q.id,
                          form: { id: opt.id, text: opt.text, sort_order: String(opt.sort_order) },
                        })
                      }
                      onDelete={() => void removeOption(q.id, opt.id)}
                    />
                  ))}
                  {(q.options ?? []).length === 0 && (
                    <div className="text-xs text-gray-400">Нет вариантов</div>
                  )}
                </div>
                <button
                  className="text-xs text-primary hover:underline flex items-center gap-1"
                  onClick={() =>
                    setOptionForm({
                      questionId: q.id,
                      form: { text: "", sort_order: String((q.options ?? []).length * 10) },
                    })
                  }
                >
                  <i className="bi bi-plus" />Добавить вариант
                </button>
              </div>
            )}
          </div>
        ))}
      </div>

      {/* Question form modal */}
      {form && (
        <Modal
          open
          title={form.id ? "Редактировать вопрос" : "Новый вопрос встречи"}
          onClose={() => { setForm(null); setError(null); }}
          width="sm"
          footer={
            <>
              <button className="btn-ghost" onClick={() => { setForm(null); setError(null); }}>
                Отмена
              </button>
              <button
                className="btn-primary disabled:opacity-50"
                onClick={saveQuestion}
                disabled={!form.text.trim()}
              >
                Сохранить
              </button>
            </>
          }
        >
          <div className="space-y-4">
            {error && (
              <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">{error}</div>
            )}
            <div>
              <label className="label">Текст вопроса <span className="text-danger">*</span></label>
              <textarea
                className="input"
                autoFocus
                rows={2}
                value={form.text}
                onChange={(e) => setForm({ ...form, text: e.target.value })}
                placeholder="Напр.: «Какой результат ожидает клиент?»"
              />
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="label">Тип</label>
                <select
                  className="input"
                  value={form.kind}
                  onChange={(e) => setForm({ ...form, kind: e.target.value as "text" | "select" })}
                >
                  <option value="text">Текстовый</option>
                  <option value="select">С вариантами</option>
                </select>
              </div>
              <div>
                <label className="label">Сортировка</label>
                <input
                  className="input"
                  type="number"
                  min={0}
                  value={form.sort_order}
                  onChange={(e) => setForm({ ...form, sort_order: e.target.value })}
                />
              </div>
            </div>
            <label className="flex items-center gap-2 cursor-pointer text-sm">
              <input
                type="checkbox"
                checked={form.is_active}
                onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
              />
              Активен
            </label>
          </div>
        </Modal>
      )}

      {/* Option form modal */}
      {optionForm && (
        <Modal
          open
          title={optionForm.form.id ? "Редактировать вариант" : "Новый вариант ответа"}
          onClose={() => setOptionForm(null)}
          width="sm"
          footer={
            <>
              <button className="btn-ghost" onClick={() => setOptionForm(null)}>Отмена</button>
              <button
                className="btn-primary disabled:opacity-50"
                onClick={() => void saveOption(optionForm.questionId, optionForm.form)}
                disabled={!optionForm.form.text.trim()}
              >
                Сохранить
              </button>
            </>
          }
        >
          <div className="space-y-3">
            <div>
              <label className="label">Текст варианта <span className="text-danger">*</span></label>
              <input
                className="input"
                autoFocus
                value={optionForm.form.text}
                onChange={(e) =>
                  setOptionForm({ ...optionForm, form: { ...optionForm.form, text: e.target.value } })
                }
                placeholder="Напр.: «Да»"
              />
            </div>
            <div>
              <label className="label">Сортировка</label>
              <input
                className="input w-28"
                type="number"
                min={0}
                value={optionForm.form.sort_order}
                onChange={(e) =>
                  setOptionForm({
                    ...optionForm,
                    form: { ...optionForm.form, sort_order: e.target.value },
                  })
                }
              />
            </div>
          </div>
        </Modal>
      )}
    </div>
  );
}

function OptionRow({
  option,
  questionId: _questionId,
  onEdit,
  onDelete,
}: {
  option: MeetingQuestionOption;
  questionId: number;
  onEdit: () => void;
  onDelete: () => void;
}) {
  return (
    <div className="flex items-center gap-2 text-sm">
      <span className="text-gray-400 text-xs w-6 text-right">{option.sort_order}</span>
      <span className="flex-1 text-gray-700 dark:text-gray-300">{option.text}</span>
      <button className="btn-ghost text-xs p-0.5" onClick={onEdit}>
        <i className="bi bi-pencil" />
      </button>
      <button className="btn-ghost text-xs p-0.5 text-danger" onClick={onDelete}>
        <i className="bi bi-trash" />
      </button>
    </div>
  );
}
