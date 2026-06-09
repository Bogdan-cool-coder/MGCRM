"use client";

import { ActivityKindIcon } from "./ActivityKindIcon";
import { ACTIVITY_KIND_LABELS, type ActivityKind } from "@/lib/types";

interface ActivityKindBadgeProps {
  kind: ActivityKind;
  overdue?: boolean;
  done?: boolean;
}

export function ActivityKindBadge({ kind, overdue = false, done = false }: ActivityKindBadgeProps) {
  return (
    <span className="inline-flex items-center gap-1 text-xs text-gray-700">
      <ActivityKindIcon kind={kind} overdue={overdue} done={done} />
      <span>{ACTIVITY_KIND_LABELS[kind]}</span>
    </span>
  );
}
