"use client";

import { useMemo, useState, useCallback, useEffect, useRef } from "react";
import useSWR from "swr";
import { PageHeader } from "@/components/PageHeader";
import { FunnelConversionWidget } from "@/components/Dashboard/FunnelConversionWidget";
import { RevenueForecastWidget } from "@/components/Dashboard/RevenueForecastWidget";
import { MyTasksWidget } from "@/components/Dashboard/MyTasksWidget";
import { HotDealsWidget } from "@/components/Dashboard/HotDealsWidget";
import { AwaitingPaymentWidget } from "@/components/Dashboard/AwaitingPaymentWidget";
import { PaidDealsWidget } from "@/components/Dashboard/PaidDealsWidget";
import { HotForecastWidget } from "@/components/Dashboard/HotForecastWidget";
import { DealsWithoutTasksTile } from "@/components/Dashboard/DealsWithoutTasksTile";
import { StatusSingleTile } from "@/components/Dashboard/StatusSingleTile";
import { BreakdownWidget } from "@/components/Dashboard/BreakdownWidget";
import { DashboardCustomizer } from "@/components/Dashboard/DashboardCustomizer";
import { GridDashboard } from "@/components/Dashboard/GridDashboard";
import { KpiCard } from "@/components/Dashboard/KpiCard";
import { api, fetcher } from "@/lib/api";
import { exportDashboardToPdf } from "@/lib/dashboardPdf";
import { useToast } from "@/components/ui/Toast";
import {
  DEFAULT_LAYOUT,
  mergeLayout,
  type DashboardWidgetConfig,
  type DashboardWidgetId,
} from "@/lib/dashboardLayout";
import { StatusLabels, type ContractStatus, type ContractsAnalytics, type Pipeline } from "@/lib/types";

export default function DashboardPage() {
  const { data: a } = useSWR<ContractsAnalytics>("/analytics/contracts", fetcher);
  const { data: pipelines } = useSWR<Pipeline[]>("/pipelines", fetcher);
  const { toast } = useToast();

  // ── Конфиг виджетов ──────────────────────────────────────────────────────
  const { data: cfgResp, mutate: mutateCfg } = useSWR<{ config: unknown }>(
    "/me/dashboard-config",
    fetcher,
  );
  const savedLayout = useMemo<DashboardWidgetConfig[]>(
    () => (cfgResp !== undefined ? mergeLayout(cfgResp.config) : DEFAULT_LAYOUT),
    [cfgResp],
  );

  // ── Edit mode state ───────────────────────────────────────────────────────
  const [editMode, setEditMode] = useState(false);
  /** Черновик layout в режиме редактирования */
  const [draftLayout, setDraftLayout] = useState<DashboardWidgetConfig[]>(savedLayout);
  const [customizerOpen, setCustomizerOpen] = useState(false);
  const [savingCfg, setSavingCfg] = useState(false);
  const [pdfLoading, setPdfLoading] = useState(false);

  // При входе в edit mode — копируем savedLayout в draft
  const enterEditMode = useCallback(() => {
    setDraftLayout(savedLayout.map((w) => ({ ...w })));
    setEditMode(true);
  }, [savedLayout]);

  const cancelEditMode = useCallback(() => {
    setEditMode(false);
  }, []);

  // Текущий активный layout: в edit mode — draft, иначе — сохранённый
  const activeLayout = editMode ? draftLayout : savedLayout;

  // ── Слушаем rgl:toggle-widget от GridDashboard (drag handle eye button) ───
  const draftRef = useRef(draftLayout);
  draftRef.current = draftLayout;

  useEffect(() => {
    function handleToggle(e: Event) {
      if (!editMode) return;
      const id = (e as CustomEvent<{ id: DashboardWidgetId }>).detail?.id;
      if (!id) return;
      setDraftLayout((prev) => prev.map((w) => (w.id === id ? { ...w, visible: !w.visible } : w)));
    }
    window.addEventListener("rgl:toggle-widget", handleToggle);
    return () => window.removeEventListener("rgl:toggle-widget", handleToggle);
  }, [editMode]);

  // ── Сохранение ────────────────────────────────────────────────────────────
  async function handleSaveEdit() {
    setSavingCfg(true);
    try {
      // Нормализуем order по текущему порядку visible → hidden
      const normalized = draftLayout.map((w, i) => ({ ...w, order: i }));
      await api("/me/dashboard-config", { method: "PUT", body: { config: normalized } });
      await mutateCfg({ config: normalized }, { revalidate: false });
      setEditMode(false);
      toast.success("Настройки дашборда сохранены");
    } catch {
      toast.error("Не удалось сохранить настройки");
    } finally {
      setSavingCfg(false);
    }
  }

  async function handleResetDefaults() {
    setDraftLayout(DEFAULT_LAYOUT.map((w) => ({ ...w })));
  }

  // Сохранение из кастомайзера (модалка для видимости/порядка)
  async function handleSaveLayout(next: DashboardWidgetConfig[]) {
    setSavingCfg(true);
    try {
      await api("/me/dashboard-config", { method: "PUT", body: { config: next } });
      await mutateCfg({ config: next }, { revalidate: false });
      setCustomizerOpen(false);
      toast.success("Настройки дашборда сохранены");
    } catch {
      toast.error("Не удалось сохранить настройки");
    } finally {
      setSavingCfg(false);
    }
  }

  async function handleExportPdf() {
    setPdfLoading(true);
    try {
      await exportDashboardToPdf("dashboard-root");
      toast.success("PDF экспортирован");
    } catch {
      toast.error("Не удалось экспортировать PDF");
    } finally {
      setPdfLoading(false);
    }
  }

  // ── Grid layout change callback ───────────────────────────────────────────
  const handleGridLayoutChange = useCallback((updated: DashboardWidgetConfig[]) => {
    setDraftLayout(updated);
  }, []);

  // ── Виджет рендер ─────────────────────────────────────────────────────────
  // Шорткат для byStatusGroup / byStatus — передаём по всем status-виджетам
  const byStatusGroup = a?.by_status_group ?? {};
  const byStatus = a?.by_status ?? {};

  function renderWidget(id: DashboardWidgetId): React.ReactNode {
    switch (id) {
      // ── KPI-плитки ────────────────────────────────────────────────────────
      case "kpi-total":
        return (
          <KpiCard
            label="Всего договоров"
            value={a?.total}
            href="/contracts"
            iconClass="bi-file-earmark-text"
            iconBg="bg-info-50 dark:bg-info-500/10"
            iconColor="text-info-600"
            sparkline={a?.total_sparkline}
            sparklineColor="#1570EF"
            trendPct={a?.total_trend_pct}
            invertColor={false}
          />
        );
      case "kpi-in-review":
        return (
          <KpiCard
            label="На согласовании"
            value={a?.pending_count}
            iconClass="bi-hourglass-split"
            iconBg="bg-warning-50 dark:bg-warning-500/10"
            iconColor="text-warning-600"
          />
        );
      case "kpi-avg-approve":
        return (
          <KpiCard
            label="Ср. время согласования, дн"
            value={a?.avg_time_to_approve_days}
            iconClass="bi-clock-history"
            iconBg="bg-primary/5 dark:bg-white/5"
            iconColor="text-primary dark:text-gray-300"
            trendPct={a?.avg_time_to_approve_trend_pct}
            invertColor={true}
          />
        );
      case "kpi-avg-cycle":
        return (
          <KpiCard
            label="Ср. цикл до подписания, дн"
            value={a?.avg_cycle_days}
            iconClass="bi-graph-up"
            iconBg="bg-success-50 dark:bg-success-500/10"
            iconColor="text-success-600"
            trendPct={a?.avg_cycle_trend_pct}
            invertColor={true}
          />
        );
      case "kpi-deals-no-tasks":
        return <DealsWithoutTasksTile />;

      // ── Статус-тайлы ──────────────────────────────────────────────────────
      case "status-archive":
        return (
          <StatusSingleTile
            groupCode="archived_group"
            byStatusGroup={byStatusGroup}
            byStatus={byStatus}
          />
        );
      case "status-draft":
        return (
          <StatusSingleTile
            groupCode="draft_group"
            byStatusGroup={byStatusGroup}
            byStatus={byStatus}
          />
        );
      case "status-in-review":
        return (
          <StatusSingleTile
            groupCode="in_review_group"
            byStatusGroup={byStatusGroup}
            byStatus={byStatus}
          />
        );
      case "status-approved":
        return (
          <StatusSingleTile
            groupCode="approved_group"
            byStatusGroup={byStatusGroup}
            byStatus={byStatus}
          />
        );

      // ── Разбивки ──────────────────────────────────────────────────────────
      case "breakdown-products":
        return a ? (
          <BreakdownWidget title="По продуктам" data={a.by_product} />
        ) : null;
      case "breakdown-countries":
        return a ? (
          <BreakdownWidget title="По странам" data={a.by_country} />
        ) : null;
      case "breakdown-managers":
        return a ? (
          <BreakdownWidget title="По менеджерам" data={a.by_manager} />
        ) : null;
      case "breakdown-statuses":
        return a ? (
          <BreakdownWidget
            title="По статусам"
            data={a.by_status}
            labelMap={(k) => StatusLabels[k as ContractStatus]?.label ?? k}
          />
        ) : null;

      // ── Уже отдельные ─────────────────────────────────────────────────────
      case "my-tasks":
        return <MyTasksWidget />;
      case "hot-deals":
        return <HotDealsWidget />;
      case "funnel-conversion":
        return <FunnelConversionWidget pipelines={pipelines} />;
      case "revenue-forecast":
        return <RevenueForecastWidget pipelines={pipelines} />;
      case "awaiting-payment":
        return <AwaitingPaymentWidget />;
      case "paid-deals":
        return <PaidDealsWidget />;
      case "hot-forecast":
        return <HotForecastWidget />;
      default:
        return null;
    }
  }

  const allHidden = activeLayout.every((w) => !w.visible);

  return (
    <>
      <PageHeader
        title="Дашборд"
        description="Обзор результатов"
        sticky
        actions={
          <div className="flex items-center gap-2">
            {editMode ? (
              /* ── Панель edit mode ───────────────────────────── */
              <>
                <button
                  type="button"
                  onClick={handleResetDefaults}
                  className="btn-ghost text-sm"
                  title="Сбросить к настройкам по умолчанию"
                >
                  <i className="bi bi-arrow-counterclockwise mr-1" aria-hidden="true" />
                  Сбросить
                </button>
                <button
                  type="button"
                  onClick={cancelEditMode}
                  className="btn-secondary text-sm"
                >
                  Отмена
                </button>
                <button
                  type="button"
                  onClick={handleSaveEdit}
                  disabled={savingCfg}
                  className="btn-primary text-sm"
                >
                  <i className="bi bi-check-lg mr-1" aria-hidden="true" />
                  {savingCfg ? "Сохранение…" : "Сохранить"}
                </button>
              </>
            ) : (
              /* ── Обычный режим ──────────────────────────────── */
              <>
                <button
                  type="button"
                  onClick={enterEditMode}
                  className="btn-secondary text-sm"
                  aria-label="Редактировать дашборд"
                >
                  <i className="bi bi-pencil-square mr-1" aria-hidden="true" />
                  Редактировать
                </button>
                <button
                  type="button"
                  onClick={() => setCustomizerOpen(true)}
                  aria-haspopup="dialog"
                  aria-expanded={customizerOpen}
                  className="btn-secondary text-sm"
                >
                  <i className="bi bi-sliders mr-1" aria-hidden="true" /> Настроить
                </button>
                <button
                  type="button"
                  onClick={handleExportPdf}
                  disabled={pdfLoading}
                  className="btn-secondary text-sm"
                >
                  <i className="bi bi-file-earmark-pdf mr-1" aria-hidden="true" />
                  {pdfLoading ? "Готовим PDF…" : "Экспорт в PDF"}
                </button>
              </>
            )}
          </div>
        }
      />

      {/* Edit mode баннер */}
      {editMode && (
        <div className="mx-8 mt-4 mb-0 rounded-xl border border-primary/30 bg-primary/5 dark:bg-primary/10
          px-4 py-2.5 flex items-center gap-2 text-sm text-primary dark:text-blue-300">
          <i className="bi bi-info-circle-fill shrink-0" aria-hidden="true" />
          <span>
            Режим редактирования — перетащите виджеты, измените их размер.
            Используйте <i className="bi bi-eye" aria-hidden="true" /> на виджете, чтобы скрыть его.
          </span>
        </div>
      )}

      <div className="p-8">
        <div id="dashboard-root">
          {allHidden ? (
            <div className="card p-10 text-center">
              <i className="bi bi-eye-slash text-3xl text-gray-400 block mb-3" aria-hidden="true" />
              <div className="text-h5 mb-1">Все виджеты скрыты</div>
              <p className="text-sm text-gray-500 dark:text-gray-400 mb-4">
                Включите хотя бы один виджет, чтобы видеть данные.
              </p>
              <button
                type="button"
                className="btn-primary text-sm"
                onClick={editMode ? undefined : () => setCustomizerOpen(true)}
              >
                <i className="bi bi-sliders mr-1" aria-hidden="true" />
                {editMode ? "Показывайте виджеты через глаз выше" : "Настроить дашборд"}
              </button>
            </div>
          ) : (
            <GridDashboard
              layout={activeLayout}
              editMode={editMode}
              onLayoutChange={handleGridLayoutChange}
              renderWidget={renderWidget}
            />
          )}
        </div>
      </div>

      <DashboardCustomizer
        open={customizerOpen}
        layout={savedLayout}
        saving={savingCfg}
        onClose={() => setCustomizerOpen(false)}
        onSave={handleSaveLayout}
      />
    </>
  );
}
