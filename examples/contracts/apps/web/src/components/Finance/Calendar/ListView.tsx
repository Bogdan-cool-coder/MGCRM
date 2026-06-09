"use client";

import Link from "next/link";
import type { FinCalendarEvent } from "@/lib/types";
import {
  fullDateLabel,
  formatSignedAmount,
  groupByDate,
  netByCurrency,
  sourceLabel,
  sourceRoute,
  statusLabel,
  statusBadgeCls,
} from "./helpers";
import type { DirectionFilter } from "./CalendarToolbar";

interface Props {
  events: FinCalendarEvent[];
  direction: DirectionFilter;
}

export function ListView({ events, direction }: Props) {
  const byDate = groupByDate(events);
  const dates = Array.from(byDate.keys()).sort();
  const showNet = direction === "all";

  return (
    <div className="flex flex-col gap-4">
      {dates.map((iso) => {
        const dayEvents = byDate.get(iso) ?? [];
        const nets = netByCurrency(dayEvents);
        return (
          <div key={iso} className="card overflow-hidden">
            {/* Шапка дня с итогами */}
            <div className="flex items-center justify-between gap-3 px-4 py-2.5 bg-gray-50 dark:bg-gray-900/40 border-b border-gray-100 dark:border-gray-700">
              <span className="text-sm font-semibold text-gray-800 dark:text-gray-200 capitalize">
                {fullDateLabel(iso)}
              </span>
              <div className="flex items-center gap-3 flex-wrap justify-end text-xs">
                {nets.map((n) => (
                  <span key={n.currency} className="inline-flex items-center gap-1.5">
                    <span className="text-gray-400 dark:text-gray-500">{n.currency}</span>
                    {showNet ? (
                      <span className="font-semibold text-gray-700 dark:text-gray-200">
                        {formatSignedAmount(
                          Math.abs(n.in_amount - n.out_amount),
                          n.in_amount - n.out_amount >= 0 ? "in" : "out",
                          n.currency,
                        )}
                      </span>
                    ) : (
                      <span
                        className={
                          "font-semibold " + (direction === "in" ? "text-success" : "text-danger")
                        }
                      >
                        {formatSignedAmount(
                          direction === "in" ? n.in_amount : n.out_amount,
                          direction === "in" ? "in" : "out",
                          n.currency,
                        )}
                      </span>
                    )}
                  </span>
                ))}
              </div>
            </div>

            {/* Строки */}
            <ul className="divide-y divide-gray-100 dark:divide-gray-700">
              {dayEvents.map((e, idx) => (
                <li
                  key={`${e.source_type}-${e.source_id}-${idx}`}
                  className="flex items-center justify-between gap-3 px-4 py-2.5"
                >
                  <div className="min-w-0 flex items-center gap-2">
                    <i
                      className={
                        "bi " +
                        (e.direction === "in"
                          ? "bi-arrow-down-left text-success"
                          : "bi-arrow-up-right text-danger")
                      }
                    />
                    <div className="min-w-0">
                      <Link
                        href={sourceRoute(e.source_type, e.source_id)}
                        className="text-sm font-medium text-primary dark:text-primary-light hover:underline truncate block"
                      >
                        {e.title}
                      </Link>
                      <div className="flex items-center gap-2 mt-0.5">
                        <span className="text-xs text-gray-500 dark:text-gray-400">
                          {sourceLabel(e.source_type)} #{e.source_id}
                        </span>
                        <span className={`inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-medium ${statusBadgeCls(e.status)}`}>
                          {statusLabel(e.status)}
                        </span>
                      </div>
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
                </li>
              ))}
            </ul>
          </div>
        );
      })}
    </div>
  );
}
