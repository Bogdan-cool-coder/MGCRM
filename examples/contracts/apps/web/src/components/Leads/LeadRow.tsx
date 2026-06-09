"use client";

import { SourceBadge } from "./SourceBadge";
import { LeadScoreIndicator } from "./LeadScoreIndicator";
import { LEAD_STATUS_LABELS, type Lead, type LeadStatus, type PipelineStage } from "@/lib/types";

/** Soft-badge классы для статусов лида (используют дизайн-токены проекта) */
const STATUS_CLS: Record<LeadStatus, string> = {
  active:    "badge-info",
  converted: "badge-success",
  archived:  "badge-neutral",
  lost:      "badge-danger",
};

interface LeadRowProps {
  lead: Lead;
  stage: PipelineStage | undefined;
  ownerName: string;
  /** Переход на детальную страницу лида */
  onView: (lead: Lead) => void;
  /** Открыть модалку редактирования */
  onEdit: (lead: Lead) => void;
  onConvert: (lead: Lead) => void;
  onDelete: (lead: Lead) => void;
  canDelete: boolean;
}

export function LeadRow({ lead, stage, ownerName, onView, onEdit, onConvert, onDelete, canDelete }: LeadRowProps) {
  const isConverted = lead.status === "converted";
  return (
    <tr
      className={[
        "group border-b border-gray-100 dark:border-gray-800",
        "transition-colors duration-100 cursor-pointer",
        "hover:bg-primary/[0.03] dark:hover:bg-primary/[0.06]",
      ].join(" ")}
      onClick={() => onView(lead)}
    >
      <td className="px-4 py-2.5 font-medium text-gray-900 dark:text-gray-100">
        <div className="flex items-center gap-1.5">
          <span className="truncate">{lead.name}</span>
          <LeadScoreIndicator score={lead.score} />
        </div>
        {(lead.contact_email || lead.contact_phone) && (
          <div className="text-xs text-gray-500 dark:text-gray-400 truncate">
            {lead.contact_email ?? lead.contact_phone}
          </div>
        )}
      </td>
      <td className="px-4 py-2.5"><SourceBadge source={lead.source} /></td>
      <td className="px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300">
        {stage ? (
          <span className="inline-flex items-center gap-1.5">
            <span
              className="w-2 h-2 rounded-full shrink-0"
              style={{ backgroundColor: stage.color || "#6B7A99" }}
            />
            {stage.name}
          </span>
        ) : "—"}
      </td>
      <td className="px-4 py-2.5 text-sm text-gray-600 dark:text-gray-400">
        {ownerName || "—"}
      </td>
      <td className="px-4 py-2.5">
        <span className={STATUS_CLS[lead.status]}>
          {LEAD_STATUS_LABELS[lead.status]}
        </span>
      </td>
      {/* Действия — появляются при hover через group */}
      <td className="px-4 py-2.5 text-right whitespace-nowrap">
        <div className="opacity-0 group-hover:opacity-100 transition-opacity duration-100 inline-flex items-center gap-2">
          <button
            onClick={(e) => { e.stopPropagation(); onEdit(lead); }}
            title="Редактировать"
            className="btn-ghost p-1 text-gray-400 hover:text-primary"
          >
            <i className="bi bi-pencil text-sm" />
          </button>
          {!isConverted && (
            <button
              onClick={(e) => { e.stopPropagation(); onConvert(lead); }}
              title="Сконвертировать в сделку"
              className="btn-ghost p-1 text-gray-400 hover:text-primary"
            >
              <i className="bi bi-arrow-right-circle text-sm" />
            </button>
          )}
          {canDelete && (
            <button
              onClick={(e) => { e.stopPropagation(); onDelete(lead); }}
              title="Удалить лид"
              className="btn-ghost p-1 text-gray-400 hover:text-danger"
            >
              <i className="bi bi-trash text-sm" />
            </button>
          )}
        </div>
      </td>
    </tr>
  );
}
