"use client";

import { useEffect, useMemo, useState } from "react";
import useSWR, { mutate as globalMutate } from "swr";
import { useRouter, useSearchParams } from "next/navigation";
import { PageHeader } from "@/components/PageHeader";
import { EmptyState } from "@/components/EmptyState";
import { DealFiltersBar, DEFAULT_DEAL_FILTERS, type DealFilters } from "@/components/Deals/DealFiltersBar";
import { DealContextMenu } from "@/components/Deals/DealContextMenu";
import { DealBulkActionsBar } from "@/components/Deals/DealBulkActionsBar";
import { DealKanbanView } from "@/components/Deals/DealKanbanView";
import { DealListView } from "@/components/Deals/DealListView";
import { DealTaskView } from "@/components/Deals/DealTaskView";
import { LostReasonModal } from "@/components/Deals/LostReasonModal";
import { SuccessGateModal } from "@/components/Deals/SuccessGateModal";
import { MeetingReportModal } from "@/components/Deals/MeetingReportModal";
import { PresentationSendModal } from "@/components/Deals/PresentationSendModal";
import { Modal } from "@/components/Modal";
import { api, ApiError, fetcher } from "@/lib/api";
import { useMe } from "@/lib/auth";
import type {
  Board,
  BoardDealOut,
  DealListRow,
  Pipeline,
  PipelineStage,
  User,
  WinGateFailedError,
} from "@/lib/types";

// ── view types ───────────────────────────────────────────────────────────────
type ViewMode = "kanban" | "list" | "tasks";

// ── filter → querystring ─────────────────────────────────────────────────────
function filtersToQs(pid: number, f: DealFilters): string {
  const p = new URLSearchParams({ pipeline_id: String(pid) });
  if (f.q) p.set("q", f.q);
  if (f.owner_id) p.set("owner_id", f.owner_id);
  if (f.city) p.set("city", f.city);
  if (f.country) p.set("country", f.country);
  if (f.source) p.set("source", f.source);
  if (f.product) p.set("product", f.product);
  if (f.tag) p.set("tag", f.tag);
  if (f.task_kind) p.set("task_kind", f.task_kind);
  if (f.no_tasks) p.set("no_tasks", "true");
  if (f.amount_from) p.set("amount_from", f.amount_from);
  if (f.amount_to) p.set("amount_to", f.amount_to);
  if (f.show_lost) p.set("show_lost", "true");
  if (f.show_cold) p.set("show_cold", "true");
  return p.toString();
}

// ── page component ───────────────────────────────────────────────────────────
export default function DealsPage() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const { user } = useMe();

  // ── pipeline selection ───────────────────────────────────────────────────
  const { data: allPipelines } = useSWR<Pipeline[]>("/pipelines", fetcher);
  const pipelines = useMemo(
    () => (allPipelines ?? []).filter((p) => p.kind !== "lifecycle" && p.is_active),
    [allPipelines]
  );

  // Дефолтная воронка: сначала kind='sales', потом остальные
  const defaultPipeline = useMemo(() => {
    if (pipelines.length === 0) return null;
    return pipelines.find((p) => p.kind === "sales") ?? pipelines[0];
  }, [pipelines]);

  const [pid, setPid] = useState<number | null>(null);
  useEffect(() => {
    if (pid == null && defaultPipeline) setPid(defaultPipeline.id);
  }, [defaultPipeline, pid]);

  // ── views + filters ──────────────────────────────────────────────────────
  const [view, setView] = useState<ViewMode>("kanban");
  // Deep-link из дашборда: /deals?no_tasks=true (Wave 2b)
  const [filters, setFilters] = useState<DealFilters>(() => ({
    ...DEFAULT_DEAL_FILTERS,
    no_tasks: searchParams.get("no_tasks") === "true",
  }));
  const [bulkMode, setBulkMode] = useState(false);
  const [selectedIds, setSelectedIds] = useState<number[]>([]);

  // ── board data (kanban) ──────────────────────────────────────────────────
  const boardKey = useMemo(
    () => (pid ? `/deals/board?${filtersToQs(pid, filters)}` : null),
    [pid, filters]
  );
  const { data: board, mutate: mutateBoard } = useSWR<Board>(boardKey, fetcher);

  // Fallback: если выбранная воронка вернула 0 колонок, переключаемся на следующую
  useEffect(() => {
    if (!board || board.columns.length > 0) return;
    if (pipelines.length <= 1) return;
    const fallback = pipelines.find((p) => p.id !== pid && p.kind === "sales")
      ?? pipelines.find((p) => p.id !== pid);
    if (fallback) setPid(fallback.id);
  }, [board, pid, pipelines]);

  // ── list data (list view) ────────────────────────────────────────────────
  const listKey = useMemo(
    () => (pid && view === "list" ? `/deals/list?${filtersToQs(pid, filters)}&limit=200` : null),
    [pid, view, filters]
  );
  const { data: listRows } = useSWR<DealListRow[]>(listKey, fetcher);

  // ── users (for list view owner name + kanban avatar) ────────────────────
  const { data: users } = useSWR<User[]>("/users", fetcher);

  // Map userId → User для быстрого lookup в DealCard аватаре
  const usersById = useMemo<Map<number, User>>(() => {
    const m = new Map<number, User>();
    for (const u of users ?? []) m.set(u.id, u);
    return m;
  }, [users]);

  // ── stages flat list (from board) ───────────────────────────────────────
  const allStages = useMemo<PipelineStage[]>(
    () => board?.columns.map((c) => c.stage) ?? [],
    [board]
  );

  // ── modals ───────────────────────────────────────────────────────────────
  const [lostTarget, setLostTarget] = useState<{ dealId: number; stageId: number } | null>(null);
  const [winGateTarget, setWinGateTarget] = useState<{
    dealId: number;
    stageId: number;
    gateInfo: WinGateFailedError;
    dealAmount: number | null;
    dealCurrency: string | null;
  } | null>(null);
  const [meetingReportDealId, setMeetingReportDealId] = useState<number | null>(null);
  const [presentationDeal, setPresentationDeal] = useState<BoardDealOut | null>(null);
  const [dupOpen, setDupOpen] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // ── спец-действия этапа ─────────────────────────────────────────────────
  function handleMeetingReport(deal: BoardDealOut) {
    setMeetingReportDealId(deal.id);
  }

  function handleSendPresentation(deal: BoardDealOut) {
    setPresentationDeal(deal);
  }

  function handleGenerateDoc(deal: BoardDealOut) {
    // BUG-2 fix: прокидываем deal_id, чтобы созданный договор привязался к сделке
    // (deal.contract_id) и win-gate не блокировал «Успех».
    if (deal.company_id) {
      router.push(`/contracts/new?company_id=${deal.company_id}&deal_id=${deal.id}`);
    } else {
      router.push(`/contracts/new?deal_id=${deal.id}`);
    }
  }

  async function handleReturnToWork(deal: BoardDealOut) {
    // Возвращаем в первый рабочий (не is_won, не is_lost) этап текущей воронки
    const firstWorkStage = allStages.find((s) => !s.is_won && !s.is_lost);
    if (!firstWorkStage) return;
    await move(deal.id, firstWorkStage.id);
  }

  // ── move handler ─────────────────────────────────────────────────────────
  async function move(dealId: number, stageId: number) {
    const targetCol = board?.columns.find((c) => c.stage.id === stageId);
    const targetStage = targetCol?.stage;

    if (targetStage?.is_lost) {
      setLostTarget({ dealId, stageId });
      return;
    }

    setError(null);
    try {
      await api(`/deals/${dealId}/move`, { method: "POST", body: { stage_id: stageId } });
      await mutateBoard();
    } catch (err) {
      if (err instanceof ApiError && err.status === 409) {
        // WIN_GATE_FAILED — показываем полный SuccessGateModal.
        // Находим сделку в доске, чтобы прокинуть amount/currency для платежа.
        const gateDeal = board?.columns
          .flatMap((c) => c.deals)
          .find((dd) => dd.id === dealId);
        const dealAmount = gateDeal?.amount ?? null;
        const dealCurrency = gateDeal?.currency ?? null;
        const detail = err.detail as WinGateFailedError | null;
        if (detail && detail.code === "WIN_GATE_FAILED") {
          setWinGateTarget({ dealId, stageId, gateInfo: detail, dealAmount, dealCurrency });
        } else {
          // fallback — generic gate info
          setWinGateTarget({
            dealId,
            stageId,
            gateInfo: { code: "WIN_GATE_FAILED", has_signed_scan: false, has_payment: false, contract_id: null },
            dealAmount,
            dealCurrency,
          });
        }
        return;
      }
      setError(
        err instanceof ApiError
          ? String((err.detail as { detail?: string })?.detail ?? err.message)
          : "Не удалось перевести сделку"
      );
    }
  }

  // ── delete handler ───────────────────────────────────────────────────────
  async function deleteDeal(dealId: number) {
    if (!confirm("Удалить сделку?")) return;
    try {
      await api(`/deals/${dealId}`, { method: "DELETE" });
      await mutateBoard();
      if (listKey) void globalMutate(listKey);
    } catch {
      setError("Не удалось удалить сделку");
    }
  }

  // ── mutate all deal-related keys ─────────────────────────────────────────
  function mutateAll() {
    void mutateBoard();
    if (listKey) void globalMutate(listKey);
    void globalMutate("/activities/counts-by-preset");
  }

  // ── bulk select helpers ──────────────────────────────────────────────────
  function handleSelect(id: number, checked: boolean) {
    setSelectedIds((prev) => (checked ? [...prev, id] : prev.filter((x) => x !== id)));
  }

  function handleBulkMode() {
    setBulkMode(true);
    setSelectedIds([]);
  }

  function clearBulk() {
    setBulkMode(false);
    setSelectedIds([]);
  }

  return (
    <div className="flex flex-col h-full">
      <PageHeader
        title="Сделки"
        description="воронка продаж"
        actions={
          <div className="flex items-center gap-2">
            {/* Context menu (3-точки) */}
            {user && (
              <DealContextMenu
                userRole={user.role}
                onBulkMode={handleBulkMode}
                onFindDuplicates={() => setDupOpen(true)}
              />
            )}
            {/* Create button — Wave 4: ведёт на полноценную страницу-черновик */}
            <button
              className="btn-primary text-sm"
              onClick={() => router.push(pid ? `/deals/new?pipeline_id=${pid}` : "/deals/new")}
            >
              <i className="bi bi-plus mr-1" />
              Новый лид / сделка
            </button>
          </div>
        }
      />

      {/* View switcher + pipeline selector */}
      <div className="flex items-center gap-3 px-4 py-2 border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
        {/* View switcher */}
        <div className="flex rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden text-sm">
          {(["kanban", "list", "tasks"] as ViewMode[]).map((v) => {
            const labels: Record<ViewMode, { label: string; icon: string }> = {
              kanban: { label: "Канбан", icon: "bi-kanban" },
              list: { label: "Список", icon: "bi-list-ul" },
              tasks: { label: "Задачи", icon: "bi-clipboard-check" },
            };
            return (
              <button
                key={v}
                onClick={() => { setView(v); clearBulk(); }}
                className={
                  "flex items-center gap-1.5 px-3 py-1.5 transition-colors " +
                  (view === v
                    ? "bg-primary text-white"
                    : "text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700")
                }
              >
                <i className={`bi ${labels[v].icon} text-sm`} />
                {labels[v].label}
              </button>
            );
          })}
        </div>

        {/* Pipeline selector */}
        {pipelines.length > 1 && (
          <select
            className="input text-sm py-1.5 w-48 shrink-0"
            value={pid ?? ""}
            onChange={(e) => setPid(Number(e.target.value))}
          >
            {pipelines.map((p) => (
              <option key={p.id} value={p.id}>{p.name}</option>
            ))}
          </select>
        )}

        {/* Поиск по сделкам — в строке тулбара, справа от селектора воронки */}
        {(view === "kanban" || view === "list") && (
          <div className="relative">
            <i className="bi bi-search absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm pointer-events-none" />
            <input
              type="text"
              className="input pl-8 w-48 text-sm py-1.5"
              placeholder="Поиск…"
              value={filters.q}
              onChange={(e) => setFilters((f) => ({ ...f, q: e.target.value }))}
            />
          </div>
        )}

        {/* Активный фильтр «Без задач» (deep-link из дашборда) */}
        {filters.no_tasks && (
          <span className="inline-flex items-center gap-1.5 text-xs font-medium bg-warning/20 text-warning rounded-full pl-2.5 pr-1.5 py-1">
            <i className="bi bi-clipboard-x" />
            Без задач
            <button
              type="button"
              onClick={() => setFilters((f) => ({ ...f, no_tasks: false }))}
              className="hover:opacity-70"
              aria-label="Сбросить фильтр «Без задач»"
            >
              <i className="bi bi-x-lg" />
            </button>
          </span>
        )}

        {/* Bulk mode badge */}
        {bulkMode && (
          <div className="flex items-center gap-2 ml-auto">
            <span className="text-sm text-primary font-medium">
              Режим выбора: {selectedIds.length} выбрано
            </span>
            <button className="btn-ghost text-sm" onClick={clearBulk}>
              <i className="bi bi-x mr-1" />
              Отмена
            </button>
          </div>
        )}
      </div>

      {/* Error banner */}
      {error && (
        <div className="mx-4 mt-2 text-sm text-danger bg-danger/10 px-3 py-2 rounded flex items-center gap-2">
          <i className="bi bi-exclamation-triangle shrink-0" />
          {error}
          <button className="ml-auto text-danger hover:opacity-70" onClick={() => setError(null)}>
            <i className="bi bi-x" />
          </button>
        </div>
      )}

      {/* Filters bar (kanban + list only) — поиск вынесен в тулбар выше */}
      {(view === "kanban" || view === "list") && (
        <DealFiltersBar filters={filters} onChange={setFilters} hideSearch />
      )}

      {/* Main content */}
      <div className="flex-1 overflow-auto min-h-0">
        {view === "kanban" && (
          <>
            {!board && !pid && (
              <div className="flex items-center justify-center py-16 text-gray-500 text-sm">
                Загрузка…
              </div>
            )}
            {board && board.columns.length === 0 && (
              <div className="flex items-center justify-center py-16">
                <EmptyState
                  icon="bi-kanban"
                  title="Нет доступных этапов"
                  description="Настройте воронку в разделе «Настройки воронки»"
                />
              </div>
            )}
            {board && board.columns.length > 0 && (
              <DealKanbanView
                board={board}
                bulkMode={bulkMode}
                selectedIds={selectedIds}
                usersById={usersById}
                onSelect={handleSelect}
                onMove={move}
                onDelete={deleteDeal}
                onMeetingReport={handleMeetingReport}
                onSendPresentation={handleSendPresentation}
                onGenerateDoc={handleGenerateDoc}
                onReturnToWork={handleReturnToWork}
              />
            )}
          </>
        )}

        {view === "list" && (
          <DealListView
            rows={listRows}
            bulkMode={bulkMode}
            selectedIds={selectedIds}
            onSelect={handleSelect}
            users={users}
          />
        )}

        {view === "tasks" && <DealTaskView pipelineId={pid} />}
      </div>

      {/* Bulk actions bar (kanban + list) */}
      {(view === "kanban" || view === "list") && (
        <DealBulkActionsBar
          selectedIds={selectedIds}
          stages={allStages}
          userRole={user?.role ?? "manager"}
          onClear={clearBulk}
          onMutate={mutateAll}
        />
      )}

      {/* Lost reason modal */}
      {lostTarget && (
        <LostReasonModal
          open={!!lostTarget}
          dealId={lostTarget.dealId}
          targetStageId={lostTarget.stageId}
          onClose={() => setLostTarget(null)}
          onConfirmed={() => { setLostTarget(null); void mutateBoard(); }}
        />
      )}

      {/* Win gate modal (full, Ф2b) */}
      {winGateTarget && (
        <SuccessGateModal
          dealId={winGateTarget.dealId}
          targetStageId={winGateTarget.stageId}
          gateInfo={winGateTarget.gateInfo}
          dealAmount={winGateTarget.dealAmount}
          dealCurrency={winGateTarget.dealCurrency}
          substages={allStages.filter(
            (s) => s.parent_stage_id === winGateTarget.stageId
          )}
          onClose={() => setWinGateTarget(null)}
          onSuccess={() => { setWinGateTarget(null); void mutateBoard(); }}
        />
      )}

      {/* Meeting report modal */}
      {meetingReportDealId && (
        <MeetingReportModal
          dealId={meetingReportDealId}
          onClose={() => setMeetingReportDealId(null)}
          onSaved={() => { setMeetingReportDealId(null); mutateAll(); }}
        />
      )}

      {/* Presentation send modal */}
      {presentationDeal && (
        <PresentationSendModal
          dealId={presentationDeal.id}
          companyName={null}
          onClose={() => setPresentationDeal(null)}
        />
      )}

      {/* Duplicate search modal */}
      {dupOpen && (
        <DuplicateSearchModal open={dupOpen} onClose={() => setDupOpen(false)} />
      )}
    </div>
  );
}

// ── inline DuplicateSearchModal ──────────────────────────────────────────────
// Простая обёртка — сканирует дубли компаний и показывает результат.
// Полный merge-flow через MergeModal / MultiMergeFlow уже есть в компонентах.
function DuplicateSearchModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { data: scanResult, isLoading } = useSWR(
    open ? "/duplicates/scan?entity_type=company" : null,
    fetcher
  );

  const groups = (scanResult as { groups?: { id: string; records: { display_name: string }[]; similarity_score: number }[] } | undefined)?.groups ?? [];

  return (
    <Modal open={open} title="Поиск дублей компаний" onClose={onClose} width="md">
      {isLoading && (
        <div className="py-6 text-center text-gray-500 text-sm">Сканирование…</div>
      )}
      {!isLoading && groups.length === 0 && (
        <div className="py-6 text-center text-gray-500 text-sm">
          <i className="bi bi-check-circle text-success text-2xl block mb-2" />
          Дублей не найдено
        </div>
      )}
      {!isLoading && groups.length > 0 && (
        <div className="space-y-3">
          <p className="text-sm text-gray-600 dark:text-gray-400">
            Найдено {groups.length} групп похожих компаний. Перейдите в{" "}
            <a href="/admin/duplicates" className="text-primary hover:underline">Дубликаты</a>{" "}
            для объединения.
          </p>
          <div className="max-h-64 overflow-y-auto space-y-2">
            {groups.slice(0, 10).map((g) => (
              <div
                key={g.id}
                className="border border-gray-200 dark:border-gray-700 rounded-lg p-3 text-sm"
              >
                <div className="flex items-center justify-between mb-1">
                  <span className="text-xs text-gray-400">
                    Схожесть: {Math.round(g.similarity_score * 100)}%
                  </span>
                </div>
                <div className="space-y-0.5">
                  {g.records.map((r: { display_name: string }, i: number) => (
                    <div key={i} className="text-gray-700 dark:text-gray-300 truncate">
                      · {r.display_name}
                    </div>
                  ))}
                </div>
              </div>
            ))}
          </div>
        </div>
      )}
    </Modal>
  );
}
