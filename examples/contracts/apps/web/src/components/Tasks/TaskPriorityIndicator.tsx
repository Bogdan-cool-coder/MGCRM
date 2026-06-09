"use client";

import type { ActivityPriority } from "@/lib/types";
import { ACTIVITY_PRIORITY_COLORS } from "@/lib/types";

interface Props {
  priority: ActivityPriority | null | undefined;
  variant?: "bar" | "dot";
}

export function TaskPriorityIndicator({ priority, variant = "bar" }: Props) {
  const color = priority ? ACTIVITY_PRIORITY_COLORS[priority] : "bg-gray-200";
  const pulse = priority === "critical" ? "animate-pulse" : "";

  if (variant === "dot") {
    return <span className={`w-2 h-2 rounded-full shrink-0 ${color} ${pulse}`} />;
  }

  return (
    <div className={`w-1.5 rounded-full self-stretch shrink-0 ${color} ${pulse}`} style={{ minHeight: 28 }} />
  );
}
