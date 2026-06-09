"use client";

import Link from "next/link";
import { useMe } from "@/lib/auth";
import { Avatar } from "./Avatar";
import { ThemeToggle } from "./ThemeToggle";
import { NotificationBell } from "./Notifications/NotificationBell";

export function Header() {
  const { user } = useMe();

  return (
    <header className="sticky top-0 h-14 z-30 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-6 flex items-center justify-end">
      <div className="flex items-center gap-1">
        <NotificationBell />
        <ThemeToggle />
        {user && (
          <Link
            href="/profile"
            className="flex items-center p-1 rounded-md hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors ml-1"
            title="Перейти в профиль"
          >
            <Avatar
              userId={user.id}
              name={user.full_name}
              hasAvatar={!!user.avatar_path}
              size={32}
            />
          </Link>
        )}
      </div>
    </header>
  );
}
