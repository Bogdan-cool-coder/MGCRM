"use client";

import { useState } from "react";
import useSWR, { mutate as globalMutate } from "swr";
import Link from "next/link";
import { fetcher, api } from "@/lib/api";
import { useMe } from "@/lib/auth";
import { ChannelPreferencesMatrix } from "@/components/Notifications/ChannelPreferencesMatrix";
import { QuietHoursToggle } from "@/components/Notifications/QuietHoursToggle";
import type {
  NotificationPreferencesResponse,
  NotificationChannelPreference,
  QuietHours,
} from "@/lib/types";

const PREFS_KEY = "/api/me/notifications/preferences";
const QH_KEY = "/api/me/notifications/quiet-hours";

function maskEmail(email: string): string {
  const [local, domain] = email.split("@");
  if (!domain) return email;
  const prefix = local.slice(0, 3);
  return `${prefix}***@${domain}`;
}

export function NotificationsPanel() {
  const { user } = useMe();

  const { data: prefsData, isLoading: prefsLoading, error: prefsError } =
    useSWR<NotificationPreferencesResponse>(PREFS_KEY, fetcher);

  const { data: quietHoursData, isLoading: qhLoading } =
    useSWR<QuietHours>(QH_KEY, fetcher);

  const [localPrefs, setLocalPrefs] = useState<NotificationChannelPreference[] | null>(null);
  const [saving, setSaving] = useState(false);
  const [saveSuccess, setSaveSuccess] = useState(false);

  const [testLoading, setTestLoading] = useState(false);
  const [testResult, setTestResult] = useState<"success" | "error" | null>(null);

  const [quietHoursLocal, setQuietHoursLocal] = useState<QuietHours | null>(null);

  const serverPrefs = prefsData?.preferences ?? [];
  const displayedPrefs = localPrefs ?? serverPrefs;

  const quietHours: QuietHours = quietHoursLocal ??
    quietHoursData ?? {
      start: "08:00",
      end: "23:00",
      enabled: false,
      email_enabled: false,
      notification_phone: null,
    };

  if (localPrefs === null && serverPrefs.length > 0) {
    setLocalPrefs([...serverPrefs]);
  }
  if (quietHoursLocal === null && quietHoursData) {
    setQuietHoursLocal(quietHoursData);
  }

  async function handleSave() {
    if (!localPrefs) return;
    setSaving(true);
    try {
      await api("/me/notifications/preferences", {
        method: "PATCH",
        body: { preferences: localPrefs },
      });
      await globalMutate(PREFS_KEY);
      setSaveSuccess(true);
      setTimeout(() => setSaveSuccess(false), 2000);
    } catch {
      // no toast yet
    } finally {
      setSaving(false);
    }
  }

  async function handleSendTest() {
    setTestLoading(true);
    setTestResult(null);
    try {
      await api("/me/notifications/test", { method: "POST" });
      setTestResult("success");
    } catch {
      setTestResult("error");
    } finally {
      setTestLoading(false);
    }
  }

  const hasTg = !!user?.telegram_user_id;
  const hasEmail = !!user?.email;
  const isLoading = prefsLoading || qhLoading;

  return (
    <div className="p-6 max-w-4xl space-y-5">
      {/* Секция: Каналы доставки */}
      <div className="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-elev-1 p-6 space-y-4">
        <h2 className="text-base font-semibold text-gray-800 dark:text-gray-100">
          Каналы доставки
        </h2>

        <div className="flex items-center justify-between py-2">
          <div className="flex items-center gap-3">
            <i className="bi-bell text-xl text-info" />
            <span className="text-sm font-medium text-gray-700 dark:text-gray-300">
              В приложении
            </span>
          </div>
          <span className="badge bg-success/10 text-success text-xs">Всегда активен</span>
        </div>

        <div className="flex items-center justify-between py-2">
          <div className="flex items-center gap-3">
            <i className="bi-telegram text-xl text-info" />
            <span className="text-sm font-medium text-gray-700 dark:text-gray-300">
              Telegram
            </span>
          </div>
          {hasTg && user?.telegram_user_id ? (
            <span className="badge bg-success/10 text-success text-xs">
              ID: {user.telegram_user_id}
            </span>
          ) : (
            <Link
              href="/profile?tab=profile"
              className="btn-secondary text-sm py-1 px-3"
            >
              Подключить Telegram
            </Link>
          )}
        </div>

        <div className="flex items-center justify-between py-2">
          <div className="flex items-center gap-3">
            <i className="bi-envelope text-xl text-info" />
            <span className="text-sm font-medium text-gray-700 dark:text-gray-300">
              Email
            </span>
          </div>
          {hasEmail && user?.email ? (
            <span className="badge bg-success/10 text-success text-xs">
              {maskEmail(user.email)}
            </span>
          ) : (
            <Link
              href="/profile?tab=profile"
              className="text-sm text-gray-400 hover:text-primary dark:hover:text-primary-light"
            >
              Добавь email в профиле
            </Link>
          )}
        </div>

        <div className="border-t border-gray-100 dark:border-gray-700 my-2" />

        {!qhLoading && (
          <QuietHoursToggle
            quietHours={quietHours}
            onUpdate={(patch) =>
              setQuietHoursLocal((prev) => ({ ...quietHours, ...prev, ...patch }))
            }
          />
        )}
      </div>

      {/* Секция: Матрица настроек */}
      <div className="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-elev-1 p-6 space-y-4">
        <h2 className="text-base font-semibold text-gray-800 dark:text-gray-100">
          Матрица настроек
        </h2>

        {isLoading && (
          <div className="space-y-3">
            <div className="animate-pulse h-24 bg-gray-100 dark:bg-gray-700 rounded" />
            <div className="animate-pulse h-80 bg-gray-100 dark:bg-gray-700 rounded" />
          </div>
        )}

        {prefsError && !isLoading && (
          <p className="text-danger text-sm">
            Не удалось загрузить настройки. Обнови страницу.
          </p>
        )}

        {!isLoading && !prefsError && (
          <ChannelPreferencesMatrix
            preferences={displayedPrefs}
            onChange={setLocalPrefs}
            hasTg={hasTg}
            hasEmail={hasEmail}
          />
        )}

        <div className="flex items-center justify-end gap-3 pt-2 border-t border-gray-100 dark:border-gray-700">
          {saveSuccess && (
            <span className="text-success text-sm">Сохранено</span>
          )}
          <button
            type="button"
            className="btn-primary"
            disabled={saving || isLoading}
            onClick={() => void handleSave()}
          >
            {saving ? "Сохраняем…" : "Сохранить настройки"}
          </button>
        </div>
      </div>

      {/* Секция: Тестовое уведомление */}
      <div className="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-elev-1 p-4">
        <div className="flex items-center gap-4">
          <i className="bi-check2-circle text-xl text-success" />
          <span className="flex-1 text-sm text-gray-700 dark:text-gray-300">
            Проверь, что каналы настроены верно
          </span>
          <div className="flex items-center gap-3">
            {testResult === "success" && (
              <span className="text-success text-sm">
                Тестовое отправлено во включённые каналы
              </span>
            )}
            {testResult === "error" && (
              <span className="text-danger text-sm">
                Не удалось отправить. Проверь настройки каналов.
              </span>
            )}
            <button
              type="button"
              className="btn-secondary"
              disabled={testLoading}
              onClick={() => void handleSendTest()}
            >
              {testLoading ? (
                <>
                  <i className="bi-arrow-clockwise animate-spin mr-1" />
                  Отправляем…
                </>
              ) : (
                "Отправить тестовое"
              )}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
