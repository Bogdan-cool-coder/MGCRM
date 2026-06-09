"use client";

import { useState } from "react";
import useSWR from "swr";
import { api, ApiError, fetcher } from "@/lib/api";
import { EmptyState } from "@/components/EmptyState";
import { DeliveryDetailModal } from "./DeliveryDetailModal";
import type { Webhook, WebhookDelivery, WebhookDeliveryStatus } from "@/lib/types";

// NOTE: Backend has no global GET /webhook-deliveries endpoint —
// deliveries_router only exposes /{delivery_id}/retry.
// We load per-webhook (webhook selection required in UI).

const PAGE_SIZE = 25;

function buildDeliveriesUrl(
  webhookId: string,
  status: string,
  event: string,
  offset: number,
): string | null {
  if (!webhookId) return null;
  const params = new URLSearchParams({ limit: String(PAGE_SIZE), offset: String(offset) });
  if (status) params.set("status", status);
  if (event) params.set("event", event);
  return `/webhooks/${webhookId}/deliveries?${params.toString()}`;
}

function StatusBadge({ status }: { status: string }) {
  const map: Record<string, { cls: string; icon: string; label: string }> = {
    pending: {
      cls: "bg-info-50 text-info-700 dark:bg-info-500/10 dark:text-info-400",
      icon: "bi-clock",
      label: "Ожидание",
    },
    success: {
      cls: "bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400",
      icon: "bi-check-circle",
      label: "Доставлено",
    },
    failed: {
      cls: "bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400",
      icon: "bi-x-circle",
      label: "Ошибка",
    },
    retrying: {
      cls: "bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400",
      icon: "bi-arrow-clockwise",
      label: "Повтор",
    },
  };
  const { cls, icon, label } = map[status] ?? {
    cls: "bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400",
    icon: "bi-circle",
    label: status,
  };
  return (
    <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${cls}`}>
      <i className={`bi ${icon} ${status === "retrying" ? "animate-spin" : ""}`} />
      {label}
    </span>
  );
}

interface Props {
  webhooks: Webhook[];
}

export function DeliveriesTab({ webhooks }: Props) {
  const [selectedWebhookId, setSelectedWebhookId] = useState<string>("");
  const [statusFilter, setStatusFilter] = useState<string>("");
  const [eventFilter, setEventFilter] = useState<string>("");
  const [offset, setOffset] = useState(0);
  const [detailDelivery, setDetailDelivery] = useState<WebhookDelivery | null>(null);

  const hasFilters = selectedWebhookId !== "" || statusFilter !== "" || eventFilter !== "";

  const swrKey = buildDeliveriesUrl(selectedWebhookId, statusFilter, eventFilter, offset);
  const { data: deliveries, error: deliveriesError, isLoading, mutate } = useSWR<WebhookDelivery[]>(
    swrKey,
    fetcher,
  );

  // Load more — append
  const [allDeliveries, setAllDeliveries] = useState<WebhookDelivery[]>([]);

  // When we change filters, reset
  function resetFilters() {
    setSelectedWebhookId("");
    setStatusFilter("");
    setEventFilter("");
    setOffset(0);
    setAllDeliveries([]);
  }

  function handleWebhookChange(id: string) {
    setSelectedWebhookId(id);
    setOffset(0);
    setAllDeliveries([]);
  }

  function handleStatusChange(s: string) {
    setStatusFilter(s);
    setOffset(0);
    setAllDeliveries([]);
  }

  function handleEventChange(e: string) {
    setEventFilter(e);
    setOffset(0);
    setAllDeliveries([]);
  }

  function handleLoadMore() {
    if (deliveries) {
      setAllDeliveries((prev) => [...prev, ...deliveries]);
    }
    setOffset((o) => o + PAGE_SIZE);
  }

  const displayDeliveries: WebhookDelivery[] = offset === 0 ? (deliveries ?? []) : [...allDeliveries, ...(deliveries ?? [])];
  const canLoadMore = deliveries && deliveries.length === PAGE_SIZE;

  async function handleRetry(id: number) {
    try {
      await api(`/webhook-deliveries/${id}/retry`, { method: "POST" });
      await mutate();
    } catch {
      // silently handle — modal shows errors
    }
  }

  return (
    <div>
      {/* Filters */}
      <div className="flex flex-wrap items-end gap-3 mb-4">
        <div>
          <label className="label text-xs mb-1">Вебхук <span className="text-danger">*</span></label>
          <select
            className="input min-w-[180px]"
            value={selectedWebhookId}
            onChange={(e) => handleWebhookChange(e.target.value)}
          >
            <option value="">— выберите вебхук —</option>
            {webhooks.map((wh) => (
              <option key={wh.id} value={String(wh.id)}>{wh.name}</option>
            ))}
          </select>
        </div>

        <div>
          <label className="label text-xs mb-1">Статус</label>
          <select
            className="input min-w-[140px]"
            value={statusFilter}
            onChange={(e) => handleStatusChange(e.target.value)}
            disabled={!selectedWebhookId}
          >
            <option value="">Все</option>
            <option value="pending">Ожидание</option>
            <option value="success">Доставлено</option>
            <option value="failed">Ошибка</option>
            <option value="retrying">Повтор</option>
          </select>
        </div>

        <div>
          <label className="label text-xs mb-1">Событие</label>
          <input
            className="input min-w-[160px]"
            placeholder="Например: lead.created"
            value={eventFilter}
            onChange={(e) => handleEventChange(e.target.value)}
            disabled={!selectedWebhookId}
          />
        </div>

        {hasFilters && (
          <button className="btn-ghost text-sm" onClick={resetFilters}>
            <i className="bi bi-x-circle" /> Сбросить
          </button>
        )}
      </div>

      {/* Content */}
      {!selectedWebhookId && (
        <EmptyState
          icon="bi-broadcast-pin"
          title="Выберите вебхук"
          description="Доставки отображаются для конкретного вебхука"
        />
      )}

      {selectedWebhookId && isLoading && offset === 0 && (
        <div className="card rounded-2xl shadow-elev-1 overflow-hidden border border-gray-100 dark:border-gray-800 animate-pulse">
          {[1, 2, 3].map((i) => (
            <div key={i} className="h-12 border-b border-gray-100 dark:border-gray-800 last:border-0 bg-white dark:bg-gray-900" />
          ))}
        </div>
      )}

      {selectedWebhookId && deliveriesError && (
        <div className="rounded-lg bg-danger/10 text-danger px-4 py-3 text-sm flex items-center gap-2">
          <i className="bi bi-exclamation-circle shrink-0" />
          Не удалось загрузить доставки
        </div>
      )}

      {selectedWebhookId && !isLoading && !deliveriesError && displayDeliveries.length === 0 && (
        <EmptyState icon="bi-send-check" title="Нет доставок" />
      )}

      {selectedWebhookId && !deliveriesError && displayDeliveries.length > 0 && (
        <div>
          <div className="card rounded-2xl shadow-elev-1 overflow-hidden border border-gray-100 dark:border-gray-800">
            <div className="overflow-x-auto">
              <table className="min-w-full text-sm">
                <thead className="border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
                  <tr>
                    <th className="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Событие</th>
                    <th className="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Статус</th>
                    <th className="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">HTTP</th>
                    <th className="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Попытки</th>
                    <th className="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Создан</th>
                    <th className="px-4 py-2.5 w-32" />
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                  {displayDeliveries.map((d) => {
                    const canRetry = d.status === "failed" || d.status === "retrying";
                    return (
                      <tr key={d.id} className="group hover:bg-primary/[0.03] dark:hover:bg-primary/[0.06] transition-colors duration-100">
                        <td className="px-4 py-2.5 font-mono text-xs text-gray-700 dark:text-gray-300">{d.event}</td>
                        <td className="px-4 py-2.5">
                          <StatusBadge status={d.status} />
                        </td>
                        <td className="px-4 py-2.5 text-xs text-gray-500 dark:text-gray-400 tabular-nums">{d.last_http_code ?? "—"}</td>
                        <td className="px-4 py-2.5 text-xs text-gray-500 dark:text-gray-400 tabular-nums">{d.attempt}</td>
                        <td className="px-4 py-2.5 text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">
                          {new Date(d.created_at).toLocaleString("ru-RU")}
                        </td>
                        <td className="px-4 py-2.5 text-right">
                          <div className="opacity-0 group-hover:opacity-100 transition-opacity duration-100 flex items-center justify-end gap-1">
                            <button
                              className="btn-ghost text-xs"
                              onClick={() => setDetailDelivery(d)}
                            >
                              <i className="bi bi-eye mr-1" />Детали
                            </button>
                            {canRetry && (
                              <button
                                className="btn-ghost text-xs text-warning"
                                onClick={() => handleRetry(d.id)}
                              >
                                <i className="bi bi-arrow-clockwise mr-1" />Retry
                              </button>
                            )}
                          </div>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </div>

          {canLoadMore && (
            <div className="mt-4 text-center">
              <button
                className="btn-secondary"
                onClick={handleLoadMore}
                disabled={isLoading}
              >
                {isLoading ? "Загрузка…" : "Загрузить ещё"}
              </button>
            </div>
          )}
        </div>
      )}

      <DeliveryDetailModal
        open={detailDelivery !== null}
        delivery={detailDelivery}
        onClose={() => setDetailDelivery(null)}
        onRetried={() => { setDetailDelivery(null); void mutate(); }}
      />
    </div>
  );
}
