"use client";

import { Suspense } from "react";
import Link from "next/link";
import { useSearchParams, useRouter } from "next/navigation";
import { PageHeader } from "@/components/PageHeader";
import { RoleGate } from "@/components/RoleGate";
import { AutomationsListPanel } from "@/components/AutomationsHub/AutomationsListPanel";
import { AutomationRunsPanel } from "@/components/AutomationsHub/AutomationRunsPanel";

export const dynamic = "force-dynamic";

type TabKey = "automations" | "sla" | "runs";

const TABS: { key: TabKey; label: string; icon: string }[] = [
  { key: "automations", label: "Автоматизации", icon: "bi-lightning-charge" },
  { key: "sla", label: "SLA-правила", icon: "bi-shield-check" },
  { key: "runs", label: "История запусков", icon: "bi-play-circle" },
];

const DEFAULT_TAB: TabKey = "automations";

function AutomationsHubContent() {
  const searchParams = useSearchParams();
  const router = useRouter();

  const rawTab = searchParams.get("tab") as TabKey | null;
  const activeTab: TabKey =
    rawTab && TABS.some((t) => t.key === rawTab) ? rawTab : DEFAULT_TAB;

  function setTab(key: TabKey) {
    router.replace(`/admin/automations?tab=${key}`);
  }

  const headerActions = activeTab === "automations" ? (
    <Link href="/admin/automations/new" className="btn-primary">
      <i className="bi bi-plus-lg mr-1" />
      Новая автоматизация
    </Link>
  ) : activeTab === "sla" ? (
    <Link href="/admin/sla/new" className="btn-primary">
      <i className="bi bi-plus-lg mr-1" />
      Новое SLA-правило
    </Link>
  ) : null;

  return (
    <RoleGate allowed={["admin", "director"]}>
      <div className="flex flex-col h-full">
        <PageHeader
          title="Автоматизации"
          description="Триггеры и действия, SLA-правила, история запусков."
          actions={headerActions}
        />

        {/* Tab bar */}
        <div className="px-8 border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 flex gap-0 flex-wrap shrink-0">
          {TABS.map((t) => (
            <button
              key={t.key}
              onClick={() => setTab(t.key)}
              className={
                "flex items-center gap-1.5 px-3 py-2.5 text-sm border-b-2 -mb-px transition-colors " +
                (activeTab === t.key
                  ? "border-primary text-primary font-medium"
                  : "border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200")
              }
            >
              <i className={`bi ${t.icon}`} />
              {t.label}
            </button>
          ))}
        </div>

        {/* Panel content */}
        <div className="flex-1 overflow-y-auto px-8 py-6">
          {activeTab === "automations" && <AutomationsListPanel />}
          {activeTab === "sla" && <AutomationsListPanel slaOnly />}
          {activeTab === "runs" && <AutomationRunsPanel />}
        </div>
      </div>
    </RoleGate>
  );
}

export default function AutomationsHubPage() {
  return (
    <Suspense fallback={<div className="p-8 text-gray-500 dark:text-gray-400">Загрузка…</div>}>
      <AutomationsHubContent />
    </Suspense>
  );
}
