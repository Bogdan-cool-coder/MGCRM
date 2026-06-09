"use client";

import { useState } from "react";
import { createPortal } from "react-dom";
import useSWR from "swr";
import type { CourseProgressStatus } from "@/lib/types";
import { api, ApiError, fetcher } from "@/lib/api";
import { formatDistanceToNow } from "date-fns";
import { ru } from "date-fns/locale";
import { formatDate } from "@/lib/dates";

interface LessonProgressItem {
  lesson_id: number;
  lesson_title: string;
  lesson_kind: string;
  completed_at: string | null;
  attempts_count: number;
  best_score_pct: number | null;
}

interface ModuleProgressItem {
  module_id: number;
  module_title: string;
  lessons: LessonProgressItem[];
}

interface UserCourseDetailData {
  assignment_id: number;
  user_id: number;
  user_name: string;
  course_id: number;
  course_title: string;
  status: CourseProgressStatus;
  percent: number;
  assigned_at: string;
  due_at: string | null;
  completed_at: string | null;
  is_mandatory: boolean;
  modules: ModuleProgressItem[];
}

interface Props {
  isOpen: boolean;
  userId: number | null;
  courseId: number | null;
  onClose: () => void;
  onRefresh?: () => void;
}

const STATUS_LABELS: Record<CourseProgressStatus, string> = {
  not_started: "Не начат",
  in_progress: "В процессе",
  completed: "Завершён",
  overdue: "Просрочен",
};

const STATUS_CLASSES: Record<CourseProgressStatus, string> = {
  not_started: "bg-gray-100 text-gray-500",
  in_progress: "bg-info/10 text-info",
  completed: "bg-success/10 text-success",
  overdue: "bg-danger/10 text-danger",
};

export function UserCourseDetailsDrawer({ isOpen, userId, courseId, onClose, onRefresh }: Props) {
  const [expandedModules, setExpandedModules] = useState<Set<number>>(new Set());
  const [resetConfirm, setResetConfirm] = useState<{ lessonId: number } | null>(null);
  const [resetting, setResetting] = useState(false);
  const [unassignConfirm, setUnassignConfirm] = useState(false);

  const swrKey = isOpen && userId && courseId
    ? `/admin/onboarding/users/${userId}/progress?course_id=${courseId}`
    : null;

  const { data, isLoading, mutate } = useSWR<UserCourseDetailData>(swrKey, fetcher);

  function toggleModule(moduleId: number) {
    setExpandedModules((prev) => {
      const next = new Set(prev);
      if (next.has(moduleId)) next.delete(moduleId);
      else next.add(moduleId);
      return next;
    });
  }

  async function handleResetQuiz(lessonId: number) {
    if (!userId) return;
    setResetting(true);
    try {
      await api(`/admin/onboarding/quiz-attempts/reset?user_id=${userId}&lesson_id=${lessonId}`, { method: "POST" });
      await mutate();
      onRefresh?.();
    } catch (e) {
      console.error(e);
    }
    setResetting(false);
    setResetConfirm(null);
  }

  async function handleUnassign() {
    if (!data?.assignment_id) return;
    try {
      await api(`/admin/onboarding/assignments/${data.assignment_id}`, { method: "DELETE" });
      onClose();
      onRefresh?.();
    } catch (e) {
      console.error(e);
    }
    setUnassignConfirm(false);
  }

  function formatDueStatus(dueAt: string | null) {
    if (!dueAt) return null;
    try {
      const diff = new Date(dueAt).getTime() - Date.now();
      const days = Math.ceil(diff / 86400000);
      if (days < 0) return { text: `Просрочено на ${Math.abs(days)} дн.`, color: "text-danger" };
      return { text: `Осталось ${days} дн.`, color: "text-gray-500" };
    } catch {
      return null;
    }
  }

  if (!isOpen) return null;

  const dueStatus = data?.due_at ? formatDueStatus(data.due_at) : null;

  return createPortal(
    <div className="fixed inset-0 z-50 flex justify-end">
      <div className="absolute inset-0 bg-black/30" onClick={onClose} />
      <div className="relative w-[480px] h-full bg-white shadow-xl flex flex-col">
        {/* Header */}
        <div className="flex items-center justify-between px-5 py-4 border-b border-gray-200">
          <div className="min-w-0 flex-1">
            <h2 className="text-h5 font-semibold truncate">
              {data ? `${data.user_name} — ${data.course_title}` : "Прогресс"}
            </h2>
          </div>
          <button type="button" className="btn-ghost px-2 py-1" onClick={onClose}>
            <i className="bi bi-x-lg" />
          </button>
        </div>

        {/* Body */}
        <div className="flex-1 overflow-y-auto p-5">
          {isLoading && (
            <div className="text-sm text-gray-400 text-center py-10">Загрузка…</div>
          )}

          {data && (
            <div className="space-y-4">
              {/* Status + progress */}
              <div>
                <div className="flex items-center gap-2 mb-2">
                  <span className={`badge px-2 py-1 rounded text-sm font-medium ${STATUS_CLASSES[data.status]}`}>
                    {STATUS_LABELS[data.status]}
                  </span>
                  <span className="text-sm font-semibold">{data.percent}%</span>
                </div>
                <div className="h-2 rounded-full bg-gray-200">
                  <div
                    className="h-full rounded-full bg-primary transition-all"
                    style={{ width: `${data.percent}%` }}
                  />
                </div>
              </div>

              {/* Dates */}
              <div className="text-sm text-gray-600 space-y-1">
                <div>Назначен: {formatDate(data.assigned_at)}</div>
                {data.due_at && (
                  <div>
                    Дедлайн: {formatDate(data.due_at)}
                    {dueStatus && (
                      <span className={`ml-2 text-xs ${dueStatus.color}`}>
                        ({dueStatus.text})
                      </span>
                    )}
                  </div>
                )}
                {data.completed_at && (
                  <div className="text-success">Завершён: {formatDate(data.completed_at)}</div>
                )}
              </div>

              {/* Modules accordion */}
              <div className="space-y-2">
                {data.modules.map((mod) => {
                  const expanded = expandedModules.has(mod.module_id);
                  return (
                    <div key={mod.module_id} className="card p-0 overflow-hidden">
                      <button
                        type="button"
                        className="flex items-center justify-between w-full px-3 py-2.5 bg-gray-50 hover:bg-gray-100 transition-colors text-sm"
                        onClick={() => toggleModule(mod.module_id)}
                      >
                        <span className="font-medium">{mod.module_title}</span>
                        <i className={`bi ${expanded ? "bi-chevron-up" : "bi-chevron-down"} text-gray-400`} />
                      </button>

                      {expanded && (
                        <div>
                          {mod.lessons.map((lesson) => {
                            const done = !!lesson.completed_at;
                            const isQuiz = lesson.lesson_kind === "quiz";
                            return (
                              <div
                                key={lesson.lesson_id}
                                className="flex items-center gap-2 px-3 py-2 border-t border-gray-100 text-sm"
                              >
                                <i
                                  className={`bi shrink-0 ${
                                    done
                                      ? "bi-check-circle-fill text-success"
                                      : lesson.attempts_count > 0
                                      ? "bi-circle-half text-info"
                                      : "bi-circle text-gray-300"
                                  }`}
                                />
                                <span className="flex-1 truncate">{lesson.lesson_title}</span>
                                {isQuiz && lesson.attempts_count > 0 && (
                                  <span className="text-xs text-gray-400">
                                    {lesson.attempts_count} поп.{lesson.best_score_pct != null ? ` · ${lesson.best_score_pct}%` : ""}
                                  </span>
                                )}
                                {isQuiz && (
                                  <button
                                    type="button"
                                    className="btn-ghost text-xs px-1.5 py-0.5 text-danger hover:bg-danger/10"
                                    onClick={() => setResetConfirm({ lessonId: lesson.lesson_id })}
                                    title="Сбросить попытки квиза"
                                  >
                                    Сбросить
                                  </button>
                                )}
                              </div>
                            );
                          })}
                        </div>
                      )}
                    </div>
                  );
                })}
              </div>
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="px-5 py-3 border-t border-gray-200 bg-gray-50 flex items-center justify-between">
          <button
            type="button"
            className="btn-secondary text-sm text-danger border-danger/30 hover:bg-danger/5"
            onClick={() => setUnassignConfirm(true)}
          >
            Снять назначение
          </button>
          <button type="button" className="btn-ghost" onClick={onClose}>
            Закрыть
          </button>
        </div>
      </div>

      {/* Reset quiz confirm */}
      {resetConfirm && (
        <div className="fixed inset-0 z-[60] bg-black/50 flex items-center justify-center p-4">
          <div className="bg-white rounded-lg shadow-xl max-w-sm w-full p-6">
            <h3 className="text-h5 mb-2">Сбросить попытки квиза?</h3>
            <p className="text-sm text-gray-600 mb-4">
              Все попытки прохождения этого квиза будут удалены. Пользователь сможет пройти заново.
            </p>
            <div className="flex justify-end gap-2">
              <button className="btn-ghost" onClick={() => setResetConfirm(null)}>Отмена</button>
              <button
                className="btn-primary"
                disabled={resetting}
                onClick={() => handleResetQuiz(resetConfirm.lessonId)}
              >
                {resetting ? "…" : "Сбросить"}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Unassign confirm */}
      {unassignConfirm && (
        <div className="fixed inset-0 z-[60] bg-black/50 flex items-center justify-center p-4">
          <div className="bg-white rounded-lg shadow-xl max-w-sm w-full p-6">
            <h3 className="text-h5 mb-2">Снять назначение?</h3>
            <p className="text-sm text-gray-600 mb-4">
              Прогресс пользователя по этому курсу будет сохранён, но назначение удалится.
            </p>
            <div className="flex justify-end gap-2">
              <button className="btn-ghost" onClick={() => setUnassignConfirm(false)}>Отмена</button>
              <button className="btn-primary text-white bg-danger border-danger hover:bg-danger/80" onClick={handleUnassign}>
                Снять
              </button>
            </div>
          </div>
        </div>
      )}
    </div>,
    document.body
  );
}
