"use client";

import React from "react";
import { SourceBadge } from "./SourceBadge";
import { LeadScoreIndicator } from "./LeadScoreIndicator";
import { LEAD_STATUS_LABELS, type Lead, type PipelineStage } from "@/lib/types";

interface LeadCardProps {
  lead: Lead;
  stages: PipelineStage[];
  ownerName: string;
  /** Переход на детальную страницу лида */
  onView: (lead: Lead) => void;
  /** Открыть модалку редактирования */
  onEdit: (lead: Lead) => void;
  onMove: (leadId: number, stageId: number) => void;
  onConvert: (lead: Lead) => void;
}

function LeadCardBase({ lead, stages, ownerName, onView, onEdit, onMove, onConvert }: LeadCardProps) {
  const isConverted = lead.status === "converted";
  const isLost = lead.status === "lost";
  const isArchived = lead.status === "archived";
  const dimmed = isConverted || isLost || isArchived;

  return (
    <div
      draggable={!dimmed}
      onDragStart={(e) => {
        e.dataTransfer.setData("leadId", String(lead.id));
      }}
      className={`bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-2 text-sm ${dimmed ? "opacity-60" : "cursor-grab active:cursor-grabbing"}`}
    >
      <div className="cursor-pointer" onClick={() => onView(lead)}>
        <div className="flex items-start justify-between gap-1">
          <div className="font-medium truncate flex-1 min-w-0 text-gray-900 dark:text-gray-100" title={lead.name}>{lead.name}</div>
          <button
            onClick={(e) => { e.stopPropagation(); onEdit(lead); }}
            className="text-gray-400 hover:text-primary shrink-0 -mt-0.5"
            title="Редактировать"
          >
            <i className="bi bi-pencil text-xs" />
          </button>
        </div>
        <div className="mt-1 flex items-center gap-1 flex-wrap">
          <SourceBadge source={lead.source} />
          <LeadScoreIndicator score={lead.score} />
          {lead.status !== "active" && (
            <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] uppercase tracking-wide bg-gray-100 text-gray-600">
              {LEAD_STATUS_LABELS[lead.status]}
            </span>
          )}
        </div>
        {(lead.contact_email || lead.contact_phone) && (
          <div className="text-xs text-gray-500 mt-1 truncate">
            {lead.contact_email ?? lead.contact_phone}
          </div>
        )}
        {ownerName && <div className="text-xs text-gray-400 mt-0.5 truncate">{ownerName}</div>}
        {lead.tags.length > 0 && (
          <div className="mt-1 flex flex-wrap gap-1">
            {lead.tags.slice(0, 3).map((t) => (
              <span key={t} className="text-[10px] bg-gray-100 text-gray-700 px-1.5 py-0.5 rounded">
                {t}
              </span>
            ))}
            {lead.tags.length > 3 && (
              <span className="text-[10px] text-gray-500">+{lead.tags.length - 3}</span>
            )}
          </div>
        )}
      </div>

      <div className="mt-2 flex gap-1">
        <select
          className="input text-xs py-1 flex-1"
          value={lead.stage_id}
          onClick={(e) => e.stopPropagation()}
          onChange={(e) => onMove(lead.id, Number(e.target.value))}
          disabled={dimmed}
        >
          {stages.map((s) => (
            <option key={s.id} value={s.id}>
              → {s.name}
            </option>
          ))}
        </select>
        {!isConverted && (
          <button
            className="btn-secondary text-xs px-2 py-1"
            onClick={(e) => {
              e.stopPropagation();
              onConvert(lead);
            }}
            title="Сконвертировать в сделку"
          >
            <i className="bi bi-arrow-right-circle" />
          </button>
        )}
      </div>
    </div>
  );
}

export const LeadCard = React.memo(LeadCardBase);
