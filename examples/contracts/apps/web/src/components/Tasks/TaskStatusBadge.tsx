"use client";

import type { ActivityStatus } from "@/lib/types";
import { ACTIVITY_STATUS_LABELS, ACTIVITY_STATUS_COLORS } from "@/lib/types";

interface Props {
  status: ActivityStatus | null | undefined;
  isClosed?: boolean;
}

export function TaskStatusBadge({ status, isClosed }: Props) {
  if (isClosed) {
    return (
      <span className="badge bg-gray-200 text-gray-500 text-[10px] px-1.5 py-0.5 rounded-full">
        Закрыта
      </span>
    );
  }
  if (!status) return null;
  const label = ACTIVITY_STATUS_LABELS[status];
  const color = ACTIVITY_STATUS_COLORS[status];
  return (
    <span className={`badge ${color} text-[10px] px-1.5 py-0.5 rounded-full`}>
      {label}
    </span>
  );
}
