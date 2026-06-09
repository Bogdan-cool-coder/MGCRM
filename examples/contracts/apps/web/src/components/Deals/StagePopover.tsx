"use client";

/**
 * StagePopover — попап выбора этапа воронки.
 *
 * Публичный API сохранён 1:1 (StagePill.tsx и другие не правятся):
 *   stages, currentStageId, onSelect, onClose
 *
 * Внутри — Radix Popover (focus-trap, ESC, click-outside автоматически).
 * Визуально идентично предыдущему, плюс dark mode и анимация.
 */

import * as RadixPopover from "@radix-ui/react-popover";
import clsx from "clsx";
import type { PipelineStage } from "@/lib/types";

interface StagePopoverProps {
  stages: PipelineStage[];
  currentStageId: number;
  onSelect: (stageId: number) => void;
  onClose: () => void;
  /** Триггер — по умолчанию невидимый span (попап управляется снаружи через open) */
  trigger?: React.ReactNode;
  open?: boolean;
}

export function StagePopover({
  stages,
  currentStageId,
  onSelect,
  onClose,
  trigger,
  open,
}: StagePopoverProps) {
  return (
    <RadixPopover.Root
      open={open ?? true}
      onOpenChange={(isOpen) => { if (!isOpen) onClose(); }}
    >
      <RadixPopover.Trigger asChild>
        {/* Если триггер не передан — невидимый span (управление через `open` снаружи) */}
        {trigger ?? <span className="sr-only" />}
      </RadixPopover.Trigger>
      <RadixPopover.Portal>
        <RadixPopover.Content
          align="start"
          side="bottom"
          sideOffset={4}
          onOpenAutoFocus={(e) => e.preventDefault()}
          className={clsx(
            "z-[45] w-48 rounded-lg border border-gray-200 bg-white py-1 shadow-elev-3",
            "dark:bg-gray-800 dark:border-gray-700",
            "data-[state=open]:popover-in data-[state=closed]:popover-out",
            "focus:outline-none",
          )}
        >
          {stages.map((s) => (
            <button
              key={s.id}
              onClick={() => { onSelect(s.id); onClose(); }}
              className={clsx(
                "w-full text-left px-3 py-2 text-sm flex items-center gap-2",
                "text-gray-700 dark:text-gray-300",
                "hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors",
                s.id === currentStageId && "bg-gray-100 dark:bg-gray-700 font-medium",
              )}
            >
              <span
                className="w-2 h-2 rounded-full shrink-0"
                style={{ backgroundColor: s.color ?? "#6B7A99" }}
              />
              {s.name}
            </button>
          ))}
        </RadixPopover.Content>
      </RadixPopover.Portal>
    </RadixPopover.Root>
  );
}
