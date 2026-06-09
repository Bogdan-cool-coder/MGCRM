"use client";

import { LEAD_SOURCE_LABELS, type LeadSource } from "@/lib/types";

const SOURCE_STYLES: Record<LeadSource, string> = {
  manual: "bg-gray-100 text-gray-700",
  form: "bg-blue-100 text-blue-700",
  import: "bg-purple-100 text-purple-700",
  api: "bg-indigo-100 text-indigo-700",
  email: "bg-green-100 text-green-700",
  tg: "bg-cyan-100 text-cyan-700",
  wa: "bg-emerald-100 text-emerald-700",
};

export function SourceBadge({ source }: { source: LeadSource }) {
  const style = SOURCE_STYLES[source];
  const label = LEAD_SOURCE_LABELS[source];
  return (
    <span
      className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold ${style}`}
      title={label}
    >
      {label}
    </span>
  );
}
