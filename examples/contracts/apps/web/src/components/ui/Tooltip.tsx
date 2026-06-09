"use client";

/**
 * Стилизованная обёртка Radix Tooltip под наш дизайн.
 *
 * ВАЖНО: TooltipProvider монтировать один раз в (app)/layout.tsx.
 * Обёртка экспортирует и сам провайдер.
 *
 * Использование:
 *   <Tooltip content="Копировать">
 *     <button>...</button>
 *   </Tooltip>
 */

import * as Radix from "@radix-ui/react-tooltip";
import clsx from "clsx";

// ─── Provider (монтировать в layout) ─────────────────────────────────────────

export const TooltipProvider = Radix.Provider;

// ─── Tooltip ─────────────────────────────────────────────────────────────────

interface TooltipProps {
  content: React.ReactNode;
  children: React.ReactNode;
  side?: "top" | "right" | "bottom" | "left";
  align?: "start" | "center" | "end";
  sideOffset?: number;
  delayDuration?: number;
}

export function Tooltip({
  content,
  children,
  side = "top",
  align = "center",
  sideOffset = 6,
  delayDuration = 400,
}: TooltipProps) {
  return (
    <Radix.Root delayDuration={delayDuration}>
      <Radix.Trigger asChild>{children}</Radix.Trigger>
      <Radix.Portal>
        <Radix.Content
          side={side}
          align={align}
          sideOffset={sideOffset}
          className={clsx(
            "z-[60] max-w-xs rounded-md px-2.5 py-1.5 text-xs leading-snug",
            "bg-gray-800 text-gray-100 shadow-elev-2",
            "dark:bg-gray-700 dark:text-gray-100",
            // Анимация — переиспользуем popover-in/out
            "data-[state=delayed-open]:popover-in",
            "data-[state=instant-open]:popover-in",
            "data-[state=closed]:popover-out",
            "select-none",
          )}
        >
          {content}
          <Radix.Arrow className="fill-gray-800 dark:fill-gray-700" />
        </Radix.Content>
      </Radix.Portal>
    </Radix.Root>
  );
}
