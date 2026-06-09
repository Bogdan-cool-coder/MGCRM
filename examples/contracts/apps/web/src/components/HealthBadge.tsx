"use client";

import clsx from "clsx";
import { TIER_META } from "@/lib/types";

/**
 * HealthBadge — отображает тир здоровья подписки.
 * Использует soft-схему токенов v2: badge-success / badge-warning / badge-danger / badge-neutral.
 * TIER_META.cls содержит Tailwind-классы (без base .badge), добавляем его здесь.
 */
export function HealthBadge({ tier, manual }: { tier?: string | null; manual?: boolean }) {
  if (!tier) return <span className="text-xs text-gray-300">—</span>;
  const m = TIER_META[tier] ?? {
    label: tier,
    cls: "bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400",
  };
  return (
    <span
      className={clsx(
        "inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold",
        m.cls,
      )}
      title={`${m.label}${manual ? " (зафиксировано вручную)" : ""}`}
    >
      {tier}
      {manual ? "·" : ""}
    </span>
  );
}
