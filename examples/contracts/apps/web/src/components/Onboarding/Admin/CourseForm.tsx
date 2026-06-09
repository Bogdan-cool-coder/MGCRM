"use client";

import { useState } from "react";
import useSWR from "swr";
import type { Course, TeamProgressUser, UserRole } from "@/lib/types";
import { api, ApiError, fetcher } from "@/lib/api";
import { RoleLabels } from "@/lib/types";
import { UserSelect } from "@/components/UserSelect";
import { Modal } from "@/components/Modal";
import { useToast } from "@/components/ui/Toast";

const TARGET_ROLE_OPTIONS: { value: UserRole; label: string }[] = [
  { value: "manager",  label: "Менеджер" },
  { value: "lawyer",   label: "Юрист" },
  { value: "director", label: "Директор" },
  { value: "admin",    label: "Администратор" },
];

interface CourseFormData {
  title: string;
  description: string;
  target_roles: UserRole[];
  is_mandatory: boolean;
  deadline_days: string;
  passing_score_pct: number;
  cover_image_url: string;
}

interface AssignedUser {
  user_id: number;
  user_name: string;
  status: string;
}

interface Props {
  course?: Course | null;
  onSaved: (id: number) => void;
  onCancel: () => void;
}

export function CourseForm({ course, onSaved, onCancel }: Props) {
  const isEdit = !!course;
  const { toast } = useToast();

  const [form, setForm] = useState<CourseFormData>({
    title: course?.title ?? "",
    description: course?.description ?? "",
    target_roles: (course?.target_roles ?? []) as UserRole[],
    is_mandatory: course?.is_mandatory ?? false,
    deadline_days: course?.deadline_days != null ? String(course.deadline_days) : "5",
    passing_score_pct: course?.passing_score_pct ?? 80,
    cover_image_url: course?.cover_image_url ?? "",
  });

  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [publishing, setPublishing] = useState(false);
  const [assignConfirmOpen, setAssignConfirmOpen] = useState(false);
  const [assignUserId, setAssignUserId] = useState("");
  const [assignDeadlineDays, setAssignDeadlineDays] = useState("5");
  const [assignSubmitting, setAssignSubmitting] = useState(false);

  // Назначенные пользователи (только в edit-режиме)
  const { data: progressData } = useSWR<TeamProgressUser[]>(
    isEdit ? `/admin/onboarding/progress?course_id=${course!.id}` : null,
    fetcher
  );

  function toggleRole(role: UserRole) {
    setForm((f) => ({
      ...f,
      target_roles: f.target_roles.includes(role)
        ? f.target_roles.filter((r) => r !== role)
        : [...f.target_roles, role],
    }));
  }

  function buildBody() {
    return {
      title: form.title.trim(),
      description: form.description.trim() || null,
      target_roles: form.target_roles,
      is_mandatory: form.is_mandatory,
      deadline_days: form.deadline_days ? Number(form.deadline_days) : null,
      passing_score_pct: form.passing_score_pct,
      cover_image_url: form.cover_image_url.trim() || null,
    };
  }

  async function handleSave() {
    if (!form.title.trim()) {
      setError("Введите название курса");
      return;
    }
    setSaving(true);
    setError(null);
    try {
      if (isEdit) {
        await api(`/admin/onboarding/courses/${course!.id}`, { method: "PATCH", body: buildBody() });
        toast.success("Настройки курса сохранены");
        onSaved(course!.id);
      } else {
        const res = await api<{ id: number }>("/admin/onboarding/courses", { method: "POST", body: buildBody() });
        toast.success("Курс создан");
        onSaved(res.id);
      }
    } catch (e) {
      setError(e instanceof ApiError ? String((e.detail as { detail?: string })?.detail ?? e.message) : "Ошибка сохранения");
    } finally {
      setSaving(false);
    }
  }

  async function handlePublish(publish: boolean) {
    if (!course) return;
    setPublishing(true);
    try {
      await api(`/admin/onboarding/courses/${course.id}/${publish ? "publish" : "unpublish"}`, { method: "POST" });
      toast.success(publish ? "Курс опубликован" : "Курс снят с публикации");
      onSaved(course.id);
    } catch (e) {
      setError(e instanceof ApiError ? String((e.detail as { detail?: string })?.detail ?? e.message) : "Ошибка");
    } finally {
      setPublishing(false);
    }
  }

  async function handleAutoAssign() {
    if (!course) return;
    setAssignConfirmOpen(false);
    try {
      await api(`/admin/onboarding/courses/${course.id}/auto-assign-existing`, { method: "POST" });
      onSaved(course.id);
    } catch (e) {
      setError(e instanceof ApiError ? String((e.detail as { detail?: string })?.detail ?? e.message) : "Ошибка");
    }
  }

  async function handleAssignUser() {
    if (!course || !assignUserId) return;
    setAssignSubmitting(true);
    try {
      await api("/admin/onboarding/assign", {
        method: "POST",
        body: {
          user_ids: [Number(assignUserId)],
          course_id: course.id,
          is_mandatory: form.is_mandatory,
          deadline_days: assignDeadlineDays ? Number(assignDeadlineDays) : null,
        },
      });
      setAssignUserId("");
      toast.success("Курс назначен пользователю");
      onSaved(course.id);
    } catch (e) {
      setError(e instanceof ApiError ? String((e.detail as { detail?: string })?.detail ?? e.message) : "Ошибка");
    } finally {
      setAssignSubmitting(false);
    }
  }

  // Для матрицы прогресса — собираем список назначенных
  const assignedUsers: AssignedUser[] = progressData
    ? progressData
        .filter((u) => u.courses.some((c) => c.course_id === course?.id && c.assignment_id !== null))
        .map((u) => {
          const courseRow = u.courses.find((c) => c.course_id === course?.id);
          return {
            user_id: u.user_id,
            user_name: u.user_name,
            status: courseRow?.status ?? "not_started",
          };
        })
    : [];

  const STATUS_LABELS: Record<string, string> = {
    not_started: "Не начат",
    in_progress: "В процессе",
    completed: "Завершён",
    overdue: "Просрочен",
    unassigned: "Не назначен",
  };

  const STATUS_COLORS: Record<string, string> = {
    not_started: "text-gray-500",
    in_progress: "text-info",
    completed: "text-success",
    overdue: "text-danger",
  };

  return (
    <div className={`flex gap-6 ${isEdit ? "items-start" : ""}`}>
      {/* Main form */}
      <div className="flex-1 space-y-4">
        {error && (
          <div className="text-danger text-sm bg-danger/10 px-3 py-2 rounded">{error}</div>
        )}

        {/* Title */}
        <div>
          <label className="label">Название курса *</label>
          <input
            className="input"
            value={form.title}
            onChange={(e) => setForm((f) => ({ ...f, title: e.target.value }))}
            placeholder="Онбординг: MacroSales для менеджера"
          />
        </div>

        {/* Description */}
        <div>
          <label className="label">Описание (опц.)</label>
          <textarea
            className="input resize-y"
            rows={3}
            value={form.description}
            onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))}
            placeholder="Краткое описание курса и его целей"
          />
        </div>

        {/* Target roles */}
        <div>
          <label className="label">Для кого назначается</label>
          <div className="flex flex-wrap gap-2">
            {TARGET_ROLE_OPTIONS.map((opt) => {
              const active = form.target_roles.includes(opt.value);
              return (
                <button
                  key={opt.value}
                  type="button"
                  className={`border rounded-full px-3 py-1 text-sm cursor-pointer transition-colors ${
                    active
                      ? "bg-primary text-white border-primary"
                      : "bg-white text-gray-700 border-gray-300 hover:border-primary"
                  }`}
                  onClick={() => toggleRole(opt.value)}
                >
                  {opt.label}
                </button>
              );
            })}
          </div>
        </div>

        {/* Mandatory */}
        <div>
          <label className="label">Обязательность</label>
          <div className="space-y-2">
            <label className="flex items-center gap-2 text-sm cursor-pointer">
              <input
                type="radio"
                name="mandatory"
                checked={!form.is_mandatory}
                onChange={() => setForm((f) => ({ ...f, is_mandatory: false }))}
              />
              Информационный — можно пропустить, нет ограничений
            </label>
            <label className="flex items-center gap-2 text-sm cursor-pointer">
              <input
                type="radio"
                name="mandatory"
                checked={form.is_mandatory}
                onChange={() => setForm((f) => ({ ...f, is_mandatory: true }))}
              />
              Обязательный — bulk-операции заблокированы при просрочке
            </label>
          </div>
        </div>

        {/* Deadline */}
        <div>
          <label className="label">Дедлайн прохождения</label>
          <div className="flex items-center gap-2">
            <input
              type="number"
              className="input w-24"
              min={0}
              value={form.deadline_days}
              onChange={(e) => setForm((f) => ({ ...f, deadline_days: e.target.value }))}
            />
            <span className="text-sm text-gray-500">рабочих дней</span>
          </div>
        </div>

        {/* Passing score */}
        <div>
          <label className="label">
            Минимальный балл для сдачи квиза: <strong>{form.passing_score_pct}%</strong>
          </label>
          <input
            type="range"
            min={50}
            max={100}
            step={5}
            value={form.passing_score_pct}
            onChange={(e) => setForm((f) => ({ ...f, passing_score_pct: Number(e.target.value) }))}
            className="w-full max-w-xs"
          />
          <div className="flex justify-between text-xs text-gray-400 max-w-xs">
            <span>50%</span>
            <span>100%</span>
          </div>
        </div>

        {/* Cover */}
        <div>
          <label className="label">Обложка (URL, опц.)</label>
          <input
            className="input"
            value={form.cover_image_url}
            onChange={(e) => setForm((f) => ({ ...f, cover_image_url: e.target.value }))}
            placeholder="https://example.com/cover.png"
          />
        </div>

        {/* Footer actions */}
        <div className="flex items-center gap-2 pt-2">
          <button type="button" className="btn-ghost" onClick={onCancel}>
            Отмена
          </button>
          <button
            type="button"
            className="btn-secondary"
            onClick={handleSave}
            disabled={saving}
          >
            {saving ? "Сохранение…" : "Сохранить черновик"}
          </button>
          {isEdit && (
            <>
              {course!.is_published ? (
                <button
                  type="button"
                  className="btn-secondary text-danger"
                  onClick={() => handlePublish(false)}
                  disabled={publishing}
                >
                  {publishing ? "…" : "Снять с публикации"}
                </button>
              ) : (
                <button
                  type="button"
                  className="btn-primary"
                  onClick={() => handlePublish(true)}
                  disabled={publishing}
                >
                  {publishing ? "…" : "Опубликовать"}
                </button>
              )}
            </>
          )}
          {!isEdit && (
            <button
              type="button"
              className="btn-primary"
              onClick={handleSave}
              disabled={saving}
            >
              {saving ? "Сохранение…" : "Сохранить"}
            </button>
          )}
        </div>
      </div>

      {/* Right rail (edit only) */}
      {isEdit && (
        <div className="w-72 shrink-0 space-y-4 sticky top-6">
          {/* Status badge */}
          <div className="card p-4">
            <div className="flex items-center gap-2 mb-3">
              <span
                className={`badge px-2 py-1 rounded text-sm font-medium ${
                  course!.is_published
                    ? "bg-success/10 text-success"
                    : "bg-warning/10 text-warning"
                }`}
              >
                {course!.is_published ? "Опубликован" : "Черновик"}
              </span>
            </div>

            {/* Assign user */}
            <div className="border-t border-gray-100 pt-3">
              <p className="text-xs font-semibold text-gray-500 mb-2">Назначить пользователю</p>
              <UserSelect
                value={assignUserId}
                onChange={setAssignUserId}
                placeholder="— выберите —"
              />
              <div className="flex items-center gap-2 mt-2">
                <input
                  type="number"
                  className="input w-16 text-sm"
                  min={0}
                  value={assignDeadlineDays}
                  onChange={(e) => setAssignDeadlineDays(e.target.value)}
                  title="Дней до дедлайна"
                />
                <span className="text-xs text-gray-400">дн.</span>
                <button
                  type="button"
                  className="btn-primary text-xs"
                  disabled={!assignUserId || assignSubmitting}
                  onClick={handleAssignUser}
                >
                  {assignSubmitting ? "…" : "Назначить"}
                </button>
              </div>
            </div>

            {/* Auto-assign */}
            <button
              type="button"
              className="btn-secondary text-xs w-full mt-3"
              onClick={() => setAssignConfirmOpen(true)}
            >
              Назначить всем подходящим сотрудникам
            </button>
          </div>

          {/* Assigned users list */}
          {assignedUsers.length > 0 && (
            <div className="card p-4">
              <p className="text-xs font-semibold text-gray-500 mb-2">Назначено ({assignedUsers.length})</p>
              <div className="space-y-1.5 max-h-48 overflow-y-auto">
                {assignedUsers.map((u) => (
                  <div key={u.user_id} className="flex items-center justify-between text-sm">
                    <span className="truncate">{u.user_name}</span>
                    <span className={`text-xs ${STATUS_COLORS[u.status] ?? "text-gray-500"}`}>
                      {STATUS_LABELS[u.status] ?? u.status}
                    </span>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>
      )}

      {/* Auto-assign confirm modal */}
      <Modal
        open={assignConfirmOpen}
        title="Назначить всем подходящим"
        onClose={() => setAssignConfirmOpen(false)}
        width="sm"
        footer={
          <>
            <button className="btn-ghost" onClick={() => setAssignConfirmOpen(false)}>Отмена</button>
            <button className="btn-primary" onClick={handleAutoAssign}>Назначить</button>
          </>
        }
      >
        <p className="text-sm text-gray-700">
          Курс будет назначен всем активным сотрудникам с ролями:{" "}
          <strong>
            {form.target_roles.map((r) => RoleLabels[r]).join(", ") || "не выбраны"}
          </strong>.
          {" "}Уже назначенным повторно не назначится.
        </p>
      </Modal>
    </div>
  );
}
