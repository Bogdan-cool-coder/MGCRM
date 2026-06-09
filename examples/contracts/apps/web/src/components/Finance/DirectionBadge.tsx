"use client";

import type { FinDirection } from "@/lib/types";

const DIRECTION_META: Record<FinDirection, { icon: string; color: string; label: string }> = {
  in:       { icon: "bi-arrow-up-circle-fill",   color: "text-success", label: "Приход" },
  out:      { icon: "bi-arrow-down-circle-fill",  color: "text-danger",  label: "Расход" },
  transfer: { icon: "bi-arrow-left-right",         color: "text-info",    label: "Перевод" },
};

interface Props {
  direction: FinDirection;
}

export function DirectionBadge({ direction }: Props) {
  const meta = DIRECTION_META[direction];
  return (
    <i
      className={`bi ${meta.icon} text-base ${meta.color}`}
      title={meta.label}
    />
  );
}
