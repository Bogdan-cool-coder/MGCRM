"use client";

import { useSearchParams, useRouter } from "next/navigation";
import { Suspense } from "react";
import { useMe } from "@/lib/auth";
import { RoleLabels } from "@/lib/types";
import { Avatar } from "@/components/Avatar";
import { BlurFade } from "@/components/magicui/BlurFade";
import { ProfilePanel } from "@/components/ProfileHub/ProfilePanel";
import { NotificationsPanel } from "@/components/ProfileHub/NotificationsPanel";
import { SecurityPanel } from "@/components/ProfileHub/SecurityPanel";
import { CalendarPanel } from "@/components/ProfileHub/CalendarPanel";
import { SignaturePanel } from "@/components/ProfileHub/SignaturePanel";
import { LocalePanel } from "@/components/ProfileHub/LocalePanel";
import { ThemePanel } from "@/components/ProfileHub/ThemePanel";
import { SegmentsPanel } from "@/components/ProfileHub/SegmentsPanel";

type TabId =
  | "profile"
  | "notifications"
  | "security"
  | "calendar"
  | "signature"
  | "locale"
  | "theme"
  | "segments";

interface TabDef {
  id: TabId;
  label: string;
  icon: string;
}

const TABS: TabDef[] = [
  { id: "profile", label: "Профиль", icon: "bi-person-circle" },
  { id: "notifications", label: "Уведомления", icon: "bi-bell" },
  { id: "security", label: "Безопасность", icon: "bi-shield-lock" },
  { id: "calendar", label: "Календарь", icon: "bi-calendar-event" },
  { id: "signature", label: "Подпись", icon: "bi-pen" },
  { id: "locale", label: "Язык", icon: "bi-translate" },
  { id: "theme", label: "Тема", icon: "bi-palette" },
  { id: "segments", label: "Сегменты", icon: "bi-bookmark-star" },
];

function ProfileHubInner() {
  const searchParams = useSearchParams();
  const router = useRouter();
  const { user } = useMe();

  const rawTab = searchParams.get("tab") ?? "profile";
  const activeTab: TabId = TABS.some((t) => t.id === rawTab)
    ? (rawTab as TabId)
    : "profile";

  // Читаем 2fa success query из security-редиректа
  const show2faSuccess = searchParams.get("2fa") === "enabled" && activeTab === "security";

  function setTab(id: TabId) {
    const params = new URLSearchParams(searchParams.toString());
    params.set("tab", id);
    // Очищаем 2fa param при переключении вкладок
    if (id !== "security") params.delete("2fa");
    router.replace(`/profile?${params.toString()}`, { scroll: false });
  }

  return (
    <div className="flex flex-col bg-gray-50 dark:bg-gray-900">

      {/* ── Hero профиля ─────────────────────────────────────────────────── */}
      <BlurFade>
        <div className="mx-6 mt-6 rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-elev-1 px-6 py-5">
          <div className="flex items-center gap-5">
            {/* Avatar */}
            {user ? (
              <Avatar
                userId={user.id}
                name={user.full_name}
                hasAvatar={!!user.avatar_path}
                size={56}
                className="shrink-0"
              />
            ) : (
              <div className="shrink-0 w-14 h-14 rounded-full bg-gray-200 dark:bg-gray-700 animate-pulse" />
            )}

            {/* Info */}
            <div className="flex-1 min-w-0">
              <div className="text-[10px] font-medium text-gray-400 dark:text-gray-500 uppercase tracking-widest mb-0.5">
                Профиль
              </div>
              {user ? (
                <>
                  <h1 className="text-lg font-semibold text-gray-900 dark:text-white truncate leading-tight">
                    {user.full_name}
                  </h1>
                  <div className="flex flex-wrap items-center gap-2 mt-1">
                    <span className="badge badge-info text-xs">{RoleLabels[user.role]}</span>
                    {user.job_title && (
                      <span className="text-sm text-gray-500 dark:text-gray-400">{user.job_title}</span>
                    )}
                    {user.email && (
                      <span className="text-sm text-gray-400 dark:text-gray-500 hidden sm:inline">{user.email}</span>
                    )}
                  </div>
                </>
              ) : (
                <div className="animate-pulse space-y-1.5">
                  <div className="h-5 bg-gray-200 dark:bg-gray-700 rounded w-48" />
                  <div className="h-4 bg-gray-100 dark:bg-gray-700/60 rounded w-32" />
                </div>
              )}
            </div>

            {/* Quick actions */}
            <div className="flex items-center gap-2 shrink-0">
              <button
                type="button"
                className="btn-secondary text-sm"
                onClick={() => setTab("profile")}
                title="Редактировать профиль"
              >
                <i className="bi bi-pencil mr-1" aria-hidden="true" />
                Редактировать
              </button>
            </div>
          </div>
        </div>
      </BlurFade>

      {/* ── Tab bar ───────────────────────────────────────────────────────── */}
      <div className="border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-6 mt-4">
        <nav className="flex overflow-x-auto whitespace-nowrap -mb-px gap-1">
          {TABS.map((tab) => {
            const active = tab.id === activeTab;
            return (
              <button
                key={tab.id}
                onClick={() => setTab(tab.id)}
                className={
                  "inline-flex items-center gap-1.5 px-3 py-3.5 text-sm border-b-2 transition-colors whitespace-nowrap " +
                  (active
                    ? "border-primary text-primary dark:text-primary-light dark:border-primary-light font-medium"
                    : "border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:border-gray-300 dark:hover:border-gray-600")
                }
              >
                <i className={`bi ${tab.icon} text-sm`} aria-hidden="true" />
                {tab.label}
              </button>
            );
          })}
        </nav>
      </div>

      {/* ── Panel content ─────────────────────────────────────────────────── */}
      {activeTab === "profile" && <ProfilePanel />}
      {activeTab === "notifications" && <NotificationsPanel />}
      {activeTab === "security" && <SecurityPanel show2faSuccess={show2faSuccess} />}
      {activeTab === "calendar" && <CalendarPanel />}
      {activeTab === "signature" && <SignaturePanel />}
      {activeTab === "locale" && <LocalePanel />}
      {activeTab === "theme" && <ThemePanel />}
      {activeTab === "segments" && <SegmentsPanel />}
    </div>
  );
}

export default function ProfilePage() {
  return (
    <Suspense fallback={<div className="p-8 text-gray-400 animate-pulse">Загрузка…</div>}>
      <ProfileHubInner />
    </Suspense>
  );
}
