"use client";

import { CHANNEL_KIND_LABELS, type ChannelKind } from "@/lib/types";

/** Бейдж канала: иконка + название + цвет.
 * Используется в Inbox-таблице, фильтрах, карточке канала.
 */
const KIND_META: Record<ChannelKind, { icon: string; classes: string }> = {
  tg:       { icon: "bi-telegram",       classes: "bg-info-50 text-info-700 dark:bg-info-500/10 dark:text-info-500" },
  wa:       { icon: "bi-whatsapp",       classes: "bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-500" },
  email:    { icon: "bi-envelope-fill",  classes: "bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-500" },
  web_form: { icon: "bi-ui-checks-grid", classes: "bg-info-50 text-info-700 dark:bg-info-500/10 dark:text-info-500" },
  api:      { icon: "bi-plug-fill",      classes: "bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300" },
};

export function KindBadge({
  kind,
  size = "sm",
}: {
  kind: ChannelKind;
  size?: "sm" | "md";
}) {
  const meta = KIND_META[kind] ?? KIND_META.api;
  const sizing = size === "md" ? "px-3 py-1 text-sm" : "px-2.5 py-0.5 text-xs";
  return (
    <span className={`inline-flex items-center gap-1 rounded-full font-medium ${meta.classes} ${sizing}`}>
      <i className={`bi ${meta.icon}`} />
      {CHANNEL_KIND_LABELS[kind]}
    </span>
  );
}
