"use client";

import type { FinInvoiceStatus } from "@/lib/types";

const STATUS_META: Record<FinInvoiceStatus, { label: string; classes: string }> = {
  draft: {
    label: "Черновик",
    classes: "bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400",
  },
  issued: {
    label: "Выставлен",
    classes: "bg-blue-50 text-blue-700 dark:bg-blue-900/20 dark:text-blue-400",
  },
  partially_paid: {
    label: "Частично оплачен",
    classes: "bg-yellow-50 text-yellow-700 dark:bg-yellow-900/20 dark:text-yellow-400",
  },
  paid: {
    label: "Оплачен",
    classes: "bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-400",
  },
  cancelled: {
    label: "Отменён",
    classes: "bg-gray-100 text-gray-400 dark:bg-gray-700 dark:text-gray-500",
  },
};

interface Props {
  status: FinInvoiceStatus;
}

export function InvoiceStatusBadge({ status }: Props) {
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
