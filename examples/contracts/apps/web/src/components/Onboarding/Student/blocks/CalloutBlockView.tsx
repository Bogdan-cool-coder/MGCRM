"use client";

import type { CalloutBlock, CalloutVariant } from "@/lib/types";

interface Props {
  block: CalloutBlock;
}

const CALLOUT_STYLES: Record<CalloutVariant, { border: string; bg: string; icon: string; iconColor: string }> = {
  info:    { border: "border-info",    bg: "bg-info/5",    icon: "bi-info-circle-fill",         iconColor: "text-info" },
  warning: { border: "border-warning", bg: "bg-warning/5", icon: "bi-exclamation-triangle-fill", iconColor: "text-warning" },
  success: { border: "border-success", bg: "bg-success/5", icon: "bi-check-circle-fill",         iconColor: "text-success" },
  danger:  { border: "border-danger",  bg: "bg-danger/5",  icon: "bi-exclamation-circle-fill",   iconColor: "text-danger" },
};

export function CalloutBlockView({ block }: Props) {
  const styles = CALLOUT_STYLES[block.variant];

  return (
    <div className={`border-l-4 ${styles.border} ${styles.bg} p-4 rounded-r-lg flex gap-3 items-start mb-6`}>
      <i className={`bi ${styles.icon} ${styles.iconColor} text-lg mt-0.5 shrink-0`} />
      <p className="text-sm text-gray-800 leading-relaxed">{block.text}</p>
    </div>
  );
}
