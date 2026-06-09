"use client";

import { Suspense } from "react";
import { useSearchParams, useRouter } from "next/navigation";
import { PageHeader } from "@/components/PageHeader";
import { RoleGate } from "@/components/RoleGate";
import { ChannelsPanel } from "@/components/IntegrationsHub/ChannelsPanel";
import { FormsPanel } from "@/components/IntegrationsHub/FormsPanel";
import { MarketplacePanel } from "@/components/IntegrationsHub/MarketplacePanel";
import { TelephonyPanel } from "@/components/IntegrationsHub/TelephonyPanel";
import { WebhooksPanel } from "@/components/IntegrationsHub/WebhooksPanel";
import { ApiTokensPanel } from "@/components/IntegrationsHub/ApiTokensPanel";
import { OAuthPanel } from "@/components/IntegrationsHub/OAuthPanel";
import { LogsPanel } from "@/components/IntegrationsHub/LogsPanel";
import { useMe } from "@/lib/auth";
import type { UserRole } from "@/lib/types";

export const dynamic = "force-dynamic";

type TabKey =
  | "channels"
  | "forms"
  | "marketplace"
  | "telephony"
  | "webhooks"
  | "api-tokens"
  | "oauth"
  | "logs";

interface TabDef {
  key: TabKey;
  label: string;
  icon: string;
  /** Roles that can see this tab. Undefined = all admin/director/lawyer */
  roles?: UserRole[];
}

const TABS: TabDef[] = [
  { key: "channels", label: "Каналы", icon: "bi-broadcast", roles: ["admin", "director"] },
  { key: "forms", label: "Формы", icon: "bi-ui-checks-grid", roles: ["admin", "director", "lawyer"] },
  { key: "marketplace", label: "Маркетплейс", icon: "bi-plug", roles: ["admin"] },
  { key: "telephony", label: "Телефония", icon: "bi-telephone-outbound", roles: ["admin", "director"] },
  { key: "webhooks", label: "Webhooks", icon: "bi-broadcast-pin", roles: ["admin", "director"] },
  { key: "api-tokens", label: "API-токены", icon: "bi-key-fill", roles: ["admin", "director"] },
  { key: "oauth", label: "OAuth", icon: "bi-key", roles: ["admin"] },
  { key: "logs", label: "Логи", icon: "bi-journal-code", roles: ["admin"] },
];

const DEFAULT_TAB: TabKey = "channels";

function IntegrationsHubContent() {
  const searchParams = useSearchParams();
  const router = useRouter();
  const { user } = useMe();

  const visibleTabs = user
    ? TABS.filter((t) => !t.roles || t.roles.includes(user.role))
    : [];

  const rawTab = searchParams.get("tab") as TabKey | null;
  const activeTab: TabKey =
    rawTab && visibleTabs.some((t) => t.key === rawTab)
      ? rawTab
      : visibleTabs[0]?.key ?? DEFAULT_TAB;

  function setTab(key: TabKey) {
    router.replace(`/admin/integrations?tab=${key}`);
  }

  return (
    <RoleGate allowed={["admin", "director", "lawyer"]}>
      <div className="flex flex-col h-full">
        <PageHeader
          title="Интеграции и каналы"
          description="Каналы, формы, маркетплейс, телефония, вебхуки, API-токены, OAuth и логи."
        />

        {/* Tab bar */}
        <div className="px-8 border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 flex gap-0.5 flex-wrap shrink-0 overflow-x-auto">
          {visibleTabs.map((t) => (
            <button
              key={t.key}
              onClick={() => setTab(t.key)}
              className={[
                "flex items-center gap-1.5 px-4 py-2.5 text-sm font-medium border-b-2 -mb-px whitespace-nowrap transition-colors duration-150",
                activeTab === t.key
                  ? "border-primary text-primary"
                  : "border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100",
              ].join(" ")}
            >
              <i className={`bi ${t.icon}`} />
              {t.label}
            </button>
          ))}
        </div>

        {/* Panel content */}
        <div className="flex-1 overflow-y-auto px-8 py-6">
          {activeTab === "channels" && <ChannelsPanel />}
          {activeTab === "forms" && <FormsPanel />}
          {activeTab === "marketplace" && (
            <MarketplacePanel onNavigateToTab={(tab) => setTab(tab as TabKey)} />
          )}
          {activeTab === "telephony" && <TelephonyPanel />}
          {activeTab === "webhooks" && <WebhooksPanel />}
          {activeTab === "api-tokens" && <ApiTokensPanel />}
          {activeTab === "oauth" && <OAuthPanel />}
          {activeTab === "logs" && <LogsPanel />}
        </div>
      </div>
    </RoleGate>
  );
}

export default function IntegrationsHubPage() {
  return (
    <Suspense fallback={<div className="p-8 text-gray-500 dark:text-gray-400">Загрузка…</div>}>
      <IntegrationsHubContent />
    </Suspense>
  );
}
