"use client";

import { useEffect, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import useSWR, { mutate as globalMutate } from "swr";
import { fetcher, api, ApiError } from "@/lib/api";
import type { GoogleCalendarSettings } from "@/lib/types";

const GC_KEY = "/api/me/google-calendar";

function formatRelative(isoString: string | null): string {
  if (!isoString) return "никогда";
  const date = new Date(isoString);
  const now = new Date();
  const diffMs = now.getTime() - date.getTime();
  const diffMin = Math.floor(diffMs / 60000);
  if (diffMin < 1) return "только что";
  if (diffMin < 60) return `${diffMin} мин. назад`;
  const diffH = Math.floor(diffMin / 60);
  if (diffH < 24) return `${diffH} ч. назад`;
  const diffD = Math.floor(diffH / 24);
  if (diffD < 7) return `${diffD} д. назад`;
  return date.toLocaleDateString("ru-RU");
}

function Banner({ msg }: { msg: { kind: "ok" | "err"; text: string } }) {
  const cls =
    msg.kind === "ok"
      ? "bg-success/10 text-success border border-success/20"
      : "bg-danger/10 text-danger border border-danger/20";
  return (
    <div className={`text-sm rounded-xl px-4 py-2.5 flex items-center gap-2 ${cls}`}>
      <i className={`bi ${msg.kind === "ok" ? "bi-check-circle" : "bi-exclamation-circle"} shrink-0`} aria-hidden="true" />
      {msg.text}
    </div>
  );
}

interface ToggleRowProps {
  label: string;
  helpText?: string;
  checked: boolean;
  disabled?: boolean;
  onChange: (val: boolean) => void;
}

function ToggleRow({ label, helpText, checked, disabled, onChange }: ToggleRowProps) {
  return (
    <div className="flex items-start justify-between gap-4 py-3 border-b border-gray-100 dark:border-gray-700 last:border-0">
      <div className="flex-1">
        <div className="text-sm font-medium text-gray-800 dark:text-gray-200">{label}</div>
        {helpText && (
          <div className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{helpText}</div>
        )}
      </div>
      <button
        type="button"
        role="switch"
        aria-checked={checked}
        disabled={disabled}
        onClick={() => onChange(!checked)}
        className={
          "relative inline-flex h-5 w-9 shrink-0 cursor-pointer rounded-full border-2 border-transparent " +
          "transition-colors duration-200 focus:outline-none disabled:opacity-40 " +
          (checked ? "bg-primary" : "bg-gray-300 dark:bg-gray-600")
        }
      >
        <span
          className={
            "pointer-events-none inline-block h-4 w-4 rounded-full bg-white shadow " +
            "transform transition duration-200 " +
            (checked ? "translate-x-4" : "translate-x-0")
          }
        />
      </button>
    </div>
  );
}

export function CalendarPanel() {
  const router = useRouter();
  const searchParams = useSearchParams();

  const { data, isLoading, error } = useSWR<GoogleCalendarSettings>(GC_KEY, fetcher);

  const [syncEnabled, setSyncEnabled] = useState(false);
  const [syncMeeting, setSyncMeeting] = useState(true);
  const [syncCall, setSyncCall] = useState(true);
  const [syncOnlyWithTime, setSyncOnlyWithTime] = useState(true);
  const [calendarId, setCalendarId] = useState("primary");
  const [advancedOpen, setAdvancedOpen] = useState(false);

  const [msg, setMsg] = useState<{ kind: "ok" | "err"; text: string } | null>(null);
  const [connecting, setConnecting] = useState(false);
  const [disconnecting, setDisconnecting] = useState(false);
  const [syncing, setSyncing] = useState(false);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (data) {
      setSyncEnabled(data.sync_enabled);
      setSyncMeeting(data.sync_meeting);
      setSyncCall(data.sync_call);
      setSyncOnlyWithTime(data.sync_only_with_time);
      setCalendarId(data.calendar_id ?? "primary");
    }
  }, [data]);

  useEffect(() => {
    if (searchParams.get("connected") === "1") {
      setMsg({ kind: "ok", text: "Google Calendar успешно подключён" });
      void globalMutate(GC_KEY);
      const url = new URL(window.location.href);
      url.searchParams.delete("connected");
      router.replace(url.pathname + url.search, { scroll: false });
    }
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function handleConnect() {
    setConnecting(true);
    setMsg(null);
    try {
      const returnUrl = `${window.location.origin}/profile?tab=calendar&connected=1`;
      const res = await api<{ authorize_url: string }>(
        `/me/google-calendar/connect?return_url=${encodeURIComponent(returnUrl)}`,
        { method: "GET" },
      );
      window.location.href = res.authorize_url;
    } catch (err) {
      const detail = err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Ошибка при подключении";
      setMsg({ kind: "err", text: detail });
      setConnecting(false);
    }
  }

  async function handleDisconnect() {
    if (!confirm("Отключить Google Calendar? Привязанные события останутся в календаре.")) return;
    setDisconnecting(true);
    setMsg(null);
    try {
      await api("/me/google-calendar", { method: "DELETE" });
      await globalMutate(GC_KEY);
      setMsg({ kind: "ok", text: "Google Calendar отключён" });
    } catch (err) {
      const detail = err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Ошибка при отключении";
      setMsg({ kind: "err", text: detail });
    } finally {
      setDisconnecting(false);
    }
  }

  async function handleSyncNow() {
    setSyncing(true);
    setMsg(null);
    try {
      await api("/me/google-calendar/sync-now", { method: "POST" });
      await globalMutate(GC_KEY);
      setMsg({ kind: "ok", text: "Синхронизация запущена" });
    } catch (err) {
      const detail = err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Ошибка синхронизации";
      setMsg({ kind: "err", text: detail });
    } finally {
      setSyncing(false);
    }
  }

  async function handleSaveSettings() {
    setSaving(true);
    setMsg(null);
    try {
      await api("/me/google-calendar/settings", {
        method: "PATCH",
        body: {
          sync_enabled: syncEnabled,
          sync_meeting: syncMeeting,
          sync_call: syncCall,
          sync_only_with_time: syncOnlyWithTime,
          calendar_id: calendarId || "primary",
        },
      });
      await globalMutate(GC_KEY);
      setMsg({ kind: "ok", text: "Настройки сохранены" });
    } catch (err) {
      const detail = err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Ошибка сохранения";
      setMsg({ kind: "err", text: detail });
    } finally {
      setSaving(false);
    }
  }

  if (isLoading) {
    return (
      <div className="p-6 max-w-3xl space-y-4">
        <div className="animate-pulse h-36 bg-gray-100 dark:bg-gray-700 rounded-2xl" />
        <div className="animate-pulse h-24 bg-gray-100 dark:bg-gray-700 rounded-2xl" />
      </div>
    );
  }

  if (error || !data) {
    return (
      <div className="p-8 text-danger text-sm">Не удалось загрузить настройки. Обнови страницу.</div>
    );
  }

  if (!data.configured) {
    return (
      <div className="p-6 max-w-3xl">
        <div className="text-sm text-gray-500 dark:text-gray-400 mb-4">
          Синхронизация встреч и звонков с вашим Google Calendar в обе стороны
        </div>
        <div className="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-elev-1 p-6 space-y-4">
          <div className="flex items-start gap-3">
            <i className="bi-calendar-x text-2xl text-gray-400" />
            <div>
              <div className="font-semibold text-gray-800 dark:text-gray-100">
                Google Calendar не настроен на сервере
              </div>
              <div className="text-sm text-gray-500 dark:text-gray-400 mt-1">
                Свяжитесь с администратором для настройки интеграции с Google Calendar.
              </div>
            </div>
          </div>
          <div>
            <button className="btn-primary" disabled>
              <i className="bi-google mr-1.5" />
              Подключить
            </button>
          </div>
        </div>
      </div>
    );
  }

  if (!data.connected) {
    return (
      <div className="p-6 max-w-3xl space-y-4">
        <div className="text-sm text-gray-500 dark:text-gray-400 mb-2">
          Синхронизация встреч и звонков с вашим Google Calendar в обе стороны
        </div>
        <div className="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-elev-1 p-6 space-y-4">
          <div className="flex items-start gap-3">
            <i className="bi-calendar-event text-2xl text-primary" />
            <div>
              <div className="font-semibold text-gray-800 dark:text-gray-100">
                Подключите ваш Google Calendar
              </div>
              <div className="text-sm text-gray-500 dark:text-gray-400 mt-1">
                Вы будете перенаправлены на Google для авторизации
              </div>
            </div>
          </div>
          <div>
            <button
              className="btn-primary"
              disabled={connecting}
              onClick={() => void handleConnect()}
            >
              <i className="bi-google mr-1.5" />
              {connecting ? "Перенаправляем…" : "Подключить Google Calendar"}
            </button>
          </div>
        </div>
        {msg && <Banner msg={msg} />}
      </div>
    );
  }

  return (
    <div className="p-6 max-w-3xl space-y-6">
      <div className="text-sm text-gray-500 dark:text-gray-400">
        Синхронизация встреч и звонков с вашим Google Calendar в обе стороны
      </div>

      <div className="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-elev-1 p-6 space-y-4">
        <h2 className="text-base font-semibold text-gray-800 dark:text-gray-100">Подключение</h2>
        <div className="space-y-2 text-sm">
          <div className="flex items-center gap-2">
            <i className="bi-check-circle-fill text-success text-base" />
            <span className="text-gray-700 dark:text-gray-300 font-medium">
              Подключено: {data.google_email ?? "—"}
            </span>
          </div>
          <div className="flex items-center gap-2 text-gray-500 dark:text-gray-400">
            <i className="bi-clock text-sm" />
            <span>Последняя синхронизация: {formatRelative(data.last_sync_at)}</span>
          </div>
          <div className="flex items-center gap-2 text-gray-500 dark:text-gray-400">
            <i className="bi-calendar2-check text-sm" />
            <span>Синхронизировано событий: {data.linked_events_count}</span>
          </div>
        </div>
        <div className="flex items-center gap-2 pt-2 border-t border-gray-100 dark:border-gray-700">
          <button
            className="btn-secondary"
            disabled={syncing || disconnecting}
            onClick={() => void handleSyncNow()}
          >
            <i className={`bi-arrow-clockwise mr-1.5 ${syncing ? "animate-spin" : ""}`} />
            {syncing ? "Синхронизация…" : "Синхронизировать сейчас"}
          </button>
          <button
            className="btn-ghost text-danger"
            disabled={disconnecting || syncing}
            onClick={() => void handleDisconnect()}
          >
            <i className="bi-x-circle mr-1.5" />
            {disconnecting ? "Отключаем…" : "Отключить"}
          </button>
        </div>
      </div>

      <div className="card p-6 space-y-2">
        <h2 className="text-base font-semibold text-gray-800 dark:text-gray-100">
          Настройки синхронизации
        </h2>
        <ToggleRow label="Синхронизация включена" checked={syncEnabled} onChange={setSyncEnabled} />
        <ToggleRow label="Синхронизировать встречи (meeting)" checked={syncMeeting} disabled={!syncEnabled} onChange={setSyncMeeting} />
        <ToggleRow label="Синхронизировать звонки (call)" checked={syncCall} disabled={!syncEnabled} onChange={setSyncCall} />
        <ToggleRow
          label="Только с указанным временем"
          helpText="Если выключить — будут синкаться задачи с любым due_at, даже без времени (создаст событие на весь день)"
          checked={syncOnlyWithTime}
          disabled={!syncEnabled}
          onChange={setSyncOnlyWithTime}
        />
        <div className="pt-2">
          <button
            type="button"
            className="flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
            onClick={() => setAdvancedOpen((v) => !v)}
          >
            <i className={`bi-chevron-${advancedOpen ? "up" : "down"} text-xs`} />
            Расширенные настройки
          </button>
          {advancedOpen && (
            <div className="mt-3">
              <label className="label">ID календаря</label>
              <input
                className="input"
                value={calendarId}
                placeholder="primary"
                onChange={(e) => setCalendarId(e.target.value)}
              />
              <div className="text-xs text-gray-500 dark:text-gray-400 mt-1">
                Оставьте «primary» для основного календаря Google-аккаунта
              </div>
            </div>
          )}
        </div>
        <div className="flex items-center justify-end gap-3 pt-3 border-t border-gray-100 dark:border-gray-700">
          <button
            type="button"
            className="btn-primary"
            disabled={saving}
            onClick={() => void handleSaveSettings()}
          >
            {saving ? "Сохранение…" : "Сохранить"}
          </button>
        </div>
      </div>

      {msg && <Banner msg={msg} />}
    </div>
  );
}
