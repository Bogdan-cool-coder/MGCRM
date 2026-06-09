"use client";

import useSWR, { mutate as globalMutate } from "swr";
import { api, fetcher } from "@/lib/api";
import type { Notification, NotificationListOut } from "@/lib/types";

const COUNT_KEY = "/api/notifications/count";
const LIST_KEY = "/api/notifications?limit=20";

interface UnreadCountResponse {
  unread_count: number;
}

export function useNotifications() {
  const {
    data: listData,
    isLoading,
    mutate: mutateList,
  } = useSWR<NotificationListOut>(LIST_KEY, fetcher, {
    refreshInterval: 60_000,
    revalidateOnFocus: false,
  });

  const { data: countData, mutate: mutateCount } = useSWR<UnreadCountResponse>(
    COUNT_KEY,
    fetcher,
    {
      refreshInterval: 30_000,
      revalidateOnFocus: true,
    },
  );

  const notifications: Notification[] = listData?.items ?? [];
  const unreadCount = countData?.unread_count ?? listData?.unread_count ?? 0;

  async function markRead(id: number): Promise<void> {
    await api(`/notifications/${id}/read`, { method: "PATCH" });
    await Promise.all([mutateList(), mutateCount()]);
  }

  async function markAllRead(): Promise<void> {
    await api("/notifications/mark-all-read", { method: "POST" });
    // Optimistic: immediately set unread_count to 0 in global SWR cache
    await globalMutate(COUNT_KEY, { unread_count: 0 }, false);
    await Promise.all([mutateList(), mutateCount()]);
  }

  async function deleteNotification(id: number): Promise<void> {
    await api(`/notifications/${id}`, { method: "DELETE" });
    await Promise.all([mutateList(), mutateCount()]);
  }

  async function bulkMarkRead(ids: number[]): Promise<void> {
    if (ids.length === 0) return;
    await api("/notifications/bulk-read", { method: "POST", body: { ids } });
    await Promise.all([mutateList(), mutateCount()]);
  }

  return {
    notifications,
    unreadCount,
    isLoading,
    markRead,
    markAllRead,
    deleteNotification,
    bulkMarkRead,
    mutateList,
  };
}

/** Standalone unread count hook — used by NotificationBell (polling) */
export function useUnreadCount(): number {
  const { data } = useSWR<UnreadCountResponse>(COUNT_KEY, fetcher, {
    refreshInterval: 30_000,
    revalidateOnFocus: true,
  });
  return data?.unread_count ?? 0;
}
