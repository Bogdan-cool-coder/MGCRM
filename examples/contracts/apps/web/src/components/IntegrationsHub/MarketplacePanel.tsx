"use client";

import { useMemo, useState } from "react";
import useSWR from "swr";
import { EmptyState } from "@/components/EmptyState";
import { IntegrationCard, INTEGRATIONS_CONFIG } from "@/components/Integrations/IntegrationCard";
import { IntegrationSetupModal } from "@/components/Integrations/IntegrationSetupModal";
import { fetcher } from "@/lib/api";
import type { MarketplaceItem, MarketplaceStatus } from "@/lib/types";

type CategoryFilter = "all" | "telephony" | "storage" | "messenger" | "erp";
type StatusFilter = "all" | "connected" | "available" | "coming_soon";

const CATEGORY_LABELS: Record<CategoryFilter, string> = {
  all: "Все категории",
  telephony: "Телефония",
  storage: "Хранилище",
  messenger: "Мессенджеры",
  erp: "ERP",
};

const STATUS_LABELS: Record<StatusFilter, string> = {
  all: "Все статусы",
  connected: "Подключено",
  available: "Доступно",
  coming_soon: "Скоро",
};

interface MarketplacePanelProps {
  /** Callback to switch hub tab (e.g. when clicking a card that belongs to another tab) */
  onNavigateToTab: (tab: string) => void;
}

export function MarketplacePanel({ onNavigateToTab }: MarketplacePanelProps) {
  const { data: marketplace } = useSWR<MarketplaceItem[]>("/integrations/marketplace", fetcher);

  const [search, setSearch] = useState("");
  const [categoryFilter, setCategoryFilter] = useState<CategoryFilter>("all");
  const [statusFilter, setStatusFilter] = useState<StatusFilter>("all");
  const [setupId, setSetupId] = useState<string | null>(null);

  const marketplaceMap = useMemo(() => {
    const m: Record<string, MarketplaceStatus> = {};
    marketplace?.forEach((item) => { m[item.id] = item.status; });
    return m;
  }, [marketplace]);

  const filtered = useMemo(() => {
    return INTEGRATIONS_CONFIG.filter((cfg) => {
      const status: MarketplaceStatus = cfg.staticStatus ?? marketplaceMap[cfg.id] ?? "available";
      if (search && !cfg.label.toLowerCase().includes(search.toLowerCase())) return false;
      if (categoryFilter !== "all" && cfg.category !== categoryFilter) return false;
      if (statusFilter !== "all" && status !== statusFilter) return false;
      return true;
    });
  }, [search, categoryFilter, statusFilter, marketplaceMap]);

  function handleCardClick(id: string) {
    if (id === "telegram") {
      onNavigateToTab("channels");
      return;
    }
    if (id === "mango" || id === "uis") {
      onNavigateToTab("telephony");
      return;
    }
    setSetupId(id);
  }

  return (
    <>
      <div className="mb-6">
        <h2 className="text-base font-semibold text-gray-900 dark:text-gray-100">Маркетплейс</h2>
        <p className="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
          Подключай внешние сервисы к MACRO CRM
        </p>
      </div>

      {/* Filters */}
      <div className="flex flex-wrap gap-3 mb-6">
        <input
          className="input flex-1 min-w-[200px]"
          placeholder="Поиск по названию…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
        <select
          className="input w-44"
          value={categoryFilter}
          onChange={(e) => setCategoryFilter(e.target.value as CategoryFilter)}
        >
          {(Object.keys(CATEGORY_LABELS) as CategoryFilter[]).map((k) => (
            <option key={k} value={k}>{CATEGORY_LABELS[k]}</option>
          ))}
        </select>
        <select
          className="input w-44"
          value={statusFilter}
          onChange={(e) => setStatusFilter(e.target.value as StatusFilter)}
        >
          {(Object.keys(STATUS_LABELS) as StatusFilter[]).map((k) => (
            <option key={k} value={k}>{STATUS_LABELS[k]}</option>
          ))}
        </select>
      </div>

      {/* Loading skeleton */}
      {!marketplace && (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
          {[1, 2, 3, 4, 5, 6, 7, 8].map((i) => (
            <div key={i} className="h-44 bg-gray-100 dark:bg-gray-800 animate-pulse rounded-2xl border border-gray-100 dark:border-gray-800" />
          ))}
        </div>
      )}

      {/* Grid */}
      {marketplace && filtered.length > 0 && (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
          {filtered.map((cfg) => (
            <IntegrationCard
              key={cfg.id}
              config={cfg}
              apiStatus={marketplaceMap[cfg.id]}
              onClick={() => handleCardClick(cfg.id)}
            />
          ))}
        </div>
      )}

      {/* Empty */}
      {marketplace && filtered.length === 0 && (
        <EmptyState
          icon="bi-plug"
          title="Нет интеграций с такими фильтрами"
          description="Попробуй изменить параметры поиска"
        />
      )}

      <IntegrationSetupModal
        open={setupId !== null}
        integrationId={setupId}
        onClose={() => setSetupId(null)}
      />
    </>
  );
}
