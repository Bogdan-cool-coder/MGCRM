"use client";

import { useState } from "react";
import useSWR from "swr";
import { EmptyState } from "@/components/EmptyState";
import { WebhooksTable } from "@/components/Webhooks/WebhooksTable";
import { CreateWebhookModal } from "@/components/Webhooks/CreateWebhookModal";
import { WebhookSecretModal } from "@/components/Webhooks/WebhookSecretModal";
import { DeliveriesTab } from "@/components/Webhooks/DeliveriesTab";
import { fetcher } from "@/lib/api";
import type { Webhook, WebhookCreateResponse } from "@/lib/types";

type SubTab = "webhooks" | "deliveries";

const SUB_TABS: { key: SubTab; label: string; icon: string }[] = [
  { key: "webhooks", label: "Вебхуки", icon: "bi-broadcast-pin" },
  { key: "deliveries", label: "Доставки", icon: "bi-send-check" },
];

export function WebhooksPanel() {
  const { data: webhooks, mutate, isLoading, error } = useSWR<Webhook[]>("/webhooks", fetcher);

  const [activeSubTab, setActiveSubTab] = useState<SubTab>("webhooks");
  const [createOpen, setCreateOpen] = useState(false);
  const [editWebhook, setEditWebhook] = useState<Webhook | null>(null);
  const [revealSecret, setRevealSecret] = useState<string | null>(null);

  function openCreate() {
    setEditWebhook(null);
    setCreateOpen(true);
  }

  function openEdit(wh: Webhook) {
    setEditWebhook(wh);
    setCreateOpen(true);
  }

  function handleCreated(result: WebhookCreateResponse) {
    setCreateOpen(false);
    setRevealSecret(result.plaintext_secret);
    void mutate();
  }

  function handleUpdated() {
    setCreateOpen(false);
    void mutate();
  }

  return (
    <>
      <div className="flex items-center justify-between mb-6">
        <div>
          <h2 className="text-base font-semibold text-gray-900 dark:text-gray-100">Webhooks</h2>
          <p className="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
            Исходящие HTTP-уведомления о событиях CRM для внешних систем
          </p>
        </div>
        {activeSubTab === "webhooks" && (
          <button className="btn-primary" onClick={openCreate}>
            <i className="bi bi-plus-lg" /> Создать вебхук
          </button>
        )}
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

      {activeSubTab === "webhooks" && (
        <>
          {isLoading && (
            <div className="card rounded-2xl shadow-elev-1 overflow-hidden border border-gray-100 dark:border-gray-800 animate-pulse">
              {[1, 2, 3].map((i) => (
                <div key={i} className="h-14 border-b border-gray-100 dark:border-gray-800 last:border-0 bg-white dark:bg-gray-900" />
              ))}
            </div>
          )}
          {error && !isLoading && (
            <div className="rounded-lg bg-danger/10 text-danger px-4 py-3 text-sm flex items-center gap-2">
              <i className="bi bi-exclamation-circle shrink-0" />
              Не удалось загрузить вебхуки
            </div>
          )}
          {!isLoading && !error && (!webhooks || webhooks.length === 0) && (
            <EmptyState
              icon="bi-broadcast-pin"
              title="Нет вебхуков"
              description="Создайте первый вебхук для получения событий CRM во внешней системе"
              cta={
                <button className="btn-primary" onClick={openCreate}>
                  <i className="bi bi-plus-lg mr-1" /> Создать вебхук
                </button>
              }
            />
          )}
          {!isLoading && webhooks && webhooks.length > 0 && (
            <WebhooksTable
              webhooks={webhooks}
              onEdit={openEdit}
              onChanged={() => void mutate()}
            />
          )}
        </>
      )}

      {activeSubTab === "deliveries" && (
        <DeliveriesTab webhooks={webhooks ?? []} />
      )}

      <CreateWebhookModal
        open={createOpen}
        webhook={editWebhook}
        onClose={() => { setCreateOpen(false); setEditWebhook(null); }}
        onCreated={handleCreated}
        onUpdated={handleUpdated}
      />

      <WebhookSecretModal
        open={revealSecret !== null}
        secret={revealSecret ?? ""}
        onClose={() => setRevealSecret(null)}
      />
    </>
  );
}
