"use client";

/**
 * Стилизованная обёртка Radix Popover под наш дизайн.
 *
 * Использование:
 *   <Popover
 *     trigger={<button>Этап</button>}
 *     open={popoverOpen}
 *     onOpenChange={setPopoverOpen}
 *   >
 *     <div>Содержимое попап</div>
 *   </Popover>
 */

import * as Radix from "@radix-ui/react-popover";
import clsx from "clsx";

interface PopoverProps {
  trigger: React.ReactNode;
  children: React.ReactNode;
  align?: "start" | "center" | "end";
  side?: "top" | "right" | "bottom" | "left";
  sideOffset?: number;
  open?: boolean;
  onOpenChange?: (open: boolean) => void;
  /** Ширина попапа, по умолчанию auto */
  contentClassName?: string;
}

export function Popover({
  trigger,
  children,
  align = "start",
  side = "bottom",
  sideOffset = 4,
  open,
  onOpenChange,
  contentClassName,
}: PopoverProps) {
  return (
    <Radix.Root open={open} onOpenChange={onOpenChange}>
      <Radix.Trigger asChild>{trigger}</Radix.Trigger>
      <Radix.Portal>
        <Radix.Content
          align={align}
          side={side}
          sideOffset={sideOffset}
          onOpenAutoFocus={(e) => e.preventDefault()}
          className={clsx(
            "rounded-lg border border-gray-200 bg-white py-1 shadow-elev-3",
            "dark:bg-gray-800 dark:border-gray-700",
            "data-[state=open]:popover-in data-[state=closed]:popover-out",
            "z-[45] focus:outline-none",
            contentClassName,
          )}
        >
          {children}
        </Radix.Content>
      </Radix.Portal>
    </Radix.Root>
  );
}
