"use client";

import type { FinCalendarEvent } from "@/lib/types";
import { formatSignedAmount } from "./helpers";

interface Props {
  event: FinCalendarEvent;
  onClick?: () => void;
}

/**
 * Чип события в ячейке дня.
 * in → success (+), out → danger (−); overdue → ring-warning + иконка; paid → opacity-60.
 */
export function EventChip({ event, onClick }: Props) {
  const isIn = event.direction === "in";
  const overdue = event.status === "overdue";
  const paid = event.status === "paid";

  const base = isIn
    ? "bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-400"
    : "bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-400";

  return (
    <button
      type="button"
      onClick={onClick}
      title={`${event.title} · ${formatSignedAmount(event.amount, event.direction, event.currency)}`}
      className={
        "w-full text-left rounded px-1.5 py-0.5 text-[11px] leading-tight truncate transition-colors " +
        base +
        (overdue ? " ring-1 ring-warning" : "") +
        (paid ? " opacity-60" : "") +
        " hover:brightness-95 dark:hover:brightness-110"
      }
    >
      {overdue && <i className="bi bi-exclamation-triangle mr-1" />}
      <span className="font-semibold">{isIn ? "+" : "−"}</span>
      <span className="ml-1">{event.title}</span>
    </button>
  );
}
