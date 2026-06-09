"use client";

import { useState } from "react";
import { Modal } from "@/components/Modal";
import { api, ApiError } from "@/lib/api";
import type { WebhookDelivery, WebhookDeliveryStatus } from "@/lib/types";
import { formatDateTime } from "@/lib/dates";

interface Props {
  open: boolean;
  delivery: WebhookDelivery | null;
  onClose: () => void;
  onRetried: () => void;
}

function StatusBadge({ status }: { status: WebhookDeliveryStatus }) {
  const map: Record<WebhookDeliveryStatus, { cls: string; icon: string; label: string }> = {
    pending: { cls: "bg-info/10 text-info", icon: "bi-clock", label: "Ожидание" },
    success: { cls: "bg-success/10 text-success", icon: "bi-check-circle", label: "Доставлено" },
    failed: { cls: "bg-danger/10 text-danger", icon: "bi-x-circle", label: "Ошибка" },
    retrying: { cls: "bg-warning/10 text-warning", icon: "bi-arrow-clockwise", label: "Повтор" },
  };
  const { cls, icon, label } = map[status] ?? { cls: "bg-gray-100 text-gray-600", icon: "bi-circle", label: status };
  return (
    <span className={`inline-flex items-center gap-1 rounded px-2 py-0.5 text-xs font-medium ${cls}`}>
      <i className={`bi ${icon} ${status === "retrying" ? "animate-spin" : ""}`} />
      {label}
    </span>
  );
}

export function DeliveryDetailModal({ open, delivery, onClose, onRetried }: Props) {
  const [retrying, setRetrying] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleRetry() {
    if (!delivery) return;
    setRetrying(true);
    setError(null);
    try {
      await api(`/webhook-deliveries/${delivery.id}/retry`, { method: "POST" });
      onRetried();
      onClose();
    } catch (err) {
      setError(err instanceof ApiError ? String((err.detail as { detail?: string })?.detail ?? err.message) : "Ошибка повтора");
    } finally {
      setRetrying(false);
    }
  }

  if (!delivery) return null;

  const canRetry = delivery.status === "failed" || delivery.status === "retrying";
  const responseBodyTruncated = delivery.last_response_body && delivery.last_response_body.length >= 2048;

  return (
    <Modal
      open={open}
      title={`${delivery.event} · #${delivery.id}`}
      onClose={onClose}
      width="lg"
      footer={
        canRetry ? (
          <>
            <button className="btn-ghost" onClick={onClose}>Закрыть</button>
            <button className="btn-primary" onClick={handleRetry} disabled={retrying}>
              {retrying ? "Повтор…" : <><i className="bi bi-arrow-clockwise" /> Повторить</>}
            </button>
          </>
        ) : (
          <button className="btn-ghost" onClick={onClose}>Закрыть</button>
        )
      }
    >
      <div className="space-y-4">
        {error && (
          <div className="rounded-md bg-danger/10 text-danger px-4 py-2 text-sm">{error}</div>
        )}

        <div className="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
          <div>
            <span className="text-gray-500 text-xs uppercase tracking-wide block mb-0.5">Вебхук ID</span>
            <span className="text-gray-900">#{delivery.webhook_id}</span>
          </div>
          <div>
            <span className="text-gray-500 text-xs uppercase tracking-wide block mb-0.5">Событие</span>
            <span className="text-gray-900 font-mono text-xs">{delivery.event}</span>
          </div>
          <div>
            <span className="text-gray-500 text-xs uppercase tracking-wide block mb-0.5">Статус</span>
            <StatusBadge status={delivery.status} />
          </div>
          <div>
            <span className="text-gray-500 text-xs uppercase tracking-wide block mb-0.5">HTTP</span>
            <span className="text-gray-900">{delivery.last_http_code ?? "—"}</span>
          </div>
          <div>
            <span className="text-gray-500 text-xs uppercase tracking-wide block mb-0.5">Попытка</span>
            <span className="text-gray-900">{delivery.attempt}</span>
          </div>
          <div>
            <span className="text-gray-500 text-xs uppercase tracking-wide block mb-0.5">Создан</span>
            <span className="text-gray-900">{formatDateTime(delivery.created_at)}</span>
          </div>
          <div>
            <span className="text-gray-500 text-xs uppercase tracking-wide block mb-0.5">Завершён</span>
            <span className="text-gray-900">{formatDateTime(delivery.finished_at)}</span>
          </div>
          <div>
            <span className="text-gray-500 text-xs uppercase tracking-wide block mb-0.5">Следующий повтор</span>
            <span className="text-gray-900">{formatDateTime(delivery.next_retry_at)}</span>
          </div>
        </div>

        <div>
          <div className="text-xs text-gray-500 uppercase tracking-wide mb-1">Payload</div>
          <div className="bg-gray-50 border border-gray-200 rounded-md p-3 max-h-64 overflow-y-auto">
            <pre className="font-mono text-xs text-gray-800 whitespace-pre-wrap break-all">
              {JSON.stringify(delivery.payload, null, 2)}
            </pre>
          </div>
        </div>

        {delivery.last_response_body && (
          <div>
            <div className="text-xs text-gray-500 uppercase tracking-wide mb-1">
              Ответ сервера
              {responseBodyTruncated && (
                <span className="ml-2 text-warning">(обрезано до 2KB)</span>
              )}
            </div>
            <div className="bg-gray-50 border border-gray-200 rounded-md p-3 max-h-48 overflow-y-auto">
              <pre className="font-mono text-xs text-gray-800 whitespace-pre-wrap break-all">
                {delivery.last_response_body}
              </pre>
            </div>
          </div>
        )}

        {delivery.last_error && (
          <div>
            <div className="text-xs text-gray-500 uppercase tracking-wide mb-1">Ошибка</div>
            <div className="bg-danger/5 border border-danger/20 rounded-md p-3">
              <p className="text-sm text-danger">{delivery.last_error}</p>
            </div>
          </div>
        )}
      </div>
    </Modal>
  );
}
