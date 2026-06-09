"use client";

import { useState } from "react";
import { useUnreadCount } from "@/hooks/useNotifications";
import { NotificationDropdown } from "./NotificationDropdown";

export function NotificationBell() {
  const [open, setOpen] = useState(false);
  const unreadCount = useUnreadCount();

  const label =
    unreadCount > 0
      ? `Уведомления, ${unreadCount} непрочитанных`
      : "Уведомления";

  return (
    <div className="relative">
      <button
        type="button"
        className="relative p-2 rounded-md hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-600 dark:text-gray-400 transition-colors"
        aria-label={label}
        onClick={() => setOpen((v) => !v)}
      >
        <i className="bi bi-bell text-lg" />
        {unreadCount > 0 && (
          <span
            className="absolute -top-0.5 -right-0.5 bg-danger text-white text-[10px]
                       font-bold rounded-full min-w-[18px] h-[18px] flex items-center
                       justify-center px-1 pointer-events-none"
          >
            {unreadCount > 99 ? "99+" : unreadCount}
          </span>
        )}
      </button>

      {open && <NotificationDropdown onClose={() => setOpen(false)} />}
    </div>
  );
}
