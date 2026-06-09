"use client";

import { useState } from "react";
import { StagePopover } from "@/components/Deals/StagePopover";
import type { PipelineStage } from "@/lib/types";

interface StagePillProps {
  stages: PipelineStage[];
  currentStageId: number;
  /** Перевод в выбранный этап. Обработка ошибок — на стороне родителя. */
  onSelect: (stageId: number) => void;
  disabled?: boolean;
}

/**
 * Кликабельный pill текущего этапа сделки.
 * Клик → StagePopover (Radix Popover) со всеми этапами → onSelect(stageId).
 */
export function StagePill({ stages, currentStageId, onSelect, disabled }: StagePillProps) {
  const [open, setOpen] = useState(false);
  const current = stages.find((s) => s.id === currentStageId);
  const color = current?.color ?? "#6B7A99";

  return (
    <StagePopover
      stages={stages}
      currentStageId={currentStageId}
      onSelect={onSelect}
      onClose={() => setOpen(false)}
      open={open}
      trigger={
        <button
          type="button"
          disabled={disabled}
          onClick={() => setOpen((v) => !v)}
          className="inline-flex items-center gap-1.5 text-sm font-medium px-3 py-1.5 rounded-full transition-colors disabled:opacity-60"
          style={{ backgroundColor: `${color}25`, color }}
        >
          <span className="w-2 h-2 rounded-full shrink-0" style={{ backgroundColor: color }} />
          {current?.name ?? `Этап #${currentStageId}`}
          <i className="bi bi-chevron-down text-xs opacity-70" />
        </button>
      }
    />
  );
}
