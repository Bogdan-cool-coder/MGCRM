"use client";

import { useState } from "react";
import { AuditActionBadge } from "./AuditActionBadge";
import type { AuditLogEntry as AuditLogEntryType } from "@/lib/types";

interface AuditLogEntryProps {
  entry: AuditLogEntryType;
}

const MAX_VALUE_LEN = 80;

function truncate(s: string) {
  if (s.length <= MAX_VALUE_LEN) return s;
  return s.slice(0, MAX_VALUE_LEN) + "…";
}

function formatValue(v: unknown): string {
  if (v === null || v === undefined) return "—";
  if (typeof v === "boolean") return v ? "Да" : "Нет";
  return truncate(String(v));
}

function formatTime(s: string) {
  return new Date(s).toLocaleTimeString("ru-RU", { hour: "2-digit", minute: "2-digit" });
}

const MAX_FIELDS_SHOWN = 3;

export function AuditLogEntry({ entry }: AuditLogEntryProps) {
  const [expanded, setExpanded] = useState(false);
  const authorName = entry.user_name ?? "Система";
  const time = formatTime(entry.occurred_at);

  const diffFields = entry.diff_json?.fields
    ? Object.entries(entry.diff_json.fields)
    : [];
  const bulkAction = entry.diff_json?.bulk_action;

  const shownFields = expanded ? diffFields : diffFields.slice(0, MAX_FIELDS_SHOWN);
  const hiddenCount = diffFields.length - MAX_FIELDS_SHOWN;

  return (
    <div className="flex gap-3 py-2">
      {/* Time + dot */}
      <div className="flex flex-col items-center gap-1 pt-0.5">
        <span className="text-xs text-gray-400 tabular-nums whitespace-nowrap">{time}</span>
        <div className="w-2 h-2 rounded-full bg-gray-300 mt-0.5" />
      </div>

      {/* Content */}
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-2 flex-wrap">
          <span className="text-sm font-medium text-gray-900">{authorName}</span>
          <AuditActionBadge action={entry.action} />
        </div>

        {entry.action === "create" && (
          <div className="text-sm text-gray-600 mt-0.5">создал запись</div>
        )}

        {entry.action === "delete" && (
          <div className="text-sm text-danger mt-0.5">удалил запись</div>
        )}

        {entry.action === "bulk_action" && bulkAction && (
          <div className="text-sm text-gray-600 mt-0.5">{bulkAction}</div>
        )}

        {entry.action === "update" && diffFields.length > 0 && (
          <div className="mt-1 space-y-0.5">
            {shownFields.map(([field, change]) => (
              <div key={field} className="text-xs text-gray-600">
                <span className="font-medium text-gray-700">«{field}»:</span>{" "}
                <span className="line-through text-gray-400">{formatValue(change.old)}</span>
                {" → "}
                <span className="text-gray-900">{formatValue(change.new)}</span>
              </div>
            ))}
            {!expanded && hiddenCount > 0 && (
              <button
                onClick={() => setExpanded(true)}
                className="text-xs text-primary hover:underline mt-0.5"
              >
                + ещё {hiddenCount} {hiddenCount === 1 ? "поле" : hiddenCount < 5 ? "поля" : "полей"}
              </button>
            )}
          </div>
        )}
      </div>
    </div>
  );
}
