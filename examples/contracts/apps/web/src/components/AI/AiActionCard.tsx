"use client";

import type { AIAssistantActionType, AIAssistantProposedAction } from "@/lib/types";

const ICON_BY_TYPE: Record<AIAssistantActionType, string> = {
  create_task: "bi-check2-square",
  create_deal: "bi-kanban",
  create_contract: "bi-file-earmark-text",
};

interface Props {
  action: AIAssistantProposedAction;
  status: "pending" | "confirmed" | "cancelled";
  confirming: boolean;
  onConfirm: () => void;
  onCancel: () => void;
}

/**
 * Карточка подтверждения предложенного AI действия (создать задачу/сделку/договор).
 * Рендерится внутри потока чата как ассистентский блок.
 */
export function AiActionCard({ action, status, confirming, onConfirm, onCancel }: Props) {
  const icon = ICON_BY_TYPE[action.type] ?? "bi-stars";

  return (
    <div className="flex justify-start">
      <div className="max-w-[90%] w-full">
        <div className="card border border-primary/30 dark:border-primary-light/40 p-3 space-y-2">
          <div className="flex items-start gap-2">
            <i className={`bi ${icon} text-primary text-lg mt-0.5`} />
            <div className="min-w-0">
              <div className="text-sm font-semibold text-gray-900 dark:text-white">
                {action.title}
              </div>
              <p className="text-xs text-gray-600 dark:text-gray-300 mt-0.5 whitespace-pre-line">
                {action.summary}
              </p>
            </div>
          </div>

          {status === "pending" && (
            <div className="flex gap-2 pt-1">
              <button
                onClick={onConfirm}
                className="btn-primary text-xs flex-1"
                disabled={confirming}
              >
                <i className="bi bi-check-lg mr-1" />
                {confirming ? "Создание…" : "Создать"}
              </button>
              <button
                onClick={onCancel}
                className="btn-secondary text-xs flex-1"
                disabled={confirming}
              >
                Отмена
              </button>
            </div>
          )}

          {status === "confirmed" && (
            <div className="flex items-center gap-1 text-xs text-success pt-1">
              <i className="bi bi-check-circle-fill" />
              <span>Создано</span>
            </div>
          )}

          {status === "cancelled" && (
            <div className="flex items-center gap-1 text-xs text-gray-400 pt-1">
              <i className="bi bi-x-circle" />
              <span>Отменено</span>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
