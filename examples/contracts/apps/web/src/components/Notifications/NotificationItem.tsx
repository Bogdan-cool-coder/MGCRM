"use client";

import { useRouter } from "next/navigation";
import type { Notification } from "@/lib/types";

function formatRelativeTime(isoString: string): string {
  const now = Date.now();
  const then = new Date(isoString).getTime();
  const diff = now - then;
  const minutes = Math.floor(diff / 60_000);
  const hours = Math.floor(diff / 3_600_000);

  if (minutes < 1) return "только что";
  if (minutes < 60) return `${minutes} мин. назад`;
  if (hours < 24) return `${hours} ч. назад`;

  const d = new Date(isoString);
  const yesterday = new Date();
  yesterday.setDate(yesterday.getDate() - 1);

  const hh = String(d.getHours()).padStart(2, "0");
  const mm = String(d.getMinutes()).padStart(2, "0");

  if (d.toDateString() === yesterday.toDateString()) {
    return `вчера в ${hh}:${mm}`;
  }

  const day = String(d.getDate()).padStart(2, "0");
  const month = String(d.getMonth() + 1).padStart(2, "0");
  return `${day}.${month} в ${hh}:${mm}`;
}

function kindIcon(kind: string): string {
  switch (kind) {
    case "approval_pending": return "bi-hourglass-split text-warning";
    case "approval_result": return "bi-check-circle-fill text-success";
    case "deal_stage_change": return "bi-kanban text-info";
    case "activity_due_soon": return "bi-clock-fill text-warning";
    case "onboarding_overdue": return "bi-mortarboard-fill text-danger";
    case "webhook_delivery_failed": return "bi-broadcast text-danger";
    default: return "bi-bell-fill text-gray-400";
  }
}

interface Props {
  item: Notification;
  onMarkRead: (id: number) => Promise<void>;
  onClose?: () => void;
}

export function NotificationItem({ item, onMarkRead, onClose }: Props) {
  const router = useRouter();

  async function handleClick() {
    if (!item.is_read) {
      await onMarkRead(item.id);
    }
    if (item.link) {
      onClose?.();
      router.push(item.link);
    }
  }

  return (
    <div
      className={
        "flex gap-3 px-4 py-3 border-b border-gray-100 dark:border-gray-700 " +
        "hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer transition-colors " +
        (!item.is_read ? "bg-info/5 dark:bg-info/10" : "")
      }
      onClick={() => void handleClick()}
    >
      {/* Unread dot */}
      <div className="shrink-0 flex items-start pt-2 w-2">
        {!item.is_read && (
          <div className="w-2 h-2 rounded-full bg-primary dark:bg-primary-light flex-none" />
        )}
      </div>

      {/* Kind icon */}
      <div className="shrink-0 w-8 h-8 rounded-full flex items-center justify-center bg-gray-100 dark:bg-gray-700 text-base mt-0.5">
        <i className={`bi ${kindIcon(item.kind)}`} />
      </div>

      {/* Text */}
      <div className="flex-1 min-w-0">
        <div className="text-sm font-medium text-primary dark:text-gray-100 leading-tight">
          {item.title}
        </div>
        {item.body && (
          <div className="text-xs text-gray-500 dark:text-gray-400 mt-0.5 line-clamp-2">
            {item.body}
          </div>
        )}
        <div className="text-xs text-gray-400 dark:text-gray-500 mt-1">
          {formatRelativeTime(item.created_at)}
        </div>
      </div>
    </div>
  );
}
