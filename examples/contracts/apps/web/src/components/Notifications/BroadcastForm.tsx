"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { api, errorMessage } from "@/lib/api";
import { RecipientSelector } from "./RecipientSelector";
import type { RecipientMode } from "./RecipientSelector";

interface FormState {
  title: string;
  body: string;
  link: string;
  recipientMode: RecipientMode;
  role: string;
  departmentId: number | null;
  userIds: number[];
  channels: { in_app: boolean; tg: boolean; email: boolean };
}

interface FormErrors {
  title?: string;
  body?: string;
  link?: string;
  channels?: string;
  recipients?: string;
  api?: string;
}

function validate(form: FormState): FormErrors {
  const errors: FormErrors = {};

  if (!form.title.trim() || form.title.trim().length < 3) {
    errors.title = "Введи заголовок (минимум 3 символа)";
  }
  if (!form.body.trim() || form.body.trim().length < 10) {
    errors.body = "Введи текст сообщения (минимум 10 символов)";
  }
  if (form.link && !form.link.startsWith("/")) {
    errors.link = "Ссылка должна быть внутренним путём и начинаться с «/» (например /deals/42)";
  }
  if (!form.channels.in_app && !form.channels.tg && !form.channels.email) {
    errors.channels = "Выбери хотя бы один канал";
  }
  if (form.recipientMode === "role" && !form.role) {
    errors.recipients = "Выбери роль";
  }
  if (form.recipientMode === "department" && !form.departmentId) {
    errors.recipients = "Выбери отдел";
  }
  if (form.recipientMode === "users" && form.userIds.length === 0) {
    errors.recipients = "Выбери хотя бы одного получателя";
  }

  return errors;
}

export function BroadcastForm() {
  const router = useRouter();

  const [form, setForm] = useState<FormState>({
    title: "",
    body: "",
    link: "",
    recipientMode: "all",
    role: "",
    departmentId: null,
    userIds: [],
    channels: { in_app: true, tg: true, email: false },
  });

  const [errors, setErrors] = useState<FormErrors>({});
  const [submitting, setSubmitting] = useState(false);

  function setField<K extends keyof FormState>(key: K, value: FormState[K]) {
    setForm((prev) => ({ ...prev, [key]: value }));
    if (key in errors) {
      setErrors((prev) => ({ ...prev, [key]: undefined }));
    }
  }

  // Канонический recipient_filter (см. normalize_recipient_filter на бэке).
  // Для «всем» отправляем ЯВНО {type:"all"} — пустой фильтр на бэке = 422.
  function buildRecipientFilter(): Record<string, unknown> {
    switch (form.recipientMode) {
      case "all":
        return { type: "all" };
      case "role":
        return { role: form.role };
      case "department":
        return { department_id: form.departmentId };
      case "users":
        return { user_ids: form.userIds };
    }
  }

  function buildChannels(): string[] {
    const out: string[] = [];
    if (form.channels.in_app) out.push("in_app");
    if (form.channels.tg) out.push("tg");
    if (form.channels.email) out.push("email");
    return out;
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    const validationErrors = validate(form);
    if (Object.keys(validationErrors).length > 0) {
      setErrors(validationErrors);
      return;
    }

    setSubmitting(true);
    setErrors({});

    try {
      await api("/admin/notifications/broadcast", {
        method: "POST",
        body: {
          title: form.title,
          body: form.body,
          link: form.link || null,
          recipient_filter: buildRecipientFilter(),
          channels: buildChannels(),
        },
      });
      router.push("/admin/notifications/broadcasts");
    } catch (err: unknown) {
      setErrors({ api: errorMessage(err, "Не удалось создать рассылку") });
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={(e) => void handleSubmit(e)} className="p-8 max-w-3xl space-y-6">

      {/* Сообщение */}
      <div className="card p-6 space-y-4">
        <h2 className="text-base font-semibold text-gray-800 dark:text-gray-100">Сообщение</h2>

        <div>
          <label className="label">Заголовок *</label>
          <input
            type="text"
            className="input w-full"
            placeholder="Например: «Обновление системы в пятницу»"
            value={form.title}
            onChange={(e) => setField("title", e.target.value)}
          />
          {errors.title && <p className="text-danger text-xs mt-1">{errors.title}</p>}
        </div>

        <div>
          <label className="label">Текст *</label>
          <textarea
            rows={5}
            className="input w-full resize-none"
            placeholder="Напиши что хочешь донести до команды"
            value={form.body}
            onChange={(e) => setField("body", e.target.value)}
          />
          {errors.body && <p className="text-danger text-xs mt-1">{errors.body}</p>}
        </div>

        <div>
          <label className="label">Ссылка при клике</label>
          <input
            type="text"
            className="input w-full"
            placeholder="/deals/42 или /tasks"
            value={form.link}
            onChange={(e) => setField("link", e.target.value)}
          />
          <p className="text-xs text-gray-400 mt-1">
            Только внутренний путь, начиная с «/». Внешние ссылки не поддерживаются.
          </p>
          {errors.link && <p className="text-danger text-xs mt-1">{errors.link}</p>}
        </div>
      </div>

      {/* Получатели */}
      <div className="card p-6 space-y-4">
        <h2 className="text-base font-semibold text-gray-800 dark:text-gray-100">Получатели</h2>
        <RecipientSelector
          mode={form.recipientMode}
          role={form.role}
          departmentId={form.departmentId}
          userIds={form.userIds}
          onModeChange={(v) => setField("recipientMode", v)}
          onRoleChange={(v) => setField("role", v)}
          onDepartmentChange={(v) => setField("departmentId", v)}
          onUserIdsChange={(v) => setField("userIds", v)}
          error={errors.recipients}
        />
      </div>

      {/* Каналы */}
      <div className="card p-6 space-y-3">
        <h2 className="text-base font-semibold text-gray-800 dark:text-gray-100">
          Каналы доставки
        </h2>
        <div className="space-y-2">
          <label className="flex items-center gap-2 cursor-pointer text-sm text-gray-700 dark:text-gray-300">
            <input
              type="checkbox"
              className="accent-primary"
              checked={form.channels.in_app}
              onChange={(e) =>
                setField("channels", { ...form.channels, in_app: e.target.checked })
              }
            />
            В приложении
          </label>
          <label className="flex items-center gap-2 cursor-pointer text-sm text-gray-700 dark:text-gray-300">
            <input
              type="checkbox"
              className="accent-primary"
              checked={form.channels.tg}
              onChange={(e) =>
                setField("channels", { ...form.channels, tg: e.target.checked })
              }
            />
            Telegram
          </label>
          <label className="flex items-center gap-2 cursor-pointer text-sm text-gray-700 dark:text-gray-300">
            <input
              type="checkbox"
              className="accent-primary"
              checked={form.channels.email}
              onChange={(e) =>
                setField("channels", { ...form.channels, email: e.target.checked })
              }
            />
            Email
          </label>
        </div>
        {errors.channels && <p className="text-danger text-xs mt-1">{errors.channels}</p>}
      </div>

      {/* Время отправки */}
      <div className="card p-6 space-y-3">
        <h2 className="text-base font-semibold text-gray-800 dark:text-gray-100">
          Время отправки
        </h2>
        <div className="space-y-2">
          <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
            <input type="radio" name="schedule_mode" className="accent-primary" checked readOnly />
            Отправить сейчас
          </label>
          <label className="flex items-center gap-2 text-sm text-gray-400 dark:text-gray-500 cursor-not-allowed">
            <input type="radio" name="schedule_mode" className="accent-primary" disabled />
            Запланировать на
            <span className="badge bg-gray-100 dark:bg-gray-700 text-gray-500 text-xs">Скоро</span>
          </label>
          <p className="text-xs text-gray-400 ml-6">
            Отложенная отправка пока не поддерживается — рассылка уйдёт сразу.
          </p>
        </div>
      </div>

      {/* Actions */}
      {errors.api && (
        <p className="text-danger text-sm">{errors.api}</p>
      )}
      <div className="flex items-center justify-end gap-3">
        <button
          type="button"
          className="btn-ghost"
          onClick={() => router.push("/admin/notifications/broadcasts")}
        >
          Отмена
        </button>
        <button
          type="submit"
          className="btn-primary"
          disabled={submitting}
        >
          {submitting ? "Отправляем…" : "Отправить рассылку"}
        </button>
      </div>
    </form>
  );
}
