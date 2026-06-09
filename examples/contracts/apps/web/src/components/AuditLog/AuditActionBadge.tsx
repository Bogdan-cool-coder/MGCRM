"use client";

import type { AuditAction } from "@/lib/types";

interface AuditActionBadgeProps {
  action: AuditAction;
}

const ACTION_STYLES: Record<AuditAction, string> = {
  create: "bg-success/10 text-success",
  update: "bg-info/20 text-primary",
  delete: "bg-danger/10 text-danger",
  merge: "bg-purple-50 text-purple-700",
  extra_fields_change: "bg-gray-100 text-gray-600",
  bulk_action: "bg-yellow-50 text-yellow-700",
};

const ACTION_LABELS: Record<AuditAction, string> = {
  create: "создание",
  update: "изменение",
  delete: "удаление",
  merge: "объединение",
  extra_fields_change: "доп. поля",
  bulk_action: "массовое",
};

export function AuditActionBadge({ action }: AuditActionBadgeProps) {
  const style = ACTION_STYLES[action] ?? "bg-gray-100 text-gray-600";
  const label = ACTION_LABELS[action] ?? action;
  return (
    <span className={`text-xs font-semibold px-2 py-0.5 rounded-full ${style}`}>
      {label}
    </span>
  );
}
