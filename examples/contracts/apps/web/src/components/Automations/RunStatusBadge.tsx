import clsx from "clsx";
import { RUN_STATUS_BADGE, RUN_STATUS_LABELS } from "@/lib/automationConfig";
import type { AutomationRunStatus } from "@/lib/types";

export function RunStatusBadge({ status, className }: { status: AutomationRunStatus; className?: string }) {
  const meta = RUN_STATUS_BADGE[status];
  return (
    <span
      className={clsx(
        "inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium",
        meta.bg,
        className,
      )}
    >
      <span className={clsx("w-1.5 h-1.5 rounded-full", meta.dot)} />
      {RUN_STATUS_LABELS[status]}
    </span>
  );
}
