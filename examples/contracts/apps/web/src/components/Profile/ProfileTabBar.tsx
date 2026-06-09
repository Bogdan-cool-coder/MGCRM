"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";

interface Tab {
  href: string;
  label: string;
  icon: string;
}

const TABS: Tab[] = [
  { href: "/profile", label: "Личное", icon: "bi-person-circle" },
  { href: "/profile/signature", label: "Подпись", icon: "bi-pen" },
  { href: "/profile/theme", label: "Тема", icon: "bi-palette" },
  { href: "/profile/locale", label: "Локализация", icon: "bi-translate" },
  { href: "/profile/security", label: "Безопасность", icon: "bi-shield-lock" },
  { href: "/profile/notifications", label: "Уведомления", icon: "bi-bell" },
  { href: "/profile/calendar", label: "Google Calendar", icon: "bi-calendar-event" },
];

export function ProfileTabBar() {
  const pathname = usePathname();

  return (
    <div className="border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-8">
      <nav className="flex overflow-x-auto whitespace-nowrap -mb-px gap-1">
        {TABS.map((tab) => {
          // Exact match for /profile, startsWith for sub-pages
          const active =
            tab.href === "/profile"
              ? pathname === "/profile"
              : pathname.startsWith(tab.href);
          return (
            <Link
              key={tab.href}
              href={tab.href}
              className={
                "inline-flex items-center gap-1.5 px-3 py-3.5 text-sm border-b-2 transition-colors whitespace-nowrap " +
                (active
                  ? "border-primary text-primary dark:text-primary-light dark:border-primary-light font-medium"
                  : "border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:border-gray-300 dark:hover:border-gray-600")
              }
            >
              <i className={`bi ${tab.icon} text-sm`} />
              {tab.label}
            </Link>
          );
        })}
      </nav>
    </div>
  );
}
