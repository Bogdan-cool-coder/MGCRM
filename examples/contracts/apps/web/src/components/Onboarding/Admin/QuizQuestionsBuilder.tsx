"use client";

import { useState } from "react";
import { DndContext, closestCenter, type DragEndEvent } from "@dnd-kit/core";
import { SortableContext, verticalListSortingStrategy, arrayMove } from "@dnd-kit/sortable";
import { SortableItem } from "@/components/SortableItem";
import type { LessonQuizQuestion, QuizQuestionKind } from "@/lib/types";
import { EmptyState } from "@/components/EmptyState";

// Локальный тип для редактирования (id может быть null для новых)
interface EditableQuestion {
  id: number | null;
  kind: QuizQuestionKind;
  text: string;
  options: string[];
  correct_answers: number[];
  explanation: string;
  points: number;
}

interface Props {
  questions: LessonQuizQuestion[];
  onChange: (questions: LessonQuizQuestion[]) => void;
}

function questionToEditable(q: LessonQuizQuestion): EditableQuestion {
  return {
    id: q.id,
    kind: q.kind,
    text: q.text,
    options: [...q.options],
    correct_answers: [...(q.correct_answers ?? [])],
    explanation: q.explanation ?? "",
    points: q.points,
  };
}

function editableToQuestion(eq: EditableQuestion, orderIndex: number): LessonQuizQuestion {
  return {
    id: eq.id ?? 0,
    lesson_id: 0, // заполняется сервером
    kind: eq.kind,
    text: eq.text,
    options: eq.options,
    correct_answers: eq.correct_answers,
    explanation: eq.explanation || null,
    points: eq.points,
    order_index: orderIndex,
  };
}

export function QuizQuestionsBuilder({ questions, onChange }: Props) {
  const [editables, setEditables] = useState<EditableQuestion[]>(
    questions.map(questionToEditable)
  );

  function notifyParent(next: EditableQuestion[]) {
    setEditables(next);
    onChange(next.map((eq, i) => editableToQuestion(eq, i)));
  }

  function addQuestion() {
    notifyParent([
      ...editables,
      {
        id: null,
        kind: "single",
        text: "",
        options: ["", ""],
        correct_answers: [],
        explanation: "",
        points: 1,
      },
    ]);
  }

  function updateQuestion(idx: number, partial: Partial<EditableQuestion>) {
    const next = editables.map((q, i) => (i === idx ? { ...q, ...partial } : q));
    notifyParent(next);
  }

  function removeQuestion(idx: number) {
    notifyParent(editables.filter((_, i) => i !== idx));
  }

  function handleDragEnd(event: DragEndEvent) {
    const { active, over } = event;
    if (!over || active.id === over.id) return;
    const oldIdx = editables.findIndex((_, i) => i === active.id);
    const newIdx = editables.findIndex((_, i) => i === over.id);
    if (oldIdx < 0 || newIdx < 0) return;
    notifyParent(arrayMove(editables, oldIdx, newIdx));
  }

  function addOption(qIdx: number) {
    const q = editables[qIdx];
    updateQuestion(qIdx, { options: [...q.options, ""] });
  }

  function updateOption(qIdx: number, optIdx: number, value: string) {
    const q = editables[qIdx];
    const options = q.options.map((o, i) => (i === optIdx ? value : o));
    updateQuestion(qIdx, { options });
  }

  function removeOption(qIdx: number, optIdx: number) {
    const q = editables[qIdx];
    if (q.options.length <= 2) return;
    const options = q.options.filter((_, i) => i !== optIdx);
    // Adjust correct_answers indices
    const correct = q.correct_answers
      .filter((ci) => ci !== optIdx)
      .map((ci) => (ci > optIdx ? ci - 1 : ci));
    updateQuestion(qIdx, { options, correct_answers: correct });
  }

  function toggleCorrect(qIdx: number, optIdx: number) {
    const q = editables[qIdx];
    if (q.kind === "single") {
      updateQuestion(qIdx, { correct_answers: [optIdx] });
    } else {
      const existing = q.correct_answers.includes(optIdx);
      const correct = existing
        ? q.correct_answers.filter((ci) => ci !== optIdx)
        : [...q.correct_answers, optIdx];
      updateQuestion(qIdx, { correct_answers: correct });
    }
  }

  function switchKind(qIdx: number, kind: QuizQuestionKind) {
    const q = editables[qIdx];
    const multipleCorrect = q.correct_answers.length > 1;
    if (kind === "single" && multipleCorrect) {
      if (!confirm("При переключении на «один правильный» отметки правильных ответов сбросятся. Продолжить?")) {
        return;
      }
      updateQuestion(qIdx, { kind, correct_answers: [] });
    } else {
      updateQuestion(qIdx, { kind });
    }
  }

  return (
    <div>
      {editables.length === 0 && (
        <EmptyState
          icon="bi-question-circle"
          title="Вопросов пока нет"
          description="Минимум 5 вопросов для финального квиза модуля"
        />
      )}

      <DndContext collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
        <SortableContext
          items={editables.map((_, i) => i)}
          strategy={verticalListSortingStrategy}
        >
      <div className="space-y-3">
        {editables.map((q, qIdx) => {
          const noCorrect = q.correct_answers.length === 0;

          return (
            <SortableItem key={qIdx} id={qIdx}>
            <div className="card p-4 flex-1">
              {/* Question header */}
              <div className="flex items-start justify-between gap-2 mb-3">
                <span className="text-sm font-medium text-gray-600">Вопрос {qIdx + 1}</span>
                <div className="flex items-center gap-1">
                  <button
                    type="button"
                    className="btn-ghost text-xs px-1.5 py-0.5 text-danger hover:bg-danger/10"
                    onClick={() => removeQuestion(qIdx)}
                  >
                    <i className="bi bi-trash" />
                  </button>
                </div>
              </div>

              {/* Question text */}
              <div className="mb-3">
                <label className="label">Текст вопроса</label>
                <textarea
                  className="input min-h-[60px] resize-y"
                  rows={2}
                  value={q.text}
                  onChange={(e) => updateQuestion(qIdx, { text: e.target.value })}
                  placeholder="Введите вопрос..."
                />
              </div>

              {/* Kind */}
              <div className="mb-3 flex gap-4">
                <label className="flex items-center gap-1.5 text-sm cursor-pointer">
                  <input
                    type="radio"
                    name={`kind-${qIdx}`}
                    checked={q.kind === "single"}
                    onChange={() => switchKind(qIdx, "single")}
                  />
                  Один правильный
                </label>
                <label className="flex items-center gap-1.5 text-sm cursor-pointer">
                  <input
                    type="radio"
                    name={`kind-${qIdx}`}
                    checked={q.kind === "multi"}
                    onChange={() => switchKind(qIdx, "multi")}
                  />
                  Несколько правильных
                </label>
              </div>

              {/* Options */}
              <div className="space-y-1.5 mb-3">
                {q.options.map((opt, optIdx) => (
                  <div key={optIdx} className="flex items-center gap-2">
                    {q.kind === "single" ? (
                      <input
                        type="radio"
                        name={`correct-${qIdx}`}
                        checked={q.correct_answers.includes(optIdx)}
                        onChange={() => toggleCorrect(qIdx, optIdx)}
                        title="Правильный ответ"
                        className="shrink-0"
                      />
                    ) : (
                      <input
                        type="checkbox"
                        checked={q.correct_answers.includes(optIdx)}
                        onChange={() => toggleCorrect(qIdx, optIdx)}
                        title="Правильный ответ"
                        className="shrink-0"
                      />
                    )}
                    <input
                      className="input flex-1"
                      value={opt}
                      onChange={(e) => updateOption(qIdx, optIdx, e.target.value)}
                      placeholder={`Вариант ${optIdx + 1}`}
                    />
                    <button
                      type="button"
                      className={`btn-ghost text-xs px-1.5 py-1 text-danger hover:bg-danger/10 ${q.options.length <= 2 ? "opacity-30 cursor-not-allowed" : ""}`}
                      onClick={() => removeOption(qIdx, optIdx)}
                      disabled={q.options.length <= 2}
                      title="Удалить вариант"
                    >
                      <i className="bi bi-trash text-xs" />
                    </button>
                  </div>
                ))}
              </div>

              {noCorrect && (
                <p className="text-danger text-xs mb-2">
                  Отметь хотя бы один правильный ответ
                </p>
              )}

              <button
                type="button"
                className="btn-ghost text-xs flex items-center gap-1 mb-3"
                onClick={() => addOption(qIdx)}
              >
                <i className="bi bi-plus-lg" />
                Добавить вариант
              </button>

              {/* Explanation */}
              <div className="mb-3">
                <label className="label">Пояснение после ответа (опц.)</label>
                <textarea
                  className="input resize-y"
                  rows={2}
                  value={q.explanation}
                  onChange={(e) => updateQuestion(qIdx, { explanation: e.target.value })}
                  placeholder="Объяснение правильного ответа..."
                />
              </div>

              {/* Points */}
              <div>
                <label className="label">Балл за вопрос</label>
                <input
                  type="number"
                  className="input w-24"
                  min={1}
                  max={10}
                  value={q.points}
                  onChange={(e) => updateQuestion(qIdx, { points: Number(e.target.value) || 1 })}
                />
              </div>
            </div>
            </SortableItem>
          );
        })}
      </div>
        </SortableContext>
      </DndContext>

      <button
        type="button"
        className="btn-secondary text-sm mt-3 flex items-center gap-1"
        onClick={addQuestion}
      >
        <i className="bi bi-plus-lg" />
        Добавить вопрос
      </button>
    </div>
  );
}
