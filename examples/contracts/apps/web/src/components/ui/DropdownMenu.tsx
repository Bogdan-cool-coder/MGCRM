"use client";

/**
 * Стилизованная обёртка Radix DropdownMenu под наш дизайн.
 *
 * Использование:
 *   <DropdownMenu trigger={<button>...</button>}>
 *     <DropdownMenuItem onSelect={() => ...}>Открыть</DropdownMenuItem>
 *     <DropdownMenuSeparator />
 *     <DropdownMenuItem variant="danger" onSelect={() => ...}>Удалить</DropdownMenuItem>
 *   </DropdownMenu>
 *
 *   Вложенное меню (для «Перевести в этап»):
 *   <DropdownMenuSub>
 *     <DropdownMenuSubTrigger>Этап <i className="bi bi-chevron-right" /></DropdownMenuSubTrigger>
 *     <DropdownMenuSubContent>...</DropdownMenuSubContent>
 *   </DropdownMenuSub>
 */

import * as Radix from "@radix-ui/react-dropdown-menu";
import clsx from "clsx";

// ─── Shared styles ────────────────────────────────────────────────────────────

const contentBase = clsx(
  // Layout
  "min-w-[160px] rounded-lg border border-gray-200 bg-white py-1",
  "shadow-elev-3 dark:bg-gray-800 dark:border-gray-700",
  // Анимация (data-state управляется Radix)
  "data-[state=open]:popover-in data-[state=closed]:popover-out",
  // z-index (выше модалок z-40, ниже confirm-диалога z-50)
  "z-[45]",
  "focus:outline-none",
);

const itemBase = clsx(
  "flex w-full cursor-pointer select-none items-center gap-2 px-3 py-2 text-sm",
  "text-gray-700 dark:text-gray-300",
  "rounded-sm transition-colors",
  "hover:bg-gray-50 dark:hover:bg-gray-700",
  "focus:bg-gray-50 dark:focus:bg-gray-700 focus:outline-none",
  "data-[disabled]:pointer-events-none data-[disabled]:opacity-40",
);

// ─── Root ─────────────────────────────────────────────────────────────────────

interface DropdownMenuProps {
  trigger: React.ReactNode;
  children: React.ReactNode;
  /** Выравнивание относительно триггера (default: "end") */
  align?: "start" | "center" | "end";
  /** Сторона появления (default: "bottom") */
  side?: "top" | "right" | "bottom" | "left";
  /** Отступ от триггера px (default: 4) */
  sideOffset?: number;
  /** Управляемый open (опционально) */
  open?: boolean;
  onOpenChange?: (open: boolean) => void;
}

export function DropdownMenu({
  trigger,
  children,
  align = "end",
  side = "bottom",
  sideOffset = 4,
  open,
  onOpenChange,
}: DropdownMenuProps) {
  return (
    <Radix.Root open={open} onOpenChange={onOpenChange} modal={false}>
      <Radix.Trigger asChild>{trigger}</Radix.Trigger>
      <Radix.Portal>
        <Radix.Content
          align={align}
          side={side}
          sideOffset={sideOffset}
          className={contentBase}
          onClick={(e) => e.stopPropagation()}
        >
          {children}
        </Radix.Content>
      </Radix.Portal>
    </Radix.Root>
  );
}

// ─── Item ─────────────────────────────────────────────────────────────────────

interface DropdownMenuItemProps {
  children: React.ReactNode;
  onSelect?: () => void;
  variant?: "default" | "danger";
  disabled?: boolean;
  className?: string;
}

export function DropdownMenuItem({
  children,
  onSelect,
  variant = "default",
  disabled = false,
  className,
}: DropdownMenuItemProps) {
  return (
    <Radix.Item
      disabled={disabled}
      onSelect={onSelect}
      className={clsx(
        itemBase,
        variant === "danger" && "text-danger hover:bg-danger-50 dark:hover:bg-danger-500/10",
        className,
      )}
    >
      {children}
    </Radix.Item>
  );
}

// ─── Separator ───────────────────────────────────────────────────────────────

export function DropdownMenuSeparator() {
  return <Radix.Separator className="my-1 h-px bg-gray-100 dark:bg-gray-700" />;
}

// ─── Sub (вложенное подменю) ─────────────────────────────────────────────────

export const DropdownMenuSub = Radix.Sub;

interface DropdownMenuSubTriggerProps {
  children: React.ReactNode;
  className?: string;
}

export function DropdownMenuSubTrigger({ children, className }: DropdownMenuSubTriggerProps) {
  return (
    <Radix.SubTrigger
      className={clsx(
        itemBase,
        "data-[state=open]:bg-gray-50 dark:data-[state=open]:bg-gray-700",
        className,
      )}
    >
      {children}
    </Radix.SubTrigger>
  );
}

interface DropdownMenuSubContentProps {
  children: React.ReactNode;
  className?: string;
}

export function DropdownMenuSubContent({ children, className }: DropdownMenuSubContentProps) {
  return (
    <Radix.Portal>
      <Radix.SubContent
        sideOffset={4}
        className={clsx(contentBase, "min-w-[180px]", className)}
      >
        {children}
      </Radix.SubContent>
    </Radix.Portal>
  );
}
