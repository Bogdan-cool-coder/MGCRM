"use client";

import { useState } from "react";
import { WebhookDeliveriesTab } from "@/components/Integrations/Logs/WebhookDeliveriesTab";
import { ApiRequestLogsTab } from "@/components/Integrations/Logs/ApiRequestLogsTab";
import { CalldownLogsTab } from "@/components/Integrations/Logs/CalldownLogsTab";

type SubTab = "webhooks" | "api" | "calldown";

const SUB_TABS: { key: SubTab; label: string; icon: string }[] = [
  { key: "webhooks", label: "Webhook доставки", icon: "bi-send-check" },
  { key: "api", label: "API запросы", icon: "bi-activity" },
  { key: "calldown", label: "Calldown", icon: "bi-telephone" },
];

export function LogsPanel() {
  const [activeSubTab, setActiveSubTab] = useState<SubTab>("webhooks");

  return (
    <>
      <div className="mb-6">
        <h2 className="text-base font-semibold text-gray-900 dark:text-gray-100">Логи интеграций</h2>
        <p className="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
          Мониторинг webhook-доставок, API-запросов и звонков
        </p>
      </div>

      {/* Sub-tabs */}
      <div className="flex gap-1 border-b border-gray-200 dark:border-gray-700 mb-6">
        {SUB_TABS.map((t) => (
          <button
            key={t.key}
            onClick={() => setActiveSubTab(t.key)}
            className={
              "flex items-center gap-1.5 px-4 py-2.5 text-sm font-medium transition-colors border-b-2 -mb-px " +
              (activeSubTab === t.key
                ? "border-primary text-primary"
                : "border-transparent text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100")
            }
          >
            <i className={`bi ${t.icon}`} />
            {t.label}
          </button>
        ))}
      </div>

      {activeSubTab === "webhooks" && <WebhookDeliveriesTab />}
      {activeSubTab === "api" && <ApiRequestLogsTab />}
      {activeSubTab === "calldown" && <CalldownLogsTab />}
    </>
  );
}
