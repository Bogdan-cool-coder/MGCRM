"use client";

import { useState } from "react";
import type { DealPrefillSuggestion } from "@/lib/types";
import { ConfidenceBadge } from "./ConfidenceBadge";

const FIELD_ICON: Record<string, string> = {
  amount: "bi-currency-exchange",
  estimated_budget: "bi-currency-exchange",
  expected_close_date: "bi-calendar",
  contact_name: "bi-person",
  description: "bi-chat-left-text",
  country: "bi-globe",
  company_name: "bi-building",
  qualification_score: "bi-bar-chart",
  title: "bi-tag",
  currency: "bi-coin",
  lost_reason: "bi-x-circle",
};

function valueToDisplay(value: string | number | null): string {
  if (value === null || value === undefined) return "—";
  if (typeof value === "number") return value.toLocaleString("ru-RU");
  return String(value);
}

export function PrefillSuggestionRow({
  suggestion,
  checked,
  onToggle,
}: {
  suggestion: DealPrefillSuggestion;
  checked: boolean;
  onToggle: (checked: boolean) => void;
}) {
  const [expanded, setExpanded] = useState(false);
  const icon = FIELD_ICON[suggestion.field] ?? "bi-tag";
  const lowConfidence = suggestion.confidence === "low";

  return (
    <div
      className={[
        "card p-3 mb-2 flex items-start gap-3",
        lowConfidence ? "opacity-70" : "",
      ].join(" ")}
    >
      <input
        type="checkbox"
        checked={checked}
        onChange={(e) => onToggle(e.target.checked)}
        className="accent-primary mt-0.5 flex-shrink-0"
      />
      <i className={`bi ${icon} text-gray-400 mt-0.5 flex-shrink-0`} />
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-2">
          <span className="text-sm font-medium dark:text-gray-200">
            {suggestion.label}
          </span>
          <span className="ml-auto">
            <ConfidenceBadge confidence={suggestion.confidence} />
          </span>
        </div>
        <p className="text-sm text-gray-700 dark:text-gray-300 mt-0.5">
          <span className="text-gray-500">AI предлагает:</span>{" "}
          <span className="font-medium">{valueToDisplay(suggestion.suggested_value)}</span>
        </p>
        {(suggestion.reasoning || suggestion.source_text) && (
          <button
            type="button"
            className="text-xs text-gray-400 hover:text-primary mt-1 inline-flex items-center gap-1"
            onClick={() => setExpanded((v) => !v)}
          >
            <i className={`bi ${expanded ? "bi-chevron-up" : "bi-chevron-down"}`} />
            {expanded ? "Скрыть" : "Источник"}
          </button>
        )}
        {expanded && (
          <div className="text-xs text-gray-500 bg-gray-50 dark:bg-gray-900 px-2 py-1 rounded mt-1">
            {suggestion.source_text && (
              <p className="italic">«{suggestion.source_text}»</p>
            )}
            {suggestion.reasoning && (
              <p className="mt-1">{suggestion.reasoning}</p>
            )}
            {suggestion.source_activity_id != null && (
              <p className="mt-1 text-gray-400">
                Активность #{suggestion.source_activity_id}
              </p>
            )}
          </div>
        )}
      </div>
    </div>
  );
}
