"use client";

import { useState } from "react";
import clsx from "clsx";
import type { AISeverity, ContractAnalysisIssue } from "@/lib/types";

const SEVERITY_BORDER: Record<AISeverity, string> = {
  error: "border-l-danger",
  warning: "border-l-warning",
  info: "border-l-info",
};

const SEVERITY_ICON: Record<AISeverity, string> = {
  error: "bi-exclamation-triangle-fill text-danger",
  warning: "bi-exclamation-circle text-warning",
  info: "bi-info-circle text-info",
};

const SEVERITY_BADGE: Record<AISeverity, { className: string; label: string }> = {
  error: { className: "bg-danger/10 text-danger", label: "Критично" },
  warning: { className: "bg-warning/10 text-warning", label: "Внимание" },
  info: { className: "bg-info/10 text-info", label: "Информация" },
};

export function AnalysisFlagCard({ issue }: { issue: ContractAnalysisIssue }) {
  const [expanded, setExpanded] = useState(false);
  const sev = issue.severity;
  const hasSuggestion = !!(issue.suggestion && issue.suggestion.trim());

  return (
    <div
      className={clsx(
        "card p-4 mb-3 border-l-4",
        SEVERITY_BORDER[sev] ?? SEVERITY_BORDER.info,
      )}
    >
      <div className="flex items-start gap-2">
        <i className={clsx("bi mt-0.5", SEVERITY_ICON[sev] ?? SEVERITY_ICON.info)} />
        <div className="flex-1 min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <span className="font-medium text-sm">
              {issue.explanation || "Замечание"}
            </span>
            <span
              className={clsx(
                "inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium",
                SEVERITY_BADGE[sev]?.className ?? SEVERITY_BADGE.info.className,
              )}
            >
              {SEVERITY_BADGE[sev]?.label ?? SEVERITY_BADGE.info.label}
            </span>
            {issue.section && (
              <span className="text-xs text-gray-500">{issue.section}</span>
            )}
          </div>

          {issue.quote && (
            <blockquote className="italic text-sm bg-gray-50 dark:bg-gray-900 px-3 py-2 rounded border-l-2 border-gray-300 mt-2 text-gray-700 dark:text-gray-300">
              «{issue.quote}»
            </blockquote>
          )}

          {hasSuggestion && (
            <div className="mt-2">
              <button
                type="button"
                className="text-xs text-primary-light hover:underline inline-flex items-center gap-1"
                onClick={() => setExpanded((v) => !v)}
              >
                <i className={clsx("bi", expanded ? "bi-chevron-up" : "bi-chevron-down")} />
                {expanded ? "Скрыть" : "Рекомендация"}
              </button>
              {expanded && (
                <p className="text-sm text-gray-700 dark:text-gray-300 mt-1">
                  {issue.suggestion}
                </p>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
