"use client";

import { useState } from "react";
import Link from "next/link";
import { api } from "@/lib/api";
import { UserSelect } from "@/components/UserSelect";
import { Modal } from "@/components/Modal";
import { RecurrenceSelector } from "./RecurrenceSelector";
import type { Activity, ActivityCollaborator, CollaboratorRole, ActivityTargetType } from "@/lib/types";
import { ACTIVITY_TARGET_LABELS } from "@/lib/types";

type RecurrenceRuleValue = "none" | "daily" | "weekly" | "monthly";

interface Props {
  task: Activity;
  onMutate: () => void;
}

const ROLE_LABELS: Record<CollaboratorRole, string> = {
  co_executor: "Соисполнители",
  auditor: "Аудиторы",
  observer: "Наблюдатели",
};

export function TaskDetailSidebar({ task, onMutate }: Props) {
  const [rejectOpen, setRejectOpen] = useState(false);
  const [rejectReason, setRejectReason] = useState("");
  const [addCollab, setAddCollab] = useState<CollaboratorRole | null>(null);
  const [collabUserId, setCollabUserId] = useState("");
  const [savingCollab, setSavingCollab] = useState(false);
  const [tagInput, setTagInput] = useState("");
  const [submitting, setSubmitting] = useState(false);

  async function changeResponsible(userId: string) {
    if (!userId) return;
    await api(`/activities/${task.id}`, {
      method: "PATCH",
      body: { responsible_id: Number(userId) },
    });
    onMutate();
  }

  async function removeCollaborator(userId: number) {
    await api(`/activities/${task.id}/collaborators/${userId}`, { method: "DELETE" });
    onMutate();
  }

  async function addCollaborator() {
    if (!collabUserId || !addCollab) return;
    setSavingCollab(true);
    try {
      await api(`/activities/${task.id}/collaborators`, {
        method: "POST",
        body: { user_id: Number(collabUserId), role: addCollab },
      });
      setAddCollab(null);
      setCollabUserId("");
      onMutate();
    } finally {
      setSavingCollab(false);
    }
  }

  async function togglePin() {
    await api(`/activities/${task.id}/pin`, { method: "PATCH" });
    onMutate();
  }

  async function toggleFavorite() {
    await api(`/activities/${task.id}/favorite`, { method: "PATCH" });
    onMutate();
  }

  async function extendDeadline(days: number) {
    await api(`/activities/${task.id}/extend-deadline`, {
      method: "POST",
      body: { days, reason: `Перенос на +${days} дн` },
    });
    onMutate();
  }

  async function rejectTask() {
    if (!rejectReason.trim()) return;
    setSubmitting(true);
    try {
      await api(`/activities/${task.id}/status`, {
        method: "PATCH",
        body: { status: "rejected", reject_reason: rejectReason.trim() },
      });
      setRejectOpen(false);
      setRejectReason("");
      onMutate();
    } finally {
      setSubmitting(false);
    }
  }

  async function addTag() {
    const tag = tagInput.trim();
    if (!tag) return;
    const tags = [...(task.tags ?? [])];
    if (!tags.includes(tag)) tags.push(tag);
    await api(`/activities/${task.id}`, { method: "PATCH", body: { tags } });
    setTagInput("");
    onMutate();
  }

  async function removeTag(tag: string) {
    const tags = (task.tags ?? []).filter((t) => t !== tag);
    await api(`/activities/${task.id}`, { method: "PATCH", body: { tags } });
    onMutate();
  }

  async function updateRecurrence(rule: RecurrenceRuleValue, until: string | null) {
    const body: Record<string, unknown> = {
      recurrence_rule: rule === "none" ? null : rule,
    };
    if (rule !== "none") body.recurrence_until = until;
    else body.recurrence_until = null;
    await api(`/activities/${task.id}`, { method: "PATCH", body });
    onMutate();
  }

  const collaboratorsByRole = {
    co_executor: (task.collaborators ?? []).filter((c) => c.role === "co_executor"),
    auditor: (task.collaborators ?? []).filter((c) => c.role === "auditor"),
    observer: (task.collaborators ?? []).filter((c) => c.role === "observer"),
  };

  const currentRecurrence: RecurrenceRuleValue =
    task.recurrence_rule ? (task.recurrence_rule as RecurrenceRuleValue) : "none";

  return (
    <div className="w-80 shrink-0 border-l border-gray-200 dark:border-gray-700 overflow-y-auto">
      <div className="p-5 space-y-5 text-sm">
        {/* Создал */}
        <div>
          <div className="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1.5">Создал</div>
          <div className="text-gray-700 dark:text-gray-300">
            {task.created_by_name ?? "—"}
            {task.created_at && (
              <span className="text-gray-400 ml-2">
                {new Date(task.created_at).toLocaleDateString("ru-RU", { day: "numeric", month: "short", year: "numeric" })}
              </span>
            )}
          </div>
        </div>

        <hr className="border-gray-100 dark:border-gray-700" />

        {/* Ответственный */}
        <div>
          <div className="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1.5">Ответственный</div>
          <UserSelect
            value={String(task.responsible_id ?? "")}
            onChange={changeResponsible}
            placeholder="Не назначен"
            className="input text-sm"
          />
        </div>

        {/* Участники по ролям */}
        {(["co_executor", "auditor", "observer"] as CollaboratorRole[]).map((role) => {
          const list = collaboratorsByRole[role];
          return (
            <div key={role}>
              <div className="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1.5">
                {ROLE_LABELS[role]}
              </div>
              <div className="space-y-1.5">
                {list.map((c) => (
                  <div key={c.user_id} className="flex items-center gap-2">
                    <span className="text-gray-700 dark:text-gray-300 flex-1">{c.user_name}</span>
                    <button
                      onClick={() => removeCollaborator(c.user_id)}
                      className="text-gray-400 hover:text-danger p-0.5"
                    >
                      <i className="bi bi-x text-sm" />
                    </button>
                  </div>
                ))}
                {addCollab === role ? (
                  <div className="flex items-center gap-2">
                    <UserSelect
                      value={collabUserId}
                      onChange={setCollabUserId}
                      placeholder="Выбрать..."
                      className="input text-sm flex-1"
                    />
                    <button
                      disabled={!collabUserId || savingCollab}
                      onClick={addCollaborator}
                      className="btn-primary text-xs py-1"
                    >
                      OK
                    </button>
                    <button onClick={() => { setAddCollab(null); setCollabUserId(""); }} className="btn-ghost text-xs py-1">
                      ✕
                    </button>
                  </div>
                ) : (
                  <button
                    onClick={() => setAddCollab(role)}
                    className="text-xs text-primary hover:underline"
                  >
                    + Добавить
                  </button>
                )}
              </div>
            </div>
          );
        })}

        <hr className="border-gray-100 dark:border-gray-700" />

        {/* Привязка */}
        {task.target_type && task.target_id && (
          <div>
            <div className="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1.5">Привязка</div>
            <span className="badge bg-primary/10 text-primary text-xs px-2 py-0.5 rounded">
              {ACTIVITY_TARGET_LABELS[task.target_type as ActivityTargetType]}
            </span>
            <span className="text-gray-700 dark:text-gray-300 ml-2">#{task.target_id}</span>
          </div>
        )}

        {/* Повторение */}
        {task.recurrence_rule && (
          <div>
            <div className="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1.5">Повторение</div>
            <RecurrenceSelector
              rule={currentRecurrence}
              until={task.recurrence_until ?? null}
              onChange={updateRecurrence}
            />
          </div>
        )}

        {/* Часть группы */}
        {task.parent_activity_id && (
          <div>
            <div className="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1.5">Часть группы</div>
            <Link
              href={`/tasks/${task.parent_activity_id}`}
              className="text-primary hover:underline flex items-center gap-1"
            >
              <i className="bi bi-arrow-up-right-circle text-sm" />
              Родительская задача #{task.parent_activity_id}
            </Link>
          </div>
        )}

        <hr className="border-gray-100 dark:border-gray-700" />

        {/* Теги */}
        <div>
          <div className="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1.5">Теги</div>
          <div className="flex flex-wrap gap-1.5 mb-2">
            {(task.tags ?? []).map((tag) => (
              <span
                key={tag}
                className="flex items-center gap-1 bg-primary/10 text-primary text-xs rounded-full px-2.5 py-0.5"
              >
                {tag}
                <button onClick={() => removeTag(tag)} className="ml-0.5">
                  <i className="bi bi-x text-[10px]" />
                </button>
              </span>
            ))}
          </div>
          <div className="flex gap-2">
            <input
              className="input flex-1 text-xs"
              placeholder="Добавить тег..."
              value={tagInput}
              onChange={(e) => setTagInput(e.target.value)}
              onKeyDown={(e) => { if (e.key === "Enter") addTag(); }}
            />
            <button onClick={addTag} className="btn-secondary text-xs py-1">
              Добавить
            </button>
          </div>
        </div>

        <hr className="border-gray-100 dark:border-gray-700" />

        {/* Быстрые действия */}
        <div className="space-y-1">
          <div className="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Действия</div>
          <button
            onClick={togglePin}
            className="btn-ghost text-sm w-full justify-start"
          >
            <i className={`bi ${task.is_pinned ? "bi-pin-fill" : "bi-pin"} mr-2`} />
            {task.is_pinned ? "Открепить" : "Закрепить"}
          </button>
          <button
            onClick={toggleFavorite}
            className="btn-ghost text-sm w-full justify-start"
          >
            <i className={`bi ${task.is_favorite ? "bi-star-fill text-warning" : "bi-star"} mr-2`} />
            {task.is_favorite ? "Убрать из избранного" : "В избранное"}
          </button>

          <div className="pt-2">
            <div className="text-xs text-gray-400 mb-1.5">Перенести дедлайн</div>
            <div className="flex gap-1.5">
              <button onClick={() => extendDeadline(1)} className="btn-secondary text-xs py-1">+1 день</button>
              <button onClick={() => extendDeadline(3)} className="btn-secondary text-xs py-1">+3 дня</button>
              <button onClick={() => extendDeadline(7)} className="btn-secondary text-xs py-1">+1 неделя</button>
            </div>
          </div>

          {task.status !== "rejected" && !task.is_closed && (
            <button
              onClick={() => setRejectOpen(true)}
              className="btn-ghost text-sm w-full justify-start text-danger"
            >
              <i className="bi bi-x-circle mr-2" />
              Отклонить
            </button>
          )}
        </div>
      </div>

      {/* Reject modal */}
      <Modal
        open={rejectOpen}
        title="Отклонить задачу"
        onClose={() => setRejectOpen(false)}
        footer={
          <>
            <button className="btn-ghost" onClick={() => setRejectOpen(false)}>Отмена</button>
            <button
              className="btn-primary text-danger"
              disabled={!rejectReason.trim() || submitting}
              onClick={rejectTask}
            >
              {submitting ? "Отклоняем..." : "Отклонить"}
            </button>
          </>
        }
      >
        <div>
          <label className="label">Причина отклонения *</label>
          <textarea
            className="input w-full min-h-[80px]"
            placeholder="Укажи причину..."
            value={rejectReason}
            onChange={(e) => setRejectReason(e.target.value)}
          />
        </div>
      </Modal>
    </div>
  );
}
