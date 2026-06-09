"use client";

import type { EmploymentStatus } from "@/lib/types";

const STATUS_CLASSES: Record<EmploymentStatus, string> = {
  active: "bg-success/10 text-success",
  on_vacation: "bg-warning/10 text-warning",
  dismissed: "bg-danger/10 text-danger",
};

const STATUS_LABELS: Record<EmploymentStatus, string> = {
  active: "Активен",
  on_vacation: "В отпуске",
  dismissed: "Уволен",
};

interface Props {
  status: EmploymentStatus;
}

export function EmployeeStatusBadge({ status }: Props) {
  return (
    <span className={`badge ${STATUS_CLASSES[status]} text-xs font-medium`}>
      {STATUS_LABELS[status]}
    </span>
  );
}
