"use client";

import type { FinRegistryApprovalStatus, FinRegistryPaymentStatus } from "@/lib/types";

const APPROVAL_META: Record<FinRegistryApprovalStatus, { label: string; classes: string }> = {
  draft:     { label: "Черновик",        classes: "bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400" },
  on_review: { label: "На согласовании", classes: "bg-yellow-50 text-yellow-700 dark:bg-yellow-900/20 dark:text-yellow-400" },
  approved:  { label: "Одобрено",        classes: "bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-400" },
  rejected:  { label: "Отклонено",       classes: "bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-400" },
};

const PAYMENT_META: Record<FinRegistryPaymentStatus, { label: string; classes: string }> = {
  new:     { label: "Не оплачен",  classes: "bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400" },
  partial: { label: "Частично",    classes: "bg-yellow-50 text-yellow-700 dark:bg-yellow-900/20 dark:text-yellow-400" },
  paid:    { label: "Оплачен",     classes: "bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-400" },
};

export function RegistryApprovalBadge({ status }: { status: FinRegistryApprovalStatus }) {
  const meta = APPROVAL_META[status] ?? { label: status, classes: "bg-gray-100 text-gray-600" };
  return (
    <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium ${meta.classes}`}>
      <span className="w-1.5 h-1.5 rounded-full bg-current opacity-70" />
      {meta.label}
    </span>
  );
}

export function RegistryPaymentBadge({ status }: { status: FinRegistryPaymentStatus }) {
  const meta = PAYMENT_META[status] ?? { label: status, classes: "bg-gray-100 text-gray-600" };
  return (
    <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium ${meta.classes}`}>
      <span className="w-1.5 h-1.5 rounded-full bg-current opacity-70" />
      {meta.label}
    </span>
  );
}
