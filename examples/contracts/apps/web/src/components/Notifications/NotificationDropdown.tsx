"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import useSWR from "swr";
import { fetcher, errorMessage } from "@/lib/api";
import { useNotifications } from "@/hooks/useNotifications";
import { NotificationItem } from "./NotificationItem";
import type { Notification, NotificationListOut } from "@/lib/types";

interface Props {
  onClose: () => void;
}

interface GroupedNotifications {
  today: Notification[];
  yesterday: Notification[];
  earlier: Notification[];
}

function groupByDate(notifications: Notification[]): GroupedNotifications {
  const now = new Date();
  const todayStart = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  const yesterdayStart = new Date(todayStart);
  yesterdayStart.setDate(yesterdayStart.getDate() - 1);

  const today: Notification[] = [];
  const yesterday: Notification[] = [];
  const earlier: Notification[] = [];

  for (const n of notifications) {
    const d = new Date(n.created_at);
    if (d >= todayStart) {
      today.push(n);
    } else if (d >= yesterdayStart) {
      yesterday.push(n);
    } else {
      earlier.push(n);
    }
  }

  return { today, yesterday, earlier };
}

function DateSeparator({ label }: { label: string }) {
  return (
    <div className="px-4 py-1 text-xs font-medium text-gray-400 dark:text-gray-500 bg-gray-50 dark:bg-gray-800/50 sticky top-0 border-b border-gray-100 dark:border-gray-700/50">
      {label}
    </div>
  );
}

export function NotificationDropdown({ onClose }: Props) {
  const ref = useRef<HTMLDivElement>(null);
  const [markingAll, setMarkingAll] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);

  const { notifications, isLoading, unreadCount, markRead, markAllRead } = useNotifications();

  // Refresh list on open
  const { mutate } = useSWR<NotificationListOut>("/api/notifications?limit=20", fetcher);
  useEffect(() => {
    void mutate();
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  // Close on click outside
  useEffect(() => {
    function handleMouseDown(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) {
        onClose();
      }
    }
    document.addEventListener("mousedown", handleMouseDown);
    return () => document.removeEventListener("mousedown", handleMouseDown);
  }, [onClose]);

  // Close on Escape
  useEffect(() => {
    function handleKey(e: KeyboardEvent) {
      if (e.key === "Escape") onClose();
    }
    document.addEventListener("keydown", handleKey);
    return () => document.removeEventListener("keydown", handleKey);
  }, [onClose]);

  async function handleMarkAllRead() {
    setMarkingAll(true);
    setActionError(null);
    try {
      await markAllRead();
    } catch (err: unknown) {
      setActionError(errorMessage(err, "Не удалось отметить прочитанными"));
    } finally {
      setMarkingAll(false);
    }
  }

  const grouped = groupByDate(notifications);
  const hasAny = notifications.length > 0;

  return (
    <div
      ref={ref}
      className="absolute right-0 top-full mt-2 w-[380px] z-50 card shadow-lg overflow-hidden"
    >
      {/* Header */}
      <div className="flex items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-gray-700">
        <div className="flex items-center gap-2">
          <span className="font-semibold text-primary dark:text-gray-100">Уведомления</span>
          {unreadCount > 0 && (
            <span className="bg-danger text-white text-xs rounded-full px-1.5 py-0.5 font-bold">
              {unreadCount}
            </span>
          )}
        </div>
        <div className="flex items-center gap-1">
          <button
            type="button"
            className="btn-ghost text-sm py-1 px-2"
            onClick={() => void handleMarkAllRead()}
            disabled={markingAll}
          >
            {markingAll ? (
              <>
                <i className="bi-arrow-clockwise animate-spin mr-1" />
                Читаем…
              </>
            ) : (
              "Прочитать все"
            )}
          </button>
          <button
            type="button"
            className="btn-ghost p-1"
            onClick={onClose}
            aria-label="Закрыть"
          >
            <i className="bi-x text-lg" />
          </button>
        </div>
      </div>

      {actionError && (
        <div className="px-4 py-2 text-xs text-danger bg-danger/10 border-b border-danger/20">
          {actionError}
        </div>
      )}

      {/* List */}
      <div className="max-h-[420px] overflow-y-auto">
        {isLoading && (
          <div className="space-y-0">
            {[1, 2, 3].map((i) => (
              <div
                key={i}
                className="animate-pulse h-16 bg-gray-100 dark:bg-gray-700 mx-4 my-2 rounded"
              />
            ))}
          </div>
        )}

        {!isLoading && !hasAny && (
          <div className="flex flex-col items-center justify-center py-10 gap-2">
            <i className="bi-bell-slash text-3xl text-gray-300 dark:text-gray-600" />
            <span className="text-sm text-gray-500 dark:text-gray-400">
              {unreadCount === 0 ? "Всё прочитано" : "Уведомлений пока нет"}
            </span>
          </div>
        )}

        {!isLoading && hasAny && (
          <>
            {grouped.today.length > 0 && (
              <>
                <DateSeparator label="Сегодня" />
                {grouped.today.map((n) => (
                  <NotificationItem
                    key={n.id}
                    item={n}
                    onMarkRead={markRead}
                    onClose={onClose}
                  />
                ))}
              </>
            )}
            {grouped.yesterday.length > 0 && (
              <>
                <DateSeparator label="Вчера" />
                {grouped.yesterday.map((n) => (
                  <NotificationItem
                    key={n.id}
                    item={n}
                    onMarkRead={markRead}
                    onClose={onClose}
                  />
                ))}
              </>
            )}
            {grouped.earlier.length > 0 && (
              <>
                <DateSeparator label="Раньше" />
                {grouped.earlier.map((n) => (
                  <NotificationItem
                    key={n.id}
                    item={n}
                    onMarkRead={markRead}
                    onClose={onClose}
                  />
                ))}
              </>
            )}
          </>
        )}
      </div>

      {/* Footer */}
      <div className="px-4 py-3 border-t border-gray-200 dark:border-gray-700">
        <Link
          href="/notifications"
          className="text-sm text-primary dark:text-primary-light hover:underline"
          onClick={onClose}
        >
          Все уведомления <i className="bi-arrow-right" />
        </Link>
      </div>
    </div>
  );
}
