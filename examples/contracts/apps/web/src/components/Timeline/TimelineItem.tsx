"use client";

import React, { useEffect, useRef, useState } from "react";
import { ActivityKindIcon } from "./ActivityKindIcon";
import { api, ApiError } from "@/lib/api";
import { formatDateTimeShort } from "@/lib/dates";
import {
  ACTIVITY_KIND_LABELS,
  type Activity,
} from "@/lib/types";

interface TimelineItemProps {
  activity: Activity;
  /** Может ли текущий пользователь удалить запись */
  canDelete: boolean;
  /** Callback после успешного complete/reopen/delete */
  onMutated: () => void;
  /** Запрос на переход в режим редактирования */
  onEdit: (activity: Activity) => void;
  /**
   * Wave 5: текст бейджа источника («по сделке «{title}»» / «по компании»).
   * null — не показывать (запись принадлежит самой просматриваемой сущности).
   */
  sourceBadge?: string | null;
}

const COLLAPSED_BODY_THRESHOLD = 180;

function TimelineItemBase({ activity, canDelete, onMutated, onEdit, sourceBadge = null }: TimelineItemProps) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [menuOpen, setMenuOpen] = useState(false);
  const [expanded, setExpanded] = useState(false);
  const menuRef = useRef<HTMLDivElement>(null);

  const isTask = activity.kind === "task";
  const isNote = activity.kind === "note";
  const isCompleted = !!activity.completed_at;
  const isOverdue = !!(
    activity.due_at &&
    !isCompleted &&
    new Date(activity.due_at).getTime() < Date.now()
  );

  // Закрытие меню по клику снаружи
  useEffect(() => {
    if (!menuOpen) return;
    function onDoc(e: MouseEvent) {
      if (menuRef.current && !menuRef.current.contains(e.target as Node)) {
        setMenuOpen(false);
      }
    }
    document.addEventListener("mousedown", onDoc);
    return () => document.removeEventListener("mousedown", onDoc);
  }, [menuOpen]);

  async function toggleComplete() {
    if (busy) return;
    setBusy(true);
    setError(null);
    try {
      const url = isCompleted
        ? `/activities/${activity.id}/reopen`
        : `/activities/${activity.id}/complete`;
      await api(url, { method: "POST" });
      onMutated();
    } catch (err) {
      setError(err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Не удалось обновить статус");
    } finally {
      setBusy(false);
    }
  }

  async function del() {
    if (!confirm(`Удалить запись «${activity.title}»? Действие необратимо.`)) return;
    setBusy(true);
    setError(null);
    try {
      await api(`/activities/${activity.id}`, { method: "DELETE" });
      onMutated();
    } catch (err) {
      setError(err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Не удалось удалить");
      setBusy(false);
    }
  }

  const body = activity.body ?? "";
  const needsCollapse = body.length > COLLAPSED_BODY_THRESHOLD;
  const visibleBody = !needsCollapse || expanded
    ? body
    : body.slice(0, COLLAPSED_BODY_THRESHOLD) + "…";

  const dimmed = isCompleted && (isTask || activity.kind === "call" || activity.kind === "meeting");

  return (
    <div className={`flex gap-3 ${dimmed ? "opacity-70" : ""}`}>
      {/* Левая колонка: иконка-маркер + чекбокс для task */}
      <div className="flex flex-col items-center pt-1 shrink-0">
        {isTask ? (
          <button
            type="button"
            onClick={toggleComplete}
            disabled={busy}
            title={isCompleted ? "Переоткрыть задачу" : "Отметить выполненной"}
            className="inline-flex items-center justify-center w-8 h-8 rounded-full border border-gray-300 hover:border-primary disabled:opacity-50"
          >
            {isCompleted ? (
              <i className="bi bi-check-circle-fill text-success text-base" />
            ) : (
              <ActivityKindIcon kind="task" overdue={isOverdue} marker />
            )}
          </button>
        ) : (
          <ActivityKindIcon kind={activity.kind} marker />
        )}
      </div>

      {/* Контент */}
      <div className="flex-1 min-w-0 border border-gray-100 rounded-lg px-3 py-2 bg-white">
        {/* Заголовок + меню */}
        <div className="flex items-start justify-between gap-2">
          <div className="min-w-0 flex-1">
            <div className="flex items-center gap-2 flex-wrap">
              <span className="text-xs uppercase tracking-wide text-gray-400">
                {ACTIVITY_KIND_LABELS[activity.kind]}
              </span>
              {isOverdue && (
                <span className="text-[10px] uppercase tracking-wide px-1.5 py-0.5 rounded bg-danger/20 text-danger">
                  Просрочена
                </span>
              )}
              {isCompleted && (
                <span className="text-[10px] uppercase tracking-wide px-1.5 py-0.5 rounded bg-success/30 text-gray-700">
                  Выполнена
                </span>
              )}
              {sourceBadge && (
                <span className="text-[10px] px-1.5 py-0.5 rounded bg-info-50 text-info-700 dark:bg-info-500/10 dark:text-info-500">
                  <i className="bi bi-link-45deg mr-0.5" />{sourceBadge}
                </span>
              )}
            </div>
            <div className={`font-medium text-sm mt-0.5 ${isCompleted && isTask ? "line-through text-gray-600" : ""}`}>
              {activity.title}
            </div>
          </div>

          {/* ⋮ меню */}
          <div className="relative shrink-0" ref={menuRef}>
            <button
              type="button"
              onClick={() => setMenuOpen((v) => !v)}
              className="text-gray-400 hover:text-primary px-1"
              title="Действия"
              disabled={busy}
            >
              <i className="bi bi-three-dots-vertical" />
            </button>
            {menuOpen && (
              <div className="absolute right-0 top-full mt-1 z-20 bg-white border border-gray-200 rounded-lg shadow-lg w-44 py-1 text-sm">
                <button
                  type="button"
                  onClick={() => { setMenuOpen(false); onEdit(activity); }}
                  className="w-full text-left px-3 py-1.5 hover:bg-gray-50 flex items-center gap-2"
                >
                  <i className="bi bi-pencil" /> Редактировать
                </button>
                {!isNote && (
                  <button
                    type="button"
                    onClick={() => { setMenuOpen(false); toggleComplete(); }}
                    className="w-full text-left px-3 py-1.5 hover:bg-gray-50 flex items-center gap-2"
                  >
                    {isCompleted ? (
                      <><i className="bi bi-arrow-counterclockwise" /> Переоткрыть</>
                    ) : (
                      <><i className="bi bi-check2" /> Отметить выполненной</>
                    )}
                  </button>
                )}
                {canDelete && (
                  <>
                    <div className="border-t border-gray-100 my-1" />
                    <button
                      type="button"
                      onClick={() => { setMenuOpen(false); del(); }}
                      className="w-full text-left px-3 py-1.5 hover:bg-danger/10 text-danger flex items-center gap-2"
                    >
                      <i className="bi bi-trash" /> Удалить
                    </button>
                  </>
                )}
              </div>
            )}
          </div>
        </div>

        {/* Body */}
        {body && (
          <div className="text-sm text-gray-700 whitespace-pre-wrap mt-1">
            {visibleBody}
            {needsCollapse && (
              <button
                type="button"
                onClick={() => setExpanded((v) => !v)}
                className="ml-2 text-primary text-xs hover:underline"
              >
                {expanded ? "свернуть" : "развернуть"}
              </button>
            )}
          </div>
        )}

        {/* Мета: автор / срок / исполнитель / время */}
        <div className="text-xs text-gray-500 mt-2 flex flex-wrap gap-x-3 gap-y-1">
          {activity.created_by_name && (
            <span><i className="bi bi-person mr-0.5" />{activity.created_by_name}</span>
          )}
          {activity.responsible_id && activity.responsible_name && activity.responsible_id !== activity.created_by_id && (
            <span><i className="bi bi-arrow-right mr-0.5" />{activity.responsible_name}</span>
          )}
          {activity.due_at && (
            <span className={isOverdue ? "text-danger" : ""}>
              <i className="bi bi-clock mr-0.5" />до {formatDateTimeShort(activity.due_at)}
            </span>
          )}
          {isCompleted && activity.completed_at && (
            <span>
              <i className="bi bi-check2-all mr-0.5" />
              {formatDateTimeShort(activity.completed_at)}
              {activity.completed_by_name ? `, ${activity.completed_by_name}` : ""}
            </span>
          )}
          <span className="ml-auto text-gray-400">{formatDateTimeShort(activity.created_at)}</span>
        </div>

        {error && (
          <div className="text-xs text-danger mt-2">{error}</div>
        )}
      </div>
    </div>
  );
}

export const TimelineItem = React.memo(TimelineItemBase);
