"use client";

import { useState } from "react";
import type { Deal, PipelineStage } from "@/lib/types";
import { formatCurrency } from "@/lib/format";
import {
  DropdownMenu,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuSub,
  DropdownMenuSubTrigger,
  DropdownMenuSubContent,
} from "@/components/ui/DropdownMenu";

interface KanbanCardProps {
  deal: Deal;
  stages: PipelineStage[];
  counterpartyName: string | undefined;
  userName: string | undefined;
  onMove: (dealId: number, stageId: number) => void;
  onOpen: (deal: Deal) => void;
  onDelete?: (dealId: number) => void;
}

function getCloseBadge(expectedClose: string | null | undefined): { text: string; cls: string } | null {
  if (!expectedClose) return null;
  const today = new Date();
  const closeDate = new Date(expectedClose);
  const daysLeft = Math.ceil((closeDate.getTime() - today.getTime()) / 86400000);
  if (daysLeft < 0) return { text: "просрочено", cls: "text-danger text-[10px]" };
  if (daysLeft <= 7) return { text: `${daysLeft} дн.`, cls: "text-warning text-[10px]" };
  return { text: closeDate.toLocaleDateString("ru-RU", { day: "numeric", month: "short" }), cls: "text-gray-400 text-[10px]" };
}

export function KanbanCard({ deal, stages, counterpartyName, userName, onMove, onOpen, onDelete }: KanbanCardProps) {
  const [menuOpen, setMenuOpen] = useState(false);

  const closeBadge = getCloseBadge(deal.expected_close_date);

  function handleOpenCard(e: React.MouseEvent) {
    e.stopPropagation();
    setMenuOpen(false);
    onOpen(deal);
  }

  return (
    <div
      draggable
      onDragStart={(e) => { e.dataTransfer.setData("dealId", String(deal.id)); }}
      className="relative group bg-white border border-gray-200 rounded-lg p-2 text-sm cursor-grab active:cursor-grabbing select-none"
    >
      {/* Kebab menu — Radix DropdownMenu */}
      <DropdownMenu
        open={menuOpen}
        onOpenChange={setMenuOpen}
        align="end"
        side="bottom"
        sideOffset={4}
        trigger={
          <button
            onClick={(e) => e.stopPropagation()}
            className="absolute top-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity p-0.5 rounded hover:bg-gray-100 text-gray-400"
            title="Действия"
          >
            <i className="bi bi-three-dots-vertical text-base" />
          </button>
        }
      >
        <DropdownMenuItem
          onSelect={() => { onOpen(deal); }}
        >
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

      {/* Card content */}
      <div className="cursor-pointer pr-5" onClick={handleOpenCard}>
        <div className="font-medium truncate">{deal.title}</div>
        {counterpartyName && <div className="text-xs text-gray-500 truncate">{counterpartyName}</div>}
        {deal.amount != null && (
          <div className="text-xs tabular-nums text-gray-700 mt-0.5">{formatCurrency(deal.amount, deal.currency)}</div>
        )}
        {userName && <div className="text-xs text-gray-400 mt-0.5 truncate">{userName}</div>}
        {closeBadge && (
          <div className={`mt-1 ${closeBadge.cls}`}>
            <i className="bi bi-calendar3 mr-1" />{closeBadge.text}
          </div>
        )}
      </div>
    </div>
  );
}
