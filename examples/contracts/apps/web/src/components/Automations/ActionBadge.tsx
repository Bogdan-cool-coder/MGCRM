import clsx from "clsx";
import { ACTION_LABELS } from "@/lib/automationConfig";
import type { AutomationActionKind } from "@/lib/types";

const ICONS: Record<AutomationActionKind, string> = {
  tg_notify: "bi-telegram",
  create_task: "bi-check2-square",
  set_field: "bi-pencil-square",
  generate_document: "bi-file-earmark-text",
  change_owner: "bi-person-gear",
  webhook: "bi-send-fill",
  email: "bi-envelope-fill",
  start_sequence: "bi-collection-play-fill",
  // Эпик 23
  set_tags: "bi-tags",
  complete_tasks: "bi-check-circle-fill",
  change_stage: "bi-arrow-right-circle-fill",
  create_deal: "bi-currency-dollar",
};

const COLORS: Record<AutomationActionKind, string> = {
  tg_notify:         "bg-info-50 text-info-700 dark:bg-info-500/10 dark:text-info-500",
  create_task:       "bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-500",
  set_field:         "bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-500",
  generate_document: "bg-primary text-white",
  change_owner:      "bg-info-50 text-info-700 dark:bg-info-500/10 dark:text-info-500",
  webhook:           "bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-500",
  email:             "bg-info-50 text-info-700 dark:bg-info-500/10 dark:text-info-500",
  start_sequence:    "bg-primary text-white",
  // Эпик 23
  set_tags:       "bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-500",
  complete_tasks: "bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-500",
  change_stage:   "bg-info-50 text-info-700 dark:bg-info-500/10 dark:text-info-500",
  create_deal:    "bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-500",
};

export function ActionBadge({ kind, className }: { kind: AutomationActionKind; className?: string }) {
  return (
    <span
      className={clsx(
        "inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap",
        COLORS[kind],
        className,
      )}
    >
      <i className={`bi ${ICONS[kind]}`} />
      {ACTION_LABELS[kind]}
    </span>
  );
}
