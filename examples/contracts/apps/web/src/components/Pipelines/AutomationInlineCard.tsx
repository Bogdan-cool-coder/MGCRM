"use client";

import { useState } from "react";
import { ACTION_ICONS, ACTION_ICON_COLORS } from "@/lib/automationConfig";
import { TRIGGER_LABELS } from "@/lib/automationConfig";
import { getActionSummary } from "@/lib/pipelineVisual";
import { ACTION_LABELS } from "@/lib/automationConfig";
import type { Automation } from "@/lib/types";

interface AutomationInlineCardProps {
  automation: Automation;
  onEdit: () => void;
  onDelete: () => void;
}

export function AutomationInlineCard({ automation, onEdit, onDelete }: AutomationInlineCardProps) {
  const [deletePending, setDeletePending] = useState(false);
  const [deleteError, setDeleteError] = useState<string | null>(null);

  const triggerLabel = TRIGGER_LABELS[automation.trigger_kind] ?? automation.trigger_kind;
  const actionLabel = ACTION_LABELS[automation.action_kind] ?? automation.action_kind;
  const actionSummary = getActionSummary(automation);
  const actionIcon = ACTION_ICONS[automation.action_kind] ?? "bi-lightning";
  const iconColor = ACTION_ICON_COLORS[automation.action_kind] ?? "text-gray-500";

  async function confirmDelete() {
    setDeleteError(null);
    try {
      await onDelete();
    } catch {
      setDeleteError("Не удалось удалить");
      setDeletePending(false);
    }
  }

  return (
    <div>
      <div className="group flex items-start gap-2 p-2.5 rounded-md border border-gray-100 bg-white hover:border-gray-300 transition-colors">
        {/* Иконка триггера */}
        <span className={`mt-0.5 shrink-0 text-sm ${iconColor}`}>
          <i className={`bi ${actionIcon}`} />
        </span>

        {/* Текст */}
        <div className="flex-1 min-w-0">
          <div className="text-xs font-medium text-gray-700">{triggerLabel}</div>
          <div className="text-xs text-gray-500 truncate">
            {actionLabel}: {actionSummary}
          </div>
        </div>

        {/* Hover-кнопки */}
        <div className="opacity-0 group-hover:opacity-100 flex items-center gap-1 transition-opacity shrink-0">
          <button
            onClick={onEdit}
            className="btn-ghost text-xs p-1"
            title="Редактировать"
          >
            <i className="bi bi-pencil" />
          </button>
          <button
            onClick={() => setDeletePending(true)}
            className="btn-ghost text-xs p-1 text-danger"
            title="Удалить"
          >
            <i className="bi bi-x-lg" />
          </button>
        </div>
      </div>

      {/* Inline confirm удаления */}
      {deletePending && (
        <div className="mt-1 p-2 bg-danger/10 border border-danger/30 rounded text-xs flex items-center justify-between gap-2">
          <span className="text-danger">Удалить автоматизацию?</span>
          <div className="flex gap-1">
            <button
              onClick={() => { setDeletePending(false); setDeleteError(null); }}
              className="btn-ghost text-xs py-0.5"
            >
              Нет, оставить
            </button>
            <button
              onClick={confirmDelete}
              className="btn-ghost text-xs py-0.5 text-danger"
            >
              Да, удалить
            </button>
          </div>
        </div>
      )}

      {deleteError && (
        <div className="mt-1 text-xs text-danger">{deleteError}</div>
      )}
    </div>
  );
}
