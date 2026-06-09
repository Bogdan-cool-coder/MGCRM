"use client";

import Link from "next/link";
import { Modal } from "@/components/Modal";
import type { FinCalendarEvent } from "@/lib/types";
import {
  fullDateLabel,
  formatSignedAmount,
  netByCurrency,
  sourceLabel,
  sourceRoute,
  statusLabel,
  statusBadgeCls,
} from "./helpers";
import type { DirectionFilter } from "./CalendarToolbar";

interface Props {
  open: boolean;
  date: string | null;
  events: FinCalendarEvent[];
  direction: DirectionFilter;
  onClose: () => void;
}

export function DayDrilldown({ open, date, events, direction, onClose }: Props) {
  const nets = netByCurrency(events);
  const showNet = direction === "all";

  return (
    <Modal
      open={open && date !== null}
      title={date ? fullDateLabel(date) : "День"}
      description={`Платежей: ${events.length}`}
      onClose={onClose}
      width="md"
    >
      {/* Итоги по дню */}
      {nets.length > 0 && (
        <div className="mb-4 rounded-md border border-gray-200 dark:border-gray-700 p-3">
          <div className="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2">
            Итоги по дню
          </div>
          {nets.length > 1 && (
            <p className="text-xs text-gray-400 dark:text-gray-500 mb-2">
              Несколько валют — итоги раздельно
            </p>
          )}
          <div className="space-y-1.5">
            {nets.map((n) => (
              <div key={n.currency} className="flex items-center justify-between text-sm">
                <span className="font-medium text-gray-700 dark:text-gray-300">{n.currency}</span>
                <div className="flex items-center gap-3">
                  {(direction === "all" || direction === "in") && n.in_amount > 0 && (
                    <span className="text-success">
                      {formatSignedAmount(n.in_amount, "in", n.currency)}
                    </span>
                  )}
                  {(direction === "all" || direction === "out") && n.out_amount > 0 && (
                    <span className="text-danger">
                      {formatSignedAmount(n.out_amount, "out", n.currency)}
                    </span>
                  )}
                  {showNet && (
                    <span className="font-semibold text-gray-900 dark:text-gray-100 min-w-[100px] text-right">
                      {formatSignedAmount(
                        Math.abs(n.in_amount - n.out_amount),
                        n.in_amount - n.out_amount >= 0 ? "in" : "out",
                        n.currency,
                      )}
                    </span>
                  )}
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Список событий */}
      <ul className="divide-y divide-gray-100 dark:divide-gray-700">
        {events.map((e, idx) => (
          <li key={`${e.source_type}-${e.source_id}-${idx}`} className="py-2.5">
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <Link
                  href={sourceRoute(e.source_type, e.source_id)}
                  className="text-sm font-medium text-primary dark:text-primary-light hover:underline truncate block"
                >
                  {e.title}
                </Link>
                <div className="flex items-center gap-2 mt-1">
                  <span className="text-xs text-gray-500 dark:text-gray-400">
                    {sourceLabel(e.source_type)} #{e.source_id}
                  </span>
                  <span className={`inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-medium ${statusBadgeCls(e.status)}`}>
                    {statusLabel(e.status)}
                  </span>
                </div>
              </div>
              <span
                className={
                  "shrink-0 text-sm font-semibold " +
                  (e.direction === "in" ? "text-success" : "text-danger")
                }
              >
                {formatSignedAmount(e.amount, e.direction, e.currency)}
              </span>
            </div>
          </li>
        ))}
        {events.length === 0 && (
          <li className="py-6 text-center text-sm text-gray-400 dark:text-gray-500">
            Нет платежей в этот день
          </li>
        )}
      </ul>
    </Modal>
  );
}
