"use client";

import type { FinActStatus } from "@/lib/types";

const STATUS_META: Record<FinActStatus, { label: string; classes: string }> = {
  draft: {
    label: "Черновик",
    classes: "bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400",
  },
  issued: {
    label: "Выставлен",
    classes: "bg-blue-50 text-blue-700 dark:bg-blue-900/20 dark:text-blue-400",
  },
  signed: {
    label: "Подписан",
    classes: "bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-400",
  },
  cancelled: {
    label: "Отменён",
    classes: "bg-gray-100 text-gray-400 dark:bg-gray-700 dark:text-gray-500",
  },
};

interface Props {
  status: FinActStatus;
}

export function ActStatusBadge({ status }: Props) {
  const meta = STATUS_META[status] ?? {
    label: status,
    classes: "bg-gray-100 text-gray-600",
  };
  return (
    <span
      className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium ${meta.classes}`}
    >
      <span className="w-1.5 h-1.5 rounded-full bg-current opacity-70" />
      {meta.label}
    </span>
  );
}
