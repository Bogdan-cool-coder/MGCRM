"use client";

import type { VacationStatus, VacationType } from "@/lib/types";

const VACATION_STATUS_CLASSES: Record<VacationStatus, string> = {
  pending_approval: "bg-warning/10 text-warning",
  approved: "bg-success/10 text-success",
  rejected: "bg-danger/10 text-danger",
};

const VACATION_STATUS_LABELS: Record<VacationStatus, string> = {
  pending_approval: "Ожидает одобрения",
  approved: "Одобрен",
  rejected: "Отклонён",
};

const VACATION_TYPE_CLASSES: Record<VacationType, string> = {
  vacation: "bg-info/10 text-info",
  sick_leave: "bg-warning/10 text-warning",
  day_off: "bg-gray-100 text-gray-600",
  business_trip: "bg-primary/10 text-primary",
};

const VACATION_TYPE_LABELS: Record<VacationType, string> = {
  vacation: "Отпуск",
  sick_leave: "Больничный",
  day_off: "Отгул",
  business_trip: "Командировка",
};

interface StatusProps {
  status: VacationStatus;
}

interface TypeProps {
  type: VacationType;
}

export function VacationStatusBadge({ status }: StatusProps) {
  return (
    <span className={`badge ${VACATION_STATUS_CLASSES[status]} text-xs font-medium`}>
      {VACATION_STATUS_LABELS[status]}
    </span>
  );
}

export function VacationTypeBadge({ type }: TypeProps) {
  return (
    <span className={`badge ${VACATION_TYPE_CLASSES[type]} text-xs font-medium`}>
      {VACATION_TYPE_LABELS[type]}
    </span>
  );
}
