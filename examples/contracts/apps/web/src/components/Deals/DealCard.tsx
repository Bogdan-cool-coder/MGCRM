"use client";

import { useState } from "react";
import type { BoardDealOut, PipelineStage, User } from "@/lib/types";
import { formatCurrency } from "@/lib/format";
import {
  DropdownMenu,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuSub,
  DropdownMenuSubTrigger,
  DropdownMenuSubContent,
} from "@/components/ui/DropdownMenu";

interface DealCardProps {
  deal: BoardDealOut;
  stages: PipelineStage[];
  /** Текущий этап сделки (для stage_features). Опционально — берём из stages по stage_id */
  currentStage?: PipelineStage | null;
  bulkMode?: boolean;
  selected?: boolean;
  /** Map userId → User, для отображения аватара ответственного */
  usersById?: Map<number, User>;
  onSelect?: (id: number, checked: boolean) => void;
  onMove: (dealId: number, stageId: number) => void;
  onOpen: (deal: BoardDealOut) => void;
  onDelete?: (dealId: number) => void;
  /** Спец-действия этапа */
  onMeetingReport?: (deal: BoardDealOut) => void;
  onSendPresentation?: (deal: BoardDealOut) => void;
  onGenerateDoc?: (deal: BoardDealOut) => void;
  onReturnToWork?: (deal: BoardDealOut) => void;
}

const TASK_KIND_ICONS: Record<string, string> = {
  call: "bi-telephone",
  meeting: "bi-calendar-event",
  task: "bi-check2-square",
  note: "bi-sticky",
};

function fmtTaskDate(due_at: string | null): string {
  if (!due_at) return "без срока";
  const d = new Date(due_at);
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const taskDay = new Date(d);
  taskDay.setHours(0, 0, 0, 0);
  const diffDays = Math.round((taskDay.getTime() - today.getTime()) / 86400000);
  if (diffDays === 0) return "сегодня";
  if (diffDays === 1) return "завтра";
  return d.toLocaleDateString("ru-RU", { day: "numeric", month: "short" });
}

/** Детерминированный цвет фона аватара по userId */
const AVATAR_COLORS = [
  "#2B4987", "#039855", "#DC6803", "#1570EF",
  "#D92D20", "#6941C6", "#026AA2", "#107569",
];
function avatarColor(userId: number): string {
  return AVATAR_COLORS[userId % AVATAR_COLORS.length];
}

/** Инициалы из полного имени: «Иван Иванов» → «ИИ» */
function initials(name: string): string {
  const parts = name.trim().split(/\s+/);
  if (parts.length === 0) return "?";
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

/**
 * Вычисляет accent-цвет left-полоски карточки по «температуре»:
 * 1. Просрочено (next_task.is_overdue) → danger-500
 * 2. expected_close_date < сегодня → danger-500
 * 3. stage.is_won → success-500
 * 4. stage.is_lost → danger-500
 * 5. Этап «горячий» (HOT/hot/warm/горяч) → warning-500
 * 6. stage.color (если задан) → использовать
 * 7. Fallback → info-500
 */
function getAccentColor(deal: BoardDealOut, stage: PipelineStage | null): string {
  const DANGER  = "#F04438";
  const SUCCESS = "#12B76A";
  const WARNING = "#F79009";
  const INFO    = "#2E90FA";

  if (deal.next_task?.is_overdue) return DANGER;

  if (deal.expected_close_date) {
    const closeDay = new Date(deal.expected_close_date);
    closeDay.setHours(0, 0, 0, 0);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    if (closeDay < today) return DANGER;
  }

  if (!stage) return INFO;

  if (stage.is_won) return SUCCESS;
  if (stage.is_lost) return DANGER;

  const nameLower = stage.name.toLowerCase();
  if (
    nameLower.includes("hot") ||
    nameLower.includes("горяч") ||
    nameLower.includes("warm") ||
    nameLower.includes("тёплый") ||
    nameLower.includes("теплый")
  ) return WARNING;

  return stage.color ?? INFO;
}

export function DealCard({
  deal,
  stages,
  currentStage,
  bulkMode,
  selected,
  usersById,
  onSelect,
  onMove,
  onOpen,
  onDelete,
  onMeetingReport,
  onSendPresentation,
  onGenerateDoc,
  onReturnToWork,
}: DealCardProps) {
  const [menuOpen, setMenuOpen] = useState(false);

  // Берём currentStage из props или находим по stage_id в списке stages
  const stage = currentStage ?? stages.find((s) => s.id === deal.stage_id) ?? null;
  const features: string[] = stage?.stage_features ?? [];

  const hasMeetingReport    = features.includes("meeting_report")    && !!onMeetingReport;
  const hasSendPresentation = features.includes("send_presentation") && !!onSendPresentation;
  const hasGenerateDoc      = features.includes("generate_document") && !!onGenerateDoc;
  const isLostStage         = stage?.is_lost ?? false;
  const hasReturnToWork     = isLostStage && !!onReturnToWork;
  const hasSpecialActions   = hasMeetingReport || hasSendPresentation || hasGenerateDoc || hasReturnToWork;

  // Left-accent цвет по «температуре»
  const accentColor = getAccentColor(deal, stage);

  // Просроченность — для бейджа
  const isOverdue = deal.next_task?.is_overdue ?? false;

  const taskIcon = deal.next_task
    ? (TASK_KIND_ICONS[deal.next_task.kind] ?? "bi-check2-square")
    : null;

  const daysLeft = deal.next_task?.due_at
    ? Math.round((new Date(deal.next_task.due_at).getTime() - Date.now()) / 86400000)
    : null;

  const deadlineChipClass = isOverdue
    ? "text-danger-600 dark:text-danger-500 font-semibold"
    : daysLeft !== null && daysLeft <= 3
    ? "text-warning-600 dark:text-warning-500 font-medium"
    : "text-gray-400 dark:text-gray-500";

  const deadlineIcon = isOverdue
    ? "bi-exclamation-circle"
    : daysLeft !== null && daysLeft <= 3
    ? "bi-clock-history"
    : "bi-calendar3";

  // Аватар ответственного
  const ownerUser = deal.owner_user_id != null
    ? (usersById?.get(deal.owner_user_id) ?? null)
    : null;

  function handleCardClick(e: React.MouseEvent) {
    if (bulkMode && onSelect) {
      e.stopPropagation();
      onSelect(deal.id, !selected);
      return;
    }
    e.stopPropagation();
    setMenuOpen(false);
    onOpen(deal);
  }

  function handleKeyDown(e: React.KeyboardEvent) {
    if (e.key === "Enter" || e.key === " ") {
      e.preventDefault();
      if (bulkMode && onSelect) {
        onSelect(deal.id, !selected);
      } else {
        onOpen(deal);
      }
    }
  }

  return (
    <div
      draggable={!bulkMode}
      tabIndex={0}
      onDragStart={(e) => {
        if (!bulkMode) {
          e.dataTransfer.setData("dealId", String(deal.id));
          e.currentTarget.dataset.dragging = "true";
        }
      }}
      onDragEnd={(e) => {
        e.currentTarget.dataset.dragging = "false";
      }}
      className={
        "relative group bg-white dark:bg-gray-800 border rounded-xl " +
        "shadow-elev-1 p-3 pl-4 text-sm select-none overflow-hidden " +
        (!bulkMode ? "kcard " : "cursor-pointer ") +
        (selected
          ? "border-primary ring-1 ring-primary shadow-elev-2"
          : "border-gray-200 dark:border-white/10 hover:shadow-elev-2")
      }
      onClick={handleCardClick}
      onKeyDown={handleKeyDown}
      role="button"
      aria-label={`Сделка: ${deal.title}`}
    >
      {/* Left-accent полоска по температуре */}
      <span
        className="absolute left-0 top-0 bottom-0 w-1 rounded-l-xl"
        style={{ backgroundColor: accentColor }}
        aria-hidden="true"
      />

      {/* Bulk checkbox */}
      {bulkMode && (
        <div className="absolute top-2 left-2 z-10" onClick={(e) => e.stopPropagation()}>
          <input
            type="checkbox"
            className="w-4 h-4 cursor-pointer"
            checked={!!selected}
            onChange={(e) => onSelect?.(deal.id, e.target.checked)}
          />
        </div>
      )}

      {/* Kebab menu — Radix DropdownMenu */}
      {!bulkMode && (
        <div
          className="absolute top-2 right-2 z-10"
          onClick={(e) => e.stopPropagation()}
        >
          <DropdownMenu
            open={menuOpen}
            onOpenChange={setMenuOpen}
            align="end"
            side="bottom"
            sideOffset={4}
            trigger={
              <button
                className="opacity-0 group-hover:opacity-100 transition-opacity p-0.5 rounded hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-400"
                title="Действия"
                aria-label="Действия со сделкой"
                aria-expanded={menuOpen}
              >
                <i className="bi bi-three-dots-vertical text-base" />
              </button>
            }
          >
            <DropdownMenuItem onSelect={() => { onOpen(deal); }}>
              <i className="bi bi-box-arrow-up-right text-gray-400" />
              Открыть карточку
            </DropdownMenuItem>

            {/* Вложенное подменю этапов */}
            <DropdownMenuSub>
              <DropdownMenuSubTrigger>
                <i className="bi bi-arrow-right-circle text-gray-400" />
                Перевести в этап
                <i className="bi bi-chevron-right text-gray-400 ml-auto text-xs" />
              </DropdownMenuSubTrigger>
              <DropdownMenuSubContent>
                {stages.map((s) => (
                  <DropdownMenuItem
                    key={s.id}
                    onSelect={() => { onMove(deal.id, s.id); setMenuOpen(false); }}
                    className={s.id === deal.stage_id ? "font-medium bg-gray-100 dark:bg-gray-700" : ""}
                  >
                    <span
                      className="w-2 h-2 rounded-full shrink-0"
                      style={{ backgroundColor: s.color ?? "#6B7A99" }}
                    />
                    {s.name}
                  </DropdownMenuItem>
                ))}
              </DropdownMenuSubContent>
            </DropdownMenuSub>

            {/* Спец-действия этапа */}
            {hasSpecialActions && (
              <>
                <DropdownMenuSeparator />
                {hasMeetingReport && (
                  <DropdownMenuItem onSelect={() => { onMeetingReport!(deal); }}>
                    <i className="bi bi-file-earmark-text text-gray-400" />
                    Отчёт по встрече
                  </DropdownMenuItem>
                )}
                {hasSendPresentation && (
                  <DropdownMenuItem onSelect={() => { onSendPresentation!(deal); }}>
                    <i className="bi bi-presentation text-gray-400" />
                    Отправить презентацию
                  </DropdownMenuItem>
                )}
                {hasGenerateDoc && (
                  <DropdownMenuItem onSelect={() => { onGenerateDoc!(deal); }}>
                    <i className="bi bi-file-earmark-plus text-gray-400" />
                    Создать КП / договор
                  </DropdownMenuItem>
                )}
                {hasReturnToWork && (
                  <DropdownMenuItem
                    onSelect={() => { onReturnToWork!(deal); }}
                    className="text-info"
                  >
                    <i className="bi bi-arrow-counterclockwise text-info" />
                    Вернуть в работу
                  </DropdownMenuItem>
                )}
              </>
            )}

            {onDelete && (
              <>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                  variant="danger"
                  onSelect={() => { onDelete(deal.id); }}
                >
                  <i className="bi bi-trash" />
                  Удалить
                </DropdownMenuItem>
              </>
            )}
          </DropdownMenu>
        </div>
      )}

      {/* Card content */}
      <div className={bulkMode ? "pl-6 pr-1" : "pr-5"}>
        {/* Overdue badge */}
        {isOverdue && (
          <div className="mb-1.5">
            <span className="text-[10px] font-semibold bg-danger-50 text-danger-700 dark:bg-danger-500/15 dark:text-danger-500 rounded px-1.5 py-0.5">
              просрочено
            </span>
          </div>
        )}

        {/* Deal title */}
        <div className="font-medium text-sm truncate text-gray-900 dark:text-gray-100">
          {deal.title}
        </div>

        {/* Product badge */}
        {deal.product && (
          <span className="inline-block mt-0.5 px-1.5 py-0.5 text-[10px] rounded bg-info/10 text-primary dark:bg-info/20 dark:text-blue-300 font-medium">
            {deal.product}
          </span>
        )}

        {/* Amount */}
        {deal.amount != null && (
          <div className="text-sm font-semibold tabular-nums mt-1.5 text-gray-900 dark:text-gray-100">
            {formatCurrency(deal.amount, deal.currency)}
          </div>
        )}

        {/* Tags */}
        {deal.tags && deal.tags.length > 0 && (
          <div className="flex flex-wrap gap-1 mt-1">
            {deal.tags.slice(0, 3).map((tag) => (
              <span
                key={tag}
                className="px-1.5 py-0.5 text-[10px] rounded bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400"
              >
                {tag}
              </span>
            ))}
            {deal.tags.length > 3 && (
              <span className="text-[10px] text-gray-400">+{deal.tags.length - 3}</span>
            )}
          </div>
        )}

        {/* Next task */}
        {deal.next_task && (
          <div className={`flex items-center gap-1 mt-1.5 text-xs ${deadlineChipClass}`}>
            {taskIcon && <i className={`bi ${taskIcon} shrink-0`} />}
            <span className="truncate">{deal.next_task.title}</span>
            <span className="shrink-0 ml-auto tabular-nums">
              {fmtTaskDate(deal.next_task.due_at)}
            </span>
          </div>
        )}

        {/* Footer: avatar + deadline chip */}
        <div className="flex items-center justify-between mt-2.5">
          {/* Avatar ответственного */}
          {ownerUser != null ? (
            <div
              className="h-6 w-6 rounded-full text-white grid place-items-center text-[10px] font-semibold shrink-0"
              style={{ backgroundColor: avatarColor(ownerUser.id) }}
              title={ownerUser.full_name}
              aria-label={ownerUser.full_name}
            >
              {initials(ownerUser.full_name)}
            </div>
          ) : (
            <span />
          )}

          {/* Deadline chip */}
          {deal.next_task && (
            <span className={`text-[11px] inline-flex items-center gap-1 ${deadlineChipClass}`}>
              <i className={`bi ${deadlineIcon} shrink-0`} />
              {fmtTaskDate(deal.next_task.due_at)}
            </span>
          )}
        </div>
      </div>
    </div>
  );
}
