import clsx from "clsx";
import { TRIGGER_LABELS } from "@/lib/automationConfig";
import type { AutomationTriggerKind } from "@/lib/types";

const ICONS: Record<AutomationTriggerKind, string> = {
  on_enter_stage: "bi-box-arrow-in-right",
  idle_in_stage_days: "bi-hourglass-split",
  date_field_approaching: "bi-calendar-event",
  on_create: "bi-plus-circle-fill",
};

const COLORS: Record<AutomationTriggerKind, string> = {
  on_enter_stage:         "bg-info-50 text-info-700 dark:bg-info-500/10 dark:text-info-500",
  idle_in_stage_days:     "bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-500",
  date_field_approaching: "bg-info-50 text-info-700 dark:bg-info-500/10 dark:text-info-500",
  on_create:              "bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-500",
};

export function TriggerBadge({ kind, className }: { kind: AutomationTriggerKind; className?: string }) {
  return (
    <span
      className={clsx(
        "inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap",
        COLORS[kind],
        className,
      )}
    >
      <i className={`bi ${ICONS[kind]}`} />
      {TRIGGER_LABELS[kind]}
    </span>
  );
}
