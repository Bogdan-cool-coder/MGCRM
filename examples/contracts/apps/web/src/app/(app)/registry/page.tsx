"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import Link from "next/link";
import useSWR from "swr";
import { PageHeader } from "@/components/PageHeader";
import { CategoryBadge } from "@/components/CategoryBadge";
import { HealthBadge } from "@/components/HealthBadge";
import { Sparkline, TrendArrow } from "@/components/Sparkline";
import { UserSelect } from "@/components/UserSelect";
import { BulkSelectToolbar } from "@/components/Bulk/BulkSelectToolbar";
import { BulkDocumentModal } from "@/components/Bulk/BulkDocumentModal";
import { EmptyState } from "@/components/EmptyState";
import coinsIcon from "@/lib/lordicon/coins.json";
import { LifecycleSegments } from "@/components/Registry/LifecycleSegments";
import { RegistryTableSkeleton } from "@/components/Registry/RegistryTableSkeleton";
import { api, fetcher } from "@/lib/api";
import { useMe } from "@/lib/auth";
import { SegmentSelector } from "@/components/Segments/SegmentSelector";
import { SaveSegmentModal } from "@/components/Segments/SaveSegmentModal";
import {
  ATTENTION_LABELS, TIER_META,
  type ClientCategory, type Pipeline, type PipelineStage, type Platform, type RegistryKpi, type RegistryRow, type Region, type User,
} from "@/lib/types";
import { formatCurrency } from "@/lib/format";

type View = "registry" | "kanban" | "dashboard" | "attention";

export default function RegistryPage() {
  const [view, setView] = useState<View>("registry");
  const [f, setF] = useState({ platform_id: "", region_id: "", category_code: "", tier: "", owner_user_id: "", q: "" });

  const { data: platforms } = useSWR<Platform[]>("/platforms", fetcher);
  const { data: regions } = useSWR<Region[]>("/regions", fetcher);
  const { data: cats } = useSWR<ClientCategory[]>("/client-categories", fetcher);
  const { data: users } = useSWR<User[]>("/users", fetcher);
  const { data: pipelines } = useSWR<Pipeline[]>("/pipelines", fetcher);
  const lifecycle = pipelines?.find((p) => p.kind === "lifecycle");
  const { data: lcStages } = useSWR<PipelineStage[]>(lifecycle ? `/pipelines/${lifecycle.id}/stages` : null, fetcher);

  const query = useMemo(() => {
    const qs = new URLSearchParams();
    if (view === "attention") qs.set("attention", "true");
    for (const [k, v] of Object.entries(f)) if (v) qs.set(k, v);
    const s = qs.toString();
    return s ? `?${s}` : "";
  }, [f, view]);

  const listKey = view === "dashboard" ? null : `/registry${query}`;
  const { data: rows, mutate: mutateRows } = useSWR<RegistryRow[]>(listKey, fetcher);

  const [moveError, setMoveError] = useState<string | null>(null);

  async function moveSub(subId: number, stageId: number) {
    setMoveError(null);
    try {
      await api(`/subscriptions/${subId}`, { method: "PATCH", body: { lifecycle_stage_id: stageId } });
      await mutateRows();
    } catch {
      setMoveError("Не удалось переместить подписку. Попробуйте ещё раз.");
    }
  }

  const { user } = useMe();
  const canImport = user?.role === "admin" || user?.role === "director";
  const canBulk = user?.role === "admin" || user?.role === "lawyer";
  const [saveSegmentOpen, setSaveSegmentOpen] = useState(false);
  const [importing, setImporting] = useState(false);

  // Bulk-выбор (Эпик 6 MVP) — Set ID подписок (RegistryRow.id = Subscription.id)
  const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());
  const [bulkModalOpen, setBulkModalOpen] = useState(false);
  function toggleId(id: number) {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }
  function toggleAll(ids: number[], allSelected: boolean) {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      if (allSelected) {
        ids.forEach((id) => next.delete(id));
      } else {
        ids.forEach((id) => next.add(id));
      }
      return next;
    });
  }
  function clearSelection() {
    setSelectedIds(new Set());
  }
  async function doImport() {
    if (!confirm("Импортировать реестр из файла registry_import.tsv? Создаст/обновит контрагентов и подписки (идемпотентно, без дублей).")) return;
    setImporting(true);
    try {
      const r = await api<{ cp_created: number; cp_matched: number; sub_created: number; sub_updated: number }>("/registry/import", { method: "POST" });
      alert(`Импорт готов:\nконтрагентов создано ${r.cp_created}, найдено ${r.cp_matched}\nподписок создано ${r.sub_created}, обновлено ${r.sub_updated}`);
      await mutateRows();
    } catch (e) {
      alert("Ошибка импорта: " + (e instanceof Error ? e.message : "неизвестно"));
    } finally {
      setImporting(false);
    }
  }
  const dashQuery = f.platform_id || f.region_id ? `?${new URLSearchParams({ ...(f.platform_id ? { platform_id: f.platform_id } : {}), ...(f.region_id ? { region_id: f.region_id } : {}) }).toString()}` : "";
  const { data: kpi } = useSWR<RegistryKpi>(view === "dashboard" ? `/registry/dashboard${dashQuery}` : null, fetcher);

  // Проверяем, есть ли активные фильтры для кнопки «сбросить»
  const hasFilters = Object.values(f).some((v) => v !== "");

  return (
    <div>
      <PageHeader title="Реестр клиентов" description="Действующие клиенты · здоровье и активность" actions={
        <div className="flex items-center gap-2">
          <a href="/api/analytics/registry.xlsx" className="btn-secondary text-sm"><i className="bi bi-file-earmark-excel" /> Excel</a>
          {canImport && <button onClick={doImport} disabled={importing} className="btn-secondary text-sm"><i className="bi bi-cloud-upload" /> {importing ? "Импорт…" : "Импорт реестра"}</button>}
          <div className="flex rounded-lg border border-gray-300 overflow-hidden text-sm">
            {([["registry", "Действующие клиенты"], ["kanban", "Канбан"], ["dashboard", "Дашборд"], ["attention", "Требуют внимания"]] as [View, string][]).map(([v, label]) => (
              <button key={v} onClick={() => setView(v)} className={view === v ? "px-3 py-1 bg-primary text-white" : "px-3 py-1 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700"}>{label}</button>
            ))}
          </div>
        </div>
      } />

      {moveError && (
        <div className="mx-8 mt-3 px-3 py-2 rounded text-sm text-danger bg-danger/10 border border-danger/20">
          {moveError}
        </div>
      )}

      {view !== "dashboard" && (
        <>
          <div className="px-8 pt-2 flex flex-wrap gap-2 items-center">
            <SegmentSelector
              pageKey="registry"
              onApply={(filterJson) => {
                if (typeof filterJson === "object" && filterJson !== null) {
                  setF((prev) => ({ ...prev, ...(filterJson as Partial<typeof f>) }));
                }
              }}
            />
            <button onClick={() => setSaveSegmentOpen(true)} className="btn-ghost text-sm">
              <i className="bi bi-bookmark-plus mr-1" />
              Сохранить
            </button>
          </div>
          <div className="px-8 pt-2 pb-3 flex flex-wrap gap-2 items-center border-b border-gray-100 dark:border-gray-800">
            <input className="input w-48" placeholder="Поиск по клиенту…" value={f.q} onChange={(e) => setF({ ...f, q: e.target.value })} />
            <select className="input w-40" value={f.platform_id} onChange={(e) => setF({ ...f, platform_id: e.target.value })}>
              <option value="">Все платформы</option>
              {(platforms ?? []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </select>
            <select className="input w-40" value={f.region_id} onChange={(e) => setF({ ...f, region_id: e.target.value })}>
              <option value="">Все регионы</option>
              {(regions ?? []).map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
            </select>
            <select className="input w-44" value={f.category_code} onChange={(e) => setF({ ...f, category_code: e.target.value })}>
              <option value="">Все категории</option>
              {(cats ?? []).map((c) => <option key={c.code} value={c.code}>{c.code} · {c.name}</option>)}
            </select>
            <select className="input w-40" value={f.tier} onChange={(e) => setF({ ...f, tier: e.target.value })}>
              <option value="">Любое здоровье</option>
              {Object.entries(TIER_META).map(([code, m]) => <option key={code} value={code}>{code} · {m.label}</option>)}
            </select>
            <UserSelect value={f.owner_user_id} onChange={(v) => setF({ ...f, owner_user_id: v })} placeholder="Любой ответственный" className="input w-52" users={users} />
            {hasFilters && (
              <button
                onClick={() => setF({ platform_id: "", region_id: "", category_code: "", tier: "", owner_user_id: "", q: "" })}
                className="btn-ghost text-sm"
                title="Сбросить фильтры"
              >
                <i className="bi bi-x-circle mr-1" />
                Сбросить
              </button>
            )}
          </div>
        </>
      )}

      <div className="p-8 pt-4">
        {view === "dashboard" ? (
          <Dashboard kpi={kpi} />
        ) : view === "kanban" ? (
          <Kanban rows={rows} stages={lcStages} onMove={moveSub} />
        ) : (
          <RegistryTable
            rows={rows}
            userName={(uid) => users?.find((u) => u.id === uid)?.full_name}
            canBulk={canBulk}
            selectedIds={selectedIds}
            onToggleId={toggleId}
            onToggleAll={toggleAll}
          />
        )}
      </div>

      {canBulk && (
        <>
          <BulkSelectToolbar
            selectedCount={selectedIds.size}
            onClear={clearSelection}
            onAction={() => setBulkModalOpen(true)}
          />
          <BulkDocumentModal
            open={bulkModalOpen}
            onClose={() => setBulkModalOpen(false)}
            selectedIds={Array.from(selectedIds)}
            targetType="subscription"
            targetLabel="Подписок"
            onCreated={clearSelection}
          />
        </>
      )}

      <SaveSegmentModal
        open={saveSegmentOpen}
        pageKey="registry"
        currentFilterJson={f as Record<string, unknown>}
        filterSummary={[
          f.q ? `Поиск: ${f.q}` : "",
          f.platform_id ? `Платформа: #${f.platform_id}` : "",
          f.region_id ? `Регион: #${f.region_id}` : "",
          f.category_code ? `Категория: ${f.category_code}` : "",
          f.tier ? `Тир: ${f.tier}` : "",
          f.owner_user_id ? `Ответственный: #${f.owner_user_id}` : "",
        ].filter(Boolean)}
        onClose={() => setSaveSegmentOpen(false)}
        onSaved={() => setSaveSegmentOpen(false)}
      />

      {/* aria-live region для bulk-выбора */}
      {selectedIds.size > 0 && (
        <div aria-live="polite" className="sr-only">
          Выбрано {selectedIds.size} подписок
        </div>
      )}
    </div>
  );
}

interface RegistryTableProps {
  rows: RegistryRow[] | undefined;
  userName: (uid: number) => string | undefined;
  canBulk: boolean;
  selectedIds: Set<number>;
  onToggleId: (id: number) => void;
  onToggleAll: (ids: number[], allSelected: boolean) => void;
}

function RegistryTable({ rows, canBulk, selectedIds, onToggleId, onToggleAll }: RegistryTableProps) {
  const wrapRef = useRef<HTMLDivElement>(null);
  const [scrolled, setScrolled] = useState(false);

  const handleScroll = useCallback(() => {
    if (!wrapRef.current) return;
    setScrolled(wrapRef.current.scrollTop > 0);
  }, []);

  useEffect(() => {
    const el = wrapRef.current;
    if (!el) return;
    el.addEventListener("scroll", handleScroll, { passive: true });
    return () => el.removeEventListener("scroll", handleScroll);
  }, [handleScroll]);

  // Скелетон при загрузке
  if (rows === undefined) {
    return (
      <div className={`card rounded-2xl overflow-hidden shadow-elev-1 registry-table-wrap${scrolled ? " scrolled" : ""}`}>
        <div ref={wrapRef} className="overflow-x-auto overflow-y-auto max-h-[70vh]">
          <table className="w-full text-sm" role="grid" aria-label="Реестр клиентов">
            <thead className="sticky top-0 z-10 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700 registry-thead-shadow">
              <RegistryThead canBulk={canBulk} allSelected={false} someSelected={false} onToggleAll={() => {}} />
            </thead>
            <tbody>
              <RegistryTableSkeleton rows={8} withCheckbox={canBulk} />
            </tbody>
          </table>
        </div>
      </div>
    );
  }

  // Пустое состояние
  if (rows.length === 0) {
    return (
      <div className="card rounded-2xl shadow-elev-1 py-8">
        <EmptyState
          icon="bi-people"
          title="Нет клиентов по фильтру"
          description="Попробуй изменить фильтры или сбросить сегмент"
          lordIcon={{ icon: coinsIcon, trigger: "loop", size: 72 }}
        />
      </div>
    );
  }

  const allIds = rows.map((r) => r.id);
  const selectedInView = allIds.filter((id) => selectedIds.has(id)).length;
  const allSelected = selectedInView === allIds.length && allIds.length > 0;
  const someSelected = selectedInView > 0 && !allSelected;

  return (
    <div className={`card rounded-2xl overflow-hidden shadow-elev-1 registry-table-wrap${scrolled ? " scrolled" : ""}`}>
      <div ref={wrapRef} className="overflow-x-auto overflow-y-auto max-h-[70vh]" onScroll={handleScroll}>
        <table className="w-full text-sm" role="grid" aria-label="Реестр клиентов">
          <thead className="sticky top-0 z-10 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700 registry-thead-shadow">
            <RegistryThead
              canBulk={canBulk}
              allSelected={allSelected}
              someSelected={someSelected}
              onToggleAll={() => onToggleAll(allIds, allSelected)}
            />
          </thead>
          <tbody className="blur-fade" style={{ ["--blur-fade-duration" as string]: "0.2s" }}>
            {rows.map((r) => (
              <RegistryRow
                key={r.id}
                row={r}
                canBulk={canBulk}
                selected={selectedIds.has(r.id)}
                onToggle={() => onToggleId(r.id)}
              />
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

// ─── Thead ──────────────────────────────────────────────────────────────────

interface RegistryTheadProps {
  canBulk: boolean;
  allSelected: boolean;
  someSelected: boolean;
  onToggleAll: () => void;
}

function RegistryThead({ canBulk, allSelected, someSelected, onToggleAll }: RegistryTheadProps) {
  const thCls = "text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 px-3 py-2.5";
  return (
    <tr>
      {canBulk && (
        <th className={`${thCls} w-10 text-center`}>
          <input
            type="checkbox"
            className="cursor-pointer"
            checked={allSelected}
            ref={(el) => { if (el) el.indeterminate = someSelected; }}
            onChange={onToggleAll}
            aria-label="Выбрать все строки"
          />
        </th>
      )}
      <th className={`${thCls} text-left`}>Клиент</th>
      <th className={`${thCls} text-left`}>Продукты</th>
      <th className={`${thCls} text-left`}>Страна и регион</th>
      <th className={`${thCls} text-center`}>Кат.</th>
      <th className={`${thCls} text-left`}>Прогресс</th>
      <th className={`${thCls} text-center`}>Активность</th>
      <th className={`${thCls} text-center`}>Здоровье</th>
      <th className={`${thCls} text-right`}>Абонентка</th>
      <th className={`${thCls} w-10`} />
    </tr>
  );
}

// ─── Строка реестра ──────────────────────────────────────────────────────────

interface RegistryRowProps {
  row: RegistryRow;
  canBulk: boolean;
  selected: boolean;
  onToggle: () => void;
}

function RegistryRow({ row: r, canBulk, selected, onToggle }: RegistryRowProps) {
  return (
    <tr
      className={[
        "group border-b border-gray-100 dark:border-gray-800",
        "transition-colors duration-100",
        "hover:bg-primary/[0.03] dark:hover:bg-primary/[0.06]",
        selected ? "[&]:bg-primary/[0.06]" : "",
      ].join(" ")}
    >
      {canBulk && (
        <td className="px-3 py-2 text-center">
          <input
            type="checkbox"
            className="cursor-pointer"
            checked={selected}
            onChange={onToggle}
            aria-label={`Выбрать ${r.counterparty_name}`}
          />
        </td>
      )}

      {/* Клиент + attention-флаги */}
      <td className="px-3 py-2">
        <Link
          href={r.company_id ? `/companies/${r.company_id}` : `/counterparties/${r.counterparty_id}`}
          className="font-medium text-primary dark:text-blue-300 hover:underline"
        >
          {r.counterparty_name}
        </Link>
        {r.attention.length > 0 && (
          <div className="flex flex-wrap gap-1 mt-1">
            {r.attention.map((a) => (
              <span key={a} className="badge badge-warning text-[10px] px-1.5 py-0.5">
                {ATTENTION_LABELS[a] ?? a}
              </span>
            ))}
          </div>
        )}
      </td>

      {/* Продукты (платформа) */}
      <td className="px-3 py-2 text-gray-700 dark:text-gray-300 font-medium">
        {r.platform_name}
      </td>

      {/* Страна и регион */}
      <td className="px-3 py-2 text-gray-600 dark:text-gray-400">
        {r.country_code ? r.country_code.toUpperCase() : ""}
        {r.country_code && r.region_name ? " · " : ""}
        {r.region_name ?? ""}
        {!r.country_code && !r.region_name && <span className="text-gray-300">—</span>}
      </td>

      {/* Категория */}
      <td className="px-3 py-2 text-center">
        <CategoryBadge code={r.category_code} />
      </td>

      {/* Lifecycle-прогресс — заменяет ImplBar */}
      <td className="px-3 py-2">
        <LifecycleSegments code={r.status_code} />
      </td>

      {/* Активность */}
      <td className="px-3 py-2 text-center whitespace-nowrap">
        <Sparkline values={r.sparkline} />
        {" "}
        <TrendArrow pct={r.activity_trend_pct} />
      </td>

      {/* Здоровье */}
      <td className="px-3 py-2 text-center">
        <HealthBadge tier={r.health_tier} />
      </td>

      {/* Абонентка */}
      <td className="px-3 py-2 text-right tabular-nums text-gray-600 dark:text-gray-400">
        {formatCurrency(r.fee_actual, r.fee_currency)}
        {r.tariff ? <div className="text-[10px] text-gray-400">{r.tariff}</div> : null}
      </td>

      {/* Row-actions (visible on hover) */}
      <td className="px-3 py-2 text-right">
        <div className="opacity-0 group-hover:opacity-100 transition-opacity duration-100 flex items-center justify-end gap-1">
          <Link
            href={r.company_id ? `/companies/${r.company_id}` : `/counterparties/${r.counterparty_id}`}
            className="btn-ghost p-1 text-gray-400 hover:text-primary"
            title={`Открыть ${r.counterparty_name}`}
            aria-label={`Открыть ${r.counterparty_name}`}
          >
            <i className="bi bi-box-arrow-up-right text-xs" />
          </Link>
        </div>
      </td>
    </tr>
  );
}

function KpiCard({ label, value, accent }: { label: string; value: number | string; accent?: string }) {
  return (
    <div className="rounded-lg border border-gray-200 px-4 py-3">
      <div className="text-2xl font-semibold" style={accent ? { color: accent } : undefined}>{value}</div>
      <div className="text-xs text-gray-500 mt-0.5">{label}</div>
    </div>
  );
}

function Dashboard({ kpi }: { kpi: RegistryKpi | undefined }) {
  if (!kpi) return <div className="text-gray-500">Загрузка…</div>;
  return (
    <div className="space-y-6 max-w-4xl">
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
        <KpiCard label="Всего подписок" value={kpi.total} />
        <KpiCard label="Действующие (без A6/B6/C0)" value={kpi.operating} accent="#1F9D55" />
        <KpiCard label="Внедряемые (B0–B5)" value={kpi.in_implementation} accent="#2B4987" />
        <KpiCard label="Сопровождение (A1–A5)" value={kpi.support} />
        <KpiCard label="Активные (A1+A2)" value={kpi.active} accent="#1F9D55" />
        <KpiCard label="Отвал (C0)" value={kpi.closed} accent="#6B7280" />
        <KpiCard label="Конверсия в сопровождение" value={`${Math.round(kpi.conversion_support * 100)}%`} />
        <KpiCard label="Конверсия в отвал" value={`${Math.round(kpi.conversion_closed * 100)}%`} accent="#C0392B" />
      </div>
      <div>
        <h3 className="font-semibold text-gray-700 mb-2">Распределение по этапам</h3>
        <div className="flex flex-wrap gap-2">
          {Object.keys(kpi.by_code).length === 0 && <span className="text-sm text-gray-500">Нет данных.</span>}
          {Object.entries(kpi.by_code).sort(([a], [b]) => a.localeCompare(b)).map(([code, n]) => (
            <div key={code} className="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-1.5">
              <span className="font-mono text-xs font-semibold" style={{ color: TIER_META[code]?.color }}>{code}</span>
              <span className="tabular-nums text-sm">{n}</span>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

function Kanban({ rows, stages, onMove }: { rows: RegistryRow[] | undefined; stages: PipelineStage[] | undefined; onMove: (subId: number, stageId: number) => void }) {
  if (!rows || !stages) return <div className="text-gray-500">Загрузка…</div>;
  const cols = [...stages].sort((a, b) => a.sort_order - b.sort_order);
  const noStage = rows.filter((r) => r.lifecycle_stage_id == null);
  const card = (r: RegistryRow) => (
    <div key={r.id} draggable onDragStart={(e) => e.dataTransfer.setData("subId", String(r.id))}
      className="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-2 text-sm cursor-grab active:cursor-grabbing">
      <Link href={r.company_id ? `/companies/${r.company_id}` : `/counterparties/${r.counterparty_id}`} className="font-medium text-primary dark:text-blue-300 hover:underline">{r.counterparty_name}</Link>
      <div className="text-xs text-gray-500">{r.platform_name}{r.region_name ? ` · ${r.region_name}` : ""}</div>
      <div className="flex items-center gap-2 mt-1"><HealthBadge tier={r.health_tier} /><Sparkline values={r.sparkline} width={56} height={18} /></div>
    </div>
  );
  return (
    <div className="overflow-x-auto">
      <div className="flex gap-3 min-w-min">
        {noStage.length > 0 && (
          <div className="w-64 flex-shrink-0">
            <div className="rounded-t-lg px-3 py-2 text-white text-sm font-semibold flex justify-between bg-gray-400"><span>Без этапа</span><span>{noStage.length}</span></div>
            <div className="bg-gray-50 border border-gray-200 border-t-0 rounded-b-lg p-2 space-y-2 min-h-[120px]">{noStage.map(card)}</div>
          </div>
        )}
        {cols.map((s) => {
          const list = rows.filter((r) => r.lifecycle_stage_id === s.id);
          return (
            <div key={s.id} className="w-64 flex-shrink-0"
              onDragOver={(e) => e.preventDefault()}
              onDrop={(e) => { const id = Number(e.dataTransfer.getData("subId")); if (id) onMove(id, s.id); }}>
              <div className="rounded-t-lg px-3 py-2 text-white text-sm font-semibold flex justify-between" style={{ backgroundColor: s.color || "#6B7A99" }}>
                <span>{s.name}</span><span>{list.length}</span>
              </div>
              <div className="bg-gray-50 border border-gray-200 border-t-0 rounded-b-lg p-2 space-y-2 min-h-[120px]">
                {list.map(card)}
                {list.length === 0 && <div className="text-xs text-gray-400 text-center py-3">пусто</div>}
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
