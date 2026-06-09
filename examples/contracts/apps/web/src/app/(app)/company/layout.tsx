"use client";

import { useMe } from "@/lib/auth";

// Роли из Sidebar для всех пунктов company/* (employees, rights-transfers, schedules, absences)
const COMPANY_LAYOUT_ROLES = ["admin", "director"] as const;

function CompanyNoAccess() {
  return (
    <div className="p-8">
      <div className="card flex flex-col items-center justify-center py-16 text-center">
        <i className="bi bi-shield-lock text-5xl text-gray-300 dark:text-gray-600 mb-4" />
        <p className="text-base font-semibold text-gray-700 dark:text-gray-300 mb-1">
          Доступ ограничен
        </p>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Раздел управления персоналом доступен только администраторам и руководителям.
        </p>
      </div>
    </div>
  );
}

export default function CompanyLayout({ children }: { children: React.ReactNode }) {
  const { user, isLoading } = useMe();

  // Нейтральный лоадер — не раскрываем контент до подтверждения роли
  if (isLoading || !user) {
    return (
      <div className="flex items-center justify-center min-h-[60vh]">
        <div className="flex flex-col items-center gap-3">
          <div className="w-8 h-8 rounded-full bg-gray-200 dark:bg-gray-700 animate-pulse" />
          <div className="h-3 w-28 bg-gray-200 dark:bg-gray-700 rounded animate-pulse" />
        </div>
      </div>
    );
  }

  if (!(COMPANY_LAYOUT_ROLES as readonly string[]).includes(user.role)) {
    return <CompanyNoAccess />;
  }

  return <>{children}</>;
}
