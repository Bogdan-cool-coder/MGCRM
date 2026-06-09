"use client";

import type { ActivityKind } from "@/lib/types";

interface ActivityKindIconProps {
  kind: ActivityKind;
  /** Для task: если просрочена и не выполнена — рисуем как danger */
  overdue?: boolean;
  /** Для task: если выполнена — приглушённый стиль */
  done?: boolean;
  /** Кружок-маркер в Timeline (увеличенный). Если false — inline-иконка. */
  marker?: boolean;
}

interface KindStyle {
  icon: string;
  bg: string;
  text: string;
  /** Inline style используется для цветов, которых нет в Tailwind-токенах (фиолетовый) */
  style?: React.CSSProperties;
}

function styleFor(kind: ActivityKind, overdue: boolean, done: boolean): KindStyle {
  if (done) {
    return { icon: kindIcon(kind), bg: "bg-gray-100", text: "text-gray-500" };
  }
  switch (kind) {
    case "call":
      return { icon: "bi-telephone-fill", bg: "bg-info/30", text: "text-primary" };
    case "meeting":
      return {
        icon: "bi-people-fill",
        bg: "bg-gray-100",
        text: "",
        style: { backgroundColor: "#EDE9FE", color: "#7C3AED" },
      };
    case "task":
      if (overdue) {
        return { icon: "bi-check2-square", bg: "bg-danger/20", text: "text-danger" };
      }
      return { icon: "bi-check2-square", bg: "bg-warning-50", text: "text-warning-700" };
    case "note":
      return { icon: "bi-sticky-fill", bg: "bg-gray-100", text: "text-gray-600" };
  }
}

function kindIcon(kind: ActivityKind): string {
  switch (kind) {
    case "call": return "bi-telephone-fill";
    case "meeting": return "bi-people-fill";
    case "task": return "bi-check2-square";
    case "note": return "bi-sticky-fill";
  }
}

export function ActivityKindIcon({ kind, overdue = false, done = false, marker = false }: ActivityKindIconProps) {
  const s = styleFor(kind, overdue, done);
  if (marker) {
    return (
      <span
        className={`inline-flex items-center justify-center w-8 h-8 rounded-full shrink-0 ${s.bg} ${s.text}`}
        style={s.style}
      >
        <i className={`bi ${s.icon} text-sm`} />
      </span>
    );
  }
  return (
    <span
      className={`inline-flex items-center justify-center w-5 h-5 rounded-full shrink-0 ${s.bg} ${s.text}`}
      style={s.style}
    >
      <i className={`bi ${s.icon} text-[10px]`} />
    </span>
  );
}
