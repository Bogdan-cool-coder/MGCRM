"use client";

import { useEffect, useRef, useState } from "react";
import { Avatar } from "@/components/Avatar";
import { api, ApiError } from "@/lib/api";
import { useMe } from "@/lib/auth";
import { RoleLabels } from "@/lib/types";
import { useToast } from "@/components/ui/Toast";

export function ProfilePanel() {
  const { user, mutate } = useMe();
  const { toast } = useToast();
  const fileInputRef = useRef<HTMLInputElement | null>(null);

  const [fullName, setFullName] = useState("");
  const [email, setEmail] = useState("");
  const [jobTitle, setJobTitle] = useState("");
  const [phone, setPhone] = useState("");

  const [savingProfile, setSavingProfile] = useState(false);
  const [uploadingAvatar, setUploadingAvatar] = useState(false);
  const [linkingTg, setLinkingTg] = useState(false);
  const [cacheBust, setCacheBust] = useState(Date.now());

  useEffect(() => {
    if (user) {
      setFullName(user.full_name);
      setEmail(user.email);
      setJobTitle(user.job_title ?? "");
      setPhone(user.phone ?? "");
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [user?.id]);

  if (!user) {
    return <div className="p-8 text-gray-500 dark:text-gray-400 animate-pulse">Загрузка…</div>;
  }

  async function saveProfile(e: React.FormEvent) {
    e.preventDefault();
    setSavingProfile(true);
    try {
      await api("/users/me", {
        method: "PATCH",
        body: { full_name: fullName, email, job_title: jobTitle || null, phone: phone || null },
      });
      await mutate();
      toast.success("Профиль сохранён");
    } catch (err) {
      const detail = err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Ошибка";
      toast.error(detail);
    } finally {
      setSavingProfile(false);
    }
  }

  async function uploadAvatar(file: File) {
    if (!["image/jpeg", "image/png", "image/webp"].includes(file.type)) {
      toast.error("Допустимы JPG, PNG, WEBP");
      return;
    }
    if (file.size > 2 * 1024 * 1024) {
      toast.error("Файл больше 2 МБ");
      return;
    }
    setUploadingAvatar(true);
    try {
      const form = new FormData();
      form.append("file", file);
      const res = await fetch("/api/users/me/avatar", {
        method: "POST",
        body: form,
        credentials: "same-origin",
      });
      if (!res.ok) {
        const detail = await res.text();
        try {
          toast.error(String(JSON.parse(detail).detail ?? detail));
        } catch {
          toast.error(detail);
        }
        return;
      }
      await mutate();
      setCacheBust(Date.now());
      toast.success("Аватар загружен");
    } finally {
      setUploadingAvatar(false);
    }
  }

  async function deleteAvatar() {
    setUploadingAvatar(true);
    try {
      await api("/users/me/avatar", { method: "DELETE" });
      await mutate();
      setCacheBust(Date.now());
      toast.success("Аватар удалён");
    } catch (err) {
      const detail = err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Ошибка";
      toast.error(detail);
    } finally {
      setUploadingAvatar(false);
    }
  }

  async function linkTelegram() {
    setLinkingTg(true);
    try {
      const res = await api<{ url: string; expires_in_minutes: number }>(
        "/users/me/telegram-link", { method: "POST" },
      );
      window.open(res.url, "_blank", "noopener");
      toast.info("Откройте Telegram и нажмите «Start» в боте. Ссылка действует 10 минут.");
      setTimeout(() => void mutate(), 5000);
      setTimeout(() => void mutate(), 15000);
    } catch (err) {
      const detail = err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Ошибка";
      toast.error(detail);
    } finally {
      setLinkingTg(false);
    }
  }

  async function unlinkTelegram() {
    setLinkingTg(true);
    try {
      await api("/users/me/telegram", { method: "DELETE" });
      await mutate();
      toast.success("Telegram отвязан");
    } catch (err) {
      const detail = err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Ошибка";
      toast.error(detail);
    } finally {
      setLinkingTg(false);
    }
  }

  return (
    <div className="p-6 grid grid-cols-1 xl:grid-cols-3 gap-5 max-w-6xl">

      {/* ── Основная форма ─────────────────────────────────────────────── */}
      <div className="xl:col-span-2">
        <form
          onSubmit={(e) => void saveProfile(e)}
          className="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-elev-1 p-6 space-y-5"
        >
          <h2 className="text-base font-semibold text-gray-900 dark:text-gray-100">Личные данные</h2>

          <div className="grid sm:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">ФИО</label>
              <input
                className="w-full rounded-xl border border-gray-300 dark:border-white/10 bg-white dark:bg-gray-900/50 px-3.5 py-2.5 text-sm outline-none transition-[border-color,box-shadow] duration-150 text-gray-900 dark:text-gray-100 focus:border-primary-light focus:ring-4 focus:ring-primary-light/15"
                value={fullName}
                onChange={(e) => setFullName(e.target.value)}
                required
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Email</label>
              <input
                type="email"
                className="w-full rounded-xl border border-gray-300 dark:border-white/10 bg-white dark:bg-gray-900/50 px-3.5 py-2.5 text-sm outline-none transition-[border-color,box-shadow] duration-150 text-gray-900 dark:text-gray-100 focus:border-primary-light focus:ring-4 focus:ring-primary-light/15"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Должность</label>
              <input
                className="w-full rounded-xl border border-gray-300 dark:border-white/10 bg-white dark:bg-gray-900/50 px-3.5 py-2.5 text-sm outline-none transition-[border-color,box-shadow] duration-150 text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500 focus:border-primary-light focus:ring-4 focus:ring-primary-light/15"
                placeholder="Менеджер по продажам"
                value={jobTitle}
                onChange={(e) => setJobTitle(e.target.value)}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Телефон</label>
              <input
                type="tel"
                className="w-full rounded-xl border border-gray-300 dark:border-white/10 bg-white dark:bg-gray-900/50 px-3.5 py-2.5 text-sm outline-none transition-[border-color,box-shadow] duration-150 text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500 focus:border-primary-light focus:ring-4 focus:ring-primary-light/15"
                placeholder="+7 999 000-00-00"
                value={phone}
                onChange={(e) => setPhone(e.target.value)}
              />
            </div>
          </div>

          <div className="flex justify-end pt-1">
            <button type="submit" disabled={savingProfile} className="btn-primary">
              {savingProfile ? "Сохранение…" : "Сохранить"}
            </button>
          </div>
        </form>
      </div>

      {/* ── Правая колонка ─────────────────────────────────────────────── */}
      <div className="space-y-5">

        {/* Аватар */}
        <div className="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-elev-1 p-6">
          <h2 className="text-base font-semibold text-gray-900 dark:text-gray-100 mb-4">Фото профиля</h2>
          <div className="flex flex-col items-center gap-4">
            <div className="relative group">
              <Avatar
                userId={user.id}
                name={user.full_name}
                hasAvatar={!!user.avatar_path}
                size={96}
                cacheBust={cacheBust}
              />
              {uploadingAvatar && (
                <div className="absolute inset-0 rounded-full bg-black/30 flex items-center justify-center">
                  <i className="bi bi-arrow-clockwise animate-spin text-white text-lg" aria-hidden="true" />
                </div>
              )}
            </div>
            <div className="flex gap-2">
              <button
                type="button"
                className="btn-secondary text-sm"
                onClick={() => fileInputRef.current?.click()}
                disabled={uploadingAvatar}
              >
                <i className="bi bi-upload mr-1" aria-hidden="true" />
                {user.avatar_path ? "Заменить" : "Загрузить"}
              </button>
              {user.avatar_path && (
                <button
                  type="button"
                  onClick={() => void deleteAvatar()}
                  disabled={uploadingAvatar}
                  className="btn-ghost text-sm text-danger"
                >
                  <i className="bi bi-trash" aria-hidden="true" />
                </button>
              )}
            </div>
            <input
              ref={fileInputRef}
              type="file"
              accept="image/jpeg,image/png,image/webp"
              className="hidden"
              onChange={(e) => {
                const f = e.target.files?.[0];
                if (f) void uploadAvatar(f);
                e.currentTarget.value = "";
              }}
            />
            <p className="text-xs text-gray-400 dark:text-gray-500 text-center">JPG, PNG или WEBP · до 2 МБ</p>
          </div>
        </div>

        {/* Telegram */}
        <div className="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-elev-1 p-6 text-sm">
          <h2 className="text-base font-semibold text-gray-900 dark:text-gray-100 mb-4">Telegram</h2>
          <div className="flex items-center justify-between mb-4 py-2.5 px-3.5 rounded-xl bg-gray-50 dark:bg-gray-700/50 border border-gray-100 dark:border-gray-700">
            <div className="flex items-center gap-2">
              <i className="bi bi-telegram text-info text-base" aria-hidden="true" />
              <span className="text-gray-700 dark:text-gray-300">Статус</span>
            </div>
            {user.telegram_user_id ? (
              <span className="badge badge-success text-xs">Привязан</span>
            ) : (
              <span className="badge badge-neutral text-xs">Не привязан</span>
            )}
          </div>
          {!user.telegram_user_id ? (
            <>
              <button
                type="button"
                onClick={() => void linkTelegram()}
                disabled={linkingTg}
                className="btn-primary w-full justify-center text-sm"
              >
                <i className="bi bi-telegram mr-1.5" aria-hidden="true" />
                {linkingTg ? "Открываю Telegram…" : "Привязать Telegram"}
              </button>
              <p className="text-xs text-gray-400 dark:text-gray-500 mt-2 text-center">
                @Contract_generator_MACRO_bot · ссылка действует 10 мин.
              </p>
            </>
          ) : (
            <button
              type="button"
              onClick={() => void unlinkTelegram()}
              disabled={linkingTg}
              className="btn-ghost text-sm text-danger w-full justify-center"
            >
              <i className="bi bi-x-circle mr-1.5" aria-hidden="true" />
              Отвязать Telegram
            </button>
          )}
        </div>
      </div>
    </div>
  );
}
