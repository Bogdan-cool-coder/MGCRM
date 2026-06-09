"use client";

import React, { useMemo, useState, useRef, useEffect } from "react";
import { useRouter } from "next/navigation";
import { DealCard } from "./DealCard";
import { formatCurrency } from "@/lib/format";
import type { Board, BoardDealOut, PipelineStage, User } from "@/lib/types";

// ── helpers ──────────────────────────────────────────────────────────────────

/** Форматирует сумму колонки: «4 100 000 ₽» → «4,1 млн ₽» при ≥ 1 000 000. */
function fmtColAmount(raw: string): string {
  return raw;
}

/**
 * Определяет семантику колонки по признакам этапа и его названию/цвету.
 * Возвращает один из: "won" | "lost" | "hot" | "neutral"
 */
function colSemantic(stage: PipelineStage): "won" | "lost" | "hot" | "neutral" {
  if (stage.is_won) return "won";
  if (stage.is_lost) return "lost";
  const nameLower = stage.name.toLowerCase();
  if (
    nameLower.includes("hot") ||
    nameLower.includes("горяч") ||
    nameLower.includes("warm") ||
    nameLower.includes("тёплый") ||
    nameLower.includes("теплый")
  ) return "hot";
  // Эвристика по цвету: если цвет близок к warning/orange
  if (stage.color) {
    const c = stage.color.toLowerCase();
    if (
      c === "#f79009" || c === "#dc6803" || c === "#b54708" ||
      c === "#fec84b" || c === "#f59e0b" || c === "#d97706" ||
      c === "#ff6b00" || c === "#ff8c00" || c === "#ffa500"
    ) return "hot";
  }
  return "neutral";
}

/** CSS-классы обёртки колонки по семантике */
function colWrapperClass(sem: ReturnType<typeof colSemantic>): string {
  switch (sem) {
    case "won":
      return "bg-success-50/40 dark:bg-success-500/[.04] border border-success-500/25";
    case "lost":
      return "bg-danger-50/40 dark:bg-danger-500/[.04] border border-danger-500/25";
    case "hot":
      return "bg-warning-50/50 dark:bg-warning-500/[.05] border border-warning-500/30";
    default:
      return "bg-gray-50 dark:bg-white/[.03] border border-gray-200 dark:border-white/10";
  }
}

/**
 * Вычисляет inline-стили для sticky-заголовка колонки.
 * Используем stage.color напрямую — мягкий фон (20% opacity) с читаемым текстом.
 * Для специальных семантик (won/lost/hot) берём фиксированные цвета.
 */
function colHeaderStyle(
  sem: ReturnType<typeof colSemantic>,
  stage: PipelineStage
): React.CSSProperties {
  if (sem === "won")  return { backgroundColor: "rgba(18, 183, 106, 0.10)", borderBottom: "1px solid rgba(18, 183, 106, 0.20)" };
  if (sem === "lost") return { backgroundColor: "rgba(240, 68, 56, 0.08)",  borderBottom: "1px solid rgba(240, 68, 56, 0.18)" };
  if (sem === "hot")  return { backgroundColor: "rgba(247, 144, 9, 0.10)",  borderBottom: "1px solid rgba(247, 144, 9, 0.20)" };
  // Нейтральный — используем stage.color с opacity
  const base = stage.color ?? "#6B7A99";
  // hex → rgba helper (поддерживаем только 6-char hex)
  const r = parseInt(base.slice(1, 3), 16);
  const g = parseInt(base.slice(3, 5), 16);
  const b = parseInt(base.slice(5, 7), 16);
  const isValidHex = base.startsWith("#") && base.length === 7 && !isNaN(r) && !isNaN(g) && !isNaN(b);
  if (isValidHex) {
    return {
      backgroundColor: `rgba(${r}, ${g}, ${b}, 0.12)`,
      borderBottom: `1px solid rgba(${r}, ${g}, ${b}, 0.25)`,
    };
  }
  return { backgroundColor: "rgba(107, 122, 153, 0.10)", borderBottom: "1px solid rgba(107, 122, 153, 0.20)" };
}

/**
 * Цвет текста заголовка колонки по семантике / stage.color.
 * Для тёмной темы всегда светлее — используем CSS переменные через класс.
 */
function colHeaderTextStyle(
  sem: ReturnType<typeof colSemantic>,
  stage: PipelineStage
): React.CSSProperties {
  if (sem === "won")  return { color: "#027A48" };
  if (sem === "lost") return { color: "#B42318" };
  if (sem === "hot")  return { color: "#B54708" };
  const base = stage.color ?? "#2B4987";
  return { color: base };
}

/** Иконка / точка для заголовка колонки по семантике */
function ColIcon({ sem, stage }: { sem: ReturnType<typeof colSemantic>; stage: PipelineStage }) {
  if (sem === "won") {
    return <i className="bi bi-check-circle-fill text-sm shrink-0" aria-hidden="true" />;
  }
  if (sem === "lost") {
    return <i className="bi bi-x-circle-fill text-sm shrink-0" aria-hidden="true" />;
  }
  if (sem === "hot") {
    return <i className="bi bi-fire text-sm shrink-0" aria-hidden="true" />;
  }
  // Нейтральный: цветная точка по stage.color
  const dotColor = stage.color ?? "#2E90FA";
  return (
    <span
      className="h-2 w-2 rounded-full shrink-0"
      style={{ backgroundColor: dotColor }}
      aria-hidden="true"
    />
  );
}

// ── KanbanColumn ─────────────────────────────────────────────────────────────

interface ColumnProps {
  stage: PipelineStage;
  deals: BoardDealOut[];
  allStages: PipelineStage[];
  subStages: PipelineStage[];
  bulkMode: boolean;
  selectedIds: number[];
  usersById: Map<number, User>;
  onSelect: (id: number, checked: boolean) => void;
  onMove: (dealId: number, stageId: number) => void;
  onOpen: (deal: BoardDealOut) => void;
  onDelete: (dealId: number) => void;
  onMeetingReport: (deal: BoardDealOut) => void;
  onSendPresentation: (deal: BoardDealOut) => void;
  onGenerateDoc: (deal: BoardDealOut) => void;
  onReturnToWork: (deal: BoardDealOut) => void;
}

function KanbanColumn({
  stage,
  deals,
  allStages,
  subStages,
  bulkMode,
  selectedIds,
  usersById,
  onSelect,
  onMove,
  onOpen,
  onDelete,
  onMeetingReport,
  onSendPresentation,
  onGenerateDoc,
  onReturnToWork,
}: ColumnProps) {
  const isWon = stage.is_won;
  const sem = colSemantic(stage);

  // Drag-over state для placeholder
  const [isDragOver, setIsDragOver] = useState(false);

  // Group deals by substage for won column
  const wonBySubstage = useMemo(() => {
    if (!isWon || subStages.length === 0) return null;
    const map: Record<number, BoardDealOut[]> = {};
    const ungrouped: BoardDealOut[] = [];
    for (const d of deals) {
      const matchSub = subStages.find((s) => s.id === d.stage_id);
      if (matchSub) {
        if (!map[matchSub.id]) map[matchSub.id] = [];
        map[matchSub.id].push(d);
      } else {
        ungrouped.push(d);
      }
    }
    return { map, ungrouped };
  }, [deals, subStages, isWon]);

  // Column amount summary
  const colAmount = useMemo(() => {
    const withAmount = deals.filter((d) => d.amount != null);
    if (withAmount.length === 0) return "";
    const currencies = new Set(withAmount.map((d) => d.currency).filter(Boolean));
    if (currencies.size > 1) return "—";
    const sum = withAmount.reduce((acc, d) => acc + Number(d.amount), 0);
    const cur = [...currencies][0] ?? null;
    return formatCurrency(sum, cur);
  }, [deals]);

  function renderCard(d: BoardDealOut, cardStage?: PipelineStage) {
    return (
      <DealCard
        key={d.id}
        deal={d}
        stages={allStages}
        currentStage={cardStage ?? stage}
        bulkMode={bulkMode}
        selected={selectedIds.includes(d.id)}
        usersById={usersById}
        onSelect={onSelect}
        onMove={onMove}
        onOpen={onOpen}
        onDelete={onDelete}
        onMeetingReport={onMeetingReport}
        onSendPresentation={onSendPresentation}
        onGenerateDoc={onGenerateDoc}
        onReturnToWork={onReturnToWork}
      />
    );
  }

  return (
    <div
      className={
        "w-[300px] flex-shrink-0 flex flex-col rounded-2xl " +
        colWrapperClass(sem)
      }
    >
      {/* Sticky column header — весь фон в цвет этапа */}
      <div
        className="sticky top-0 px-4 py-3 rounded-t-2xl backdrop-blur z-10"
        style={colHeaderStyle(sem, stage)}
      >
        <div className="flex items-center gap-2 min-w-0" style={colHeaderTextStyle(sem, stage)}>
          <ColIcon sem={sem} stage={stage} />
          <span className="font-semibold text-sm truncate">
            {stage.name}
          </span>
          <span className="text-xs shrink-0 opacity-60">
            {deals.length}
          </span>
        </div>

        {/* Column total amount */}
        {colAmount && (
          <div
            className="mt-1 text-xs tabular-nums font-medium opacity-75"
            style={colHeaderTextStyle(sem, stage)}
          >
            Σ {fmtColAmount(colAmount)}
          </div>
        )}
      </div>

      {/* Column body */}
      <div
        className={
          "flex-1 overflow-y-auto p-2.5 space-y-2.5 " +
          "scrollbar-thin scrollbar-thumb-gray-200 dark:scrollbar-thumb-white/10"
        }
        style={{ overscrollBehavior: "contain" }}
        onDragOver={(e) => {
          e.preventDefault();
          setIsDragOver(true);
        }}
        onDragLeave={(e) => {
          // Только если выходим за пределы колонки, не в дочерний элемент
          if (!e.currentTarget.contains(e.relatedTarget as Node | null)) {
            setIsDragOver(false);
          }
        }}
        onDrop={(e) => {
          setIsDragOver(false);
          const did = Number(e.dataTransfer.getData("dealId"));
          if (did) onMove(did, stage.id);
        }}
      >
        {/* Won column with substages */}
        {isWon && wonBySubstage ? (
          <>
            {wonBySubstage.ungrouped.map((d) => renderCard(d))}
            {subStages.map((sub) => {
              const subDeals = wonBySubstage.map[sub.id] ?? [];
              return (
                <div key={sub.id}>
                  <div
                    className="text-[10px] font-semibold uppercase tracking-wider px-1 py-1 rounded mb-1"
                    style={{ color: sub.color ?? "#6B7A99" }}
                  >
                    {sub.name} · {subDeals.length}
                  </div>
                  <div
                    className="space-y-1.5 pl-1 border-l-2"
                    style={{ borderColor: sub.color ?? "#6B7A99" }}
                    onDragOver={(e) => e.preventDefault()}
                    onDrop={(e) => {
                      e.stopPropagation();
                      const did = Number(e.dataTransfer.getData("dealId"));
                      if (did) onMove(did, sub.id);
                    }}
                  >
                    {subDeals.map((d) => renderCard(d, sub))}
                    {subDeals.length === 0 && (
                      <div className="text-xs text-gray-400 dark:text-gray-500 py-1 px-1">Пусто</div>
                    )}
                  </div>
                </div>
              );
            })}
          </>
        ) : (
          deals.map((d) => renderCard(d))
        )}

        {/* Empty column state — не в won-колонке */}
        {deals.length === 0 && !isWon && !isDragOver && (
          <div className="flex flex-col items-center justify-center py-8 text-gray-400 dark:text-gray-500">
            <i className="bi bi-inbox text-2xl mb-2" aria-hidden="true" />
            <span className="text-xs">Нет сделок</span>
          </div>
        )}

        {/* Drag placeholder — всегда в конце при dragOver */}
        {isDragOver && (
          <div
            className={
              "rounded-xl h-[92px] border-2 border-dashed " +
              "border-gray-300 dark:border-white/20 " +
              "bg-gray-50/50 dark:bg-white/5 " +
              "grid place-items-center text-xs text-gray-400 dark:text-gray-500 " +
              "transition-opacity duration-base"
            }
            aria-hidden="true"
          >
            отпустите здесь
          </div>
        )}
      </div>
    </div>
  );
}

// ── DealKanbanView ───────────────────────────────────────────────────────────

interface Props {
  board: Board;
  bulkMode: boolean;
  selectedIds: number[];
  usersById?: Map<number, User>;
  onSelect: (id: number, checked: boolean) => void;
  onMove: (dealId: number, stageId: number) => void;
  onDelete: (dealId: number) => void;
  onMeetingReport: (deal: BoardDealOut) => void;
  onSendPresentation: (deal: BoardDealOut) => void;
  onGenerateDoc: (deal: BoardDealOut) => void;
  onReturnToWork: (deal: BoardDealOut) => void;
}

export function DealKanbanView({
  board,
  bulkMode,
  selectedIds,
  usersById,
  onSelect,
  onMove,
  onDelete,
  onMeetingReport,
  onSendPresentation,
  onGenerateDoc,
  onReturnToWork,
}: Props) {
  const router = useRouter();

  // Ref на внешний контейнер доски для горизонтального скролла
  const boardRef = useRef<HTMLDivElement>(null);

  // Обработчик wheel: горизонтальный жест (deltaX или Shift+deltaY) скроллит доску.
  // Используем native addEventListener с passive:false, т.к. React synthetic wheel passive
  // и не позволяет вызвать preventDefault() для блокировки вертикального скролла страницы.
  useEffect(() => {
    const el = boardRef.current;
    if (!el) return;

    function handleWheel(e: WheelEvent) {
      if (!el) return;
      const absX = Math.abs(e.deltaX);
      const absY = Math.abs(e.deltaY);

      // Горизонтальный жест (трекпад-свайп или Shift+scroll):
      // если deltaX доминирует, либо Shift зажат — скроллим доску по горизонтали.
      if (absX > absY || e.shiftKey) {
        e.preventDefault();
        el.scrollLeft += e.shiftKey && absX === 0 ? e.deltaY : e.deltaX;
      }
      // При вертикальном жесте — не вмешиваемся, колонка скроллится сама.
    }

    el.addEventListener("wheel", handleWheel, { passive: false });
    return () => el.removeEventListener("wheel", handleWheel);
  }, []);

  // Fallback empty map if not provided
  const resolvedUsersById = useMemo<Map<number, User>>(
    () => usersById ?? new Map<number, User>(),
    [usersById]
  );

  // Flat list of all visible stages (including sub-stages — needed for DealCard move popover)
  const allStages = useMemo(() => board.columns.map((c) => c.stage), [board]);

  // Top-level columns: exclude sub-stages (parent_stage_id != null).
  const topLevelColumns = useMemo(
    () => board.columns.filter((c) => !c.stage.parent_stage_id),
    [board]
  );

  // Index of sub-stage columns by parent stage id for deal aggregation
  const subColsByParent = useMemo(() => {
    const map: Record<number, BoardDealOut[]> = {};
    for (const col of board.columns) {
      const pid = col.stage.parent_stage_id;
      if (pid != null) {
        if (!map[pid]) map[pid] = [];
        map[pid].push(...col.deals);
      }
    }
    return map;
  }, [board]);

  function handleOpen(deal: BoardDealOut) {
    router.push(`/deals/${deal.id}`);
  }

  return (
    /* Горизонтальный скролл доски.
       overscrollBehavior убран с внешнего контейнера — горизонтальный wheel
       теперь обрабатывается нативным обработчиком выше, vertical bubble не нужен. */
    <div
      ref={boardRef}
      className="overflow-x-auto h-full"
    >
    <div className="flex gap-4 min-w-min p-6">
      {topLevelColumns.map((col) => {
        const subStages = col.stage.is_won
          ? allStages.filter((s) => s.parent_stage_id === col.stage.id)
          : [];

        const colDeals =
          col.stage.is_won && subStages.length > 0
            ? [...col.deals, ...(subColsByParent[col.stage.id] ?? [])]
            : col.deals;

        return (
          <KanbanColumn
            key={col.stage.id}
            stage={col.stage}
            deals={colDeals}
            allStages={allStages}
            subStages={subStages}
            bulkMode={bulkMode}
            selectedIds={selectedIds}
            usersById={resolvedUsersById}
            onSelect={onSelect}
            onMove={onMove}
            onOpen={handleOpen}
            onDelete={onDelete}
            onMeetingReport={onMeetingReport}
            onSendPresentation={onSendPresentation}
            onGenerateDoc={onGenerateDoc}
            onReturnToWork={onReturnToWork}
          />
        );
      })}
    </div>
    </div>
  );
}
