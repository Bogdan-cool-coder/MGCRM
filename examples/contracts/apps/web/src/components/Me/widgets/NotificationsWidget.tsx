"use client";

import Link from "next/link";
import useSWR from "swr";
import { fetcher } from "@/lib/api";
import { EmptyState } from "@/components/EmptyState";
import type { NotificationListOut } from "@/lib/types";

function relativeTime(d: string) {
  const diff = Date.now() - new Date(d).getTime();
  const mins = Math.floor(diff / 60000);
  if (mins < 1) return "только что";
  if (mins < 60) return `${mins} мин назад`;
  const h = Math.floor(mins / 60);
  if (h < 24) return `${h} ч назад`;
  return `${Math.floor(h / 24)} дн назад`;
}

export function NotificationsWidget() {
  const { data, isLoading } = useSWR<NotificationListOut>(
    "/notifications?limit=3&unread_only=false",
    fetcher,
  );

  return (
    <div className="rounded-2xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-1 p-5 flex flex-col">
      <div className="flex items-center justify-between mb-4">
        <span className="text-sm font-semibold text-gray-900 dark:text-white flex items-center gap-2">
          <i className="bi bi-bell text-primary" aria-hidden="true" />
          Уведомления
        </span>
        <Link href="/notifications" className="btn-ghost text-xs">
          Все
          <i className="bi bi-arrow-right ml-1" aria-hidden="true" />
        </Link>
      </div>

      {isLoading && (
        <div className="space-y-2 animate-pulse">
          {[1, 2, 3].map((i) => (
            <div key={i} className="h-14 bg-gray-100 dark:bg-gray-700 rounded-xl" />
          ))}
        </div>
      )}

      {!isLoading && (!data?.items || data.items.length === 0) && (
        <div className="flex-1">
          <EmptyState
            icon="bi-bell"
            title="Новых уведомлений нет"
          />
        </div>
      )}

      {!isLoading && data?.items && data.items.length > 0 && (
        <div className="space-y-1">
          {data.items.map((n, idx) => (
            <div
              key={n.id}
              className={`blur-fade flex items-start gap-3 p-2.5 rounded-xl transition-colors ${
                !n.is_read
                  ? "bg-primary/5 dark:bg-primary/10"
                  : "hover:bg-gray-50 dark:hover:bg-gray-700/30"
              }`}
              style={{ "--blur-fade-duration": `${0.3 + idx * 0.06}s` } as React.CSSProperties}
            >
              {!n.is_read && (
                <span className="w-1.5 h-1.5 rounded-full bg-primary mt-2 shrink-0" aria-hidden="true" />
              )}
              {n.is_read && (
                <span className="w-1.5 h-1.5 shrink-0 mt-2" aria-hidden="true" />
              )}
              <div className="flex-1 min-w-0">
                <p className="text-sm font-medium truncate">{n.title}</p>
                {n.body && <p className="text-xs text-gray-500 truncate">{n.body}</p>}
                <p className="text-[11px] text-gray-400 mt-0.5">{relativeTime(n.created_at)}</p>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
