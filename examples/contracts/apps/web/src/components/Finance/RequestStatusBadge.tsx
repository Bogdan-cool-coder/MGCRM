"use client";

import type { FinRequestStatus } from "@/lib/types";

const STATUS_META: Record<FinRequestStatus, { label: string; classes: string }> = {
  draft:     { label: "Черновик",          classes: "bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400" },
  submitted: { label: "На согласовании",   classes: "bg-yellow-50 text-yellow-700 dark:bg-yellow-900/20 dark:text-yellow-400" },
  approved:  { label: "Одобрено",          classes: "bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-400" },
  rejected:  { label: "Отклонено",         classes: "bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-400" },
  paid:      { label: "Оплачено",          classes: "bg-blue-50 text-blue-700 dark:bg-blue-900/20 dark:text-blue-400" },
  cancelled: { label: "Отменено",          classes: "bg-gray-100 text-gray-400 dark:bg-gray-700 dark:text-gray-500" },
};

interface Props {
  status: FinRequestStatus;
}

export function RequestStatusBadge({ status }: Props) {
  const meta = STATUS_META[status] ?? { label: status, classes: "bg-gray-100 text-gray-600" };
  return (
    <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium ${meta.classes}`}>
      <span className="w-1.5 h-1.5 rounded-full bg-current opacity-70" />
      {meta.label}
    </span>
  );
}
