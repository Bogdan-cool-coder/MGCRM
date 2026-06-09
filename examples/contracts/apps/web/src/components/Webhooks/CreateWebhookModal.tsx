"use client";

import { useEffect, useState } from "react";
import useSWR from "swr";
import { Modal } from "@/components/Modal";
import { api, ApiError, fetcher } from "@/lib/api";
import type { Webhook, WebhookCreateResponse } from "@/lib/types";

type RotateStep = "idle" | "confirm" | "loading" | "revealed";

interface EventsResponse {
  events: string[];
  wildcard: string;
}

// Event categories for display grouping
const EVENT_CATEGORIES: { label: string; events: string[] }[] = [
  { label: "Лиды", events: ["lead.created", "lead.converted"] },
  { label: "Сделки", events: ["deal.created", "deal.stage_changed", "deal.won", "deal.lost"] },
  { label: "Договоры", events: ["contract.created", "contract.signed"] },
  { label: "Подписки", events: ["subscription.created", "subscription.health_changed"] },
  { label: "Контрагенты", events: ["counterparty.created"] },
];

interface Props {
  open: boolean;
  webhook?: Webhook | null;
  onClose: () => void;
  onCreated: (wh: WebhookCreateResponse) => void;
  onUpdated: () => void;
}

export function CreateWebhookModal({ open, webhook, onClose, onCreated, onUpdated }: Props) {
  const isEdit = !!webhook;

  const { data: eventsData } = useSWR<EventsResponse>(open ? "/webhooks/events" : null, fetcher);
  const availableEvents = eventsData?.events ?? [];

  const [name, setName] = useState("");
  const [url, setUrl] = useState("");
  const [selectedEvents, setSelectedEvents] = useState<Set<string>>(new Set());
  const [wildcardSelected, setWildcardSelected] = useState(false);
  const [headersJson, setHeadersJson] = useState("");
  const [secret, setSecret] = useState("");
  const [isActive, setIsActive] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Per-webhook delivery settings (only for edit mode)
  const [maxRetries, setMaxRetries] = useState("5");
  const [retryDelay, setRetryDelay] = useState("60");
  const [timeoutSec, setTimeoutSec] = useState("10");
  const [maxRetriesError, setMaxRetriesError] = useState<string | null>(null);
  const [retryDelayError, setRetryDelayError] = useState<string | null>(null);
  const [timeoutError, setTimeoutError] = useState<string | null>(null);

  // Rotate secret state
  const [rotateStep, setRotateStep] = useState<RotateStep>("idle");
  const [newPlaintextSecret, setNewPlaintextSecret] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);
  const [rotateError, setRotateError] = useState<string | null>(null);

  // Populate form when editing
  useEffect(() => {
    if (open && webhook) {
      setName(webhook.name);
      setUrl(webhook.url);
      const hasWildcard = webhook.event_subscriptions.includes("*");
      setWildcardSelected(hasWildcard);
      setSelectedEvents(new Set(hasWildcard ? [] : webhook.event_subscriptions));
      setHeadersJson(webhook.headers ? JSON.stringify(webhook.headers, null, 2) : "");
      setSecret("");
      setIsActive(webhook.is_active);
      // Delivery settings
      setMaxRetries(String(webhook.max_attempts ?? 5));
      setRetryDelay(String(webhook.backoff_seconds ?? 60));
      setTimeoutSec(String(webhook.timeout_seconds ?? 10));
    } else if (open && !webhook) {
      setName("");
      setUrl("");
      setSelectedEvents(new Set());
      setWildcardSelected(false);
      setHeadersJson("");
      setSecret("");
      setIsActive(true);
    }
    setError(null);
    // Reset rotate secret on open/close
    setRotateStep("idle");
    setNewPlaintextSecret(null);
    setCopied(false);
    setRotateError(null);
    // Reset delivery errors
    setMaxRetriesError(null);
    setRetryDelayError(null);
    setTimeoutError(null);
  }, [open, webhook]);

  function handleClose() {
    setError(null);
    onClose();
  }

  async function handleRotateSecret() {
    if (!webhook) return;
    setRotateStep("loading");
    setRotateError(null);
    try {
      const result = await api<{ plaintext_secret: string }>(
        `/webhooks/${webhook.id}/regenerate-secret`,
        { method: "POST" }
      );
      setNewPlaintextSecret(result.plaintext_secret);
      setRotateStep("revealed");
    } catch {
      setRotateError("Не удалось обновить секрет");
      setRotateStep("idle");
    }
  }

  function handleCopySecret() {
    if (!newPlaintextSecret) return;
    navigator.clipboard.writeText(newPlaintextSecret).then(() => {
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    }).catch(() => { /* silent */ });
  }

  function toggleEvent(event: string) {
    setSelectedEvents((prev) => {
      const next = new Set(prev);
      if (next.has(event)) {
        next.delete(event);
      } else {
        next.add(event);
      }
      return next;
    });
  }

  function toggleWildcard() {
    setWildcardSelected((v) => !v);
    if (!wildcardSelected) {
      setSelectedEvents(new Set());
    }
  }

  function generateSecret() {
    const arr = new Uint8Array(32);
    crypto.getRandomValues(arr);
    const hex = Array.from(arr).map((b) => b.toString(16).padStart(2, "0")).join("");
    setSecret(`whsec_${hex}`);
  }

  async function handleSubmit() {
    if (!name.trim()) { setError("Введите название"); return; }
    if (!url.trim()) { setError("Введите URL"); return; }
    if (!url.startsWith("https://")) { setError("URL должен начинаться с https://"); return; }

    const events = wildcardSelected ? ["*"] : Array.from(selectedEvents);
    if (events.length === 0) { setError("Выберите хотя бы одно событие"); return; }

    let headers: Record<string, string> | null = null;
    if (headersJson.trim()) {
      try {
        headers = JSON.parse(headersJson) as Record<string, string>;
        if (typeof headers !== "object" || Array.isArray(headers)) throw new Error("not object");
      } catch {
        setError("Доп. заголовки: невалидный JSON");
        return;
      }
    }

    // Validate delivery settings if editing
    if (isEdit) {
      const mr = Number(maxRetries);
      if (!Number.isInteger(mr) || mr < 1 || mr > 20) {
        setMaxRetriesError("Укажи число от 1 до 20");
        return;
      }
      const rd = Number(retryDelay);
      if (!Number.isInteger(rd) || rd < 10 || rd > 3600) {
        setRetryDelayError("Укажи число от 10 до 3600");
        return;
      }
      const to = Number(timeoutSec);
      if (!Number.isInteger(to) || to < 1 || to > 60) {
        setTimeoutError("Укажи число от 1 до 60");
        return;
      }
    }

    setSubmitting(true);
    setError(null);
    try {
      if (isEdit && webhook) {
        const mr = Number(maxRetries);
        const rd = Number(retryDelay);
        const to = Number(timeoutSec);
        const body: {
          name: string;
          url: string;
          event_subscriptions: string[];
          headers: Record<string, string> | null;
          is_active: boolean;
          max_attempts: number;
          backoff_seconds: number;
          timeout_seconds: number;
        } = {
          name: name.trim(),
          url: url.trim(),
          event_subscriptions: events,
          headers,
          is_active: isActive,
          max_attempts: mr,
          backoff_seconds: rd,
          timeout_seconds: to,
        };
        await api<Webhook>(`/webhooks/${webhook.id}`, { method: "PATCH", body });
        onUpdated();
      } else {
        const body: {
          name: string;
          url: string;
          event_subscriptions: string[];
          headers: Record<string, string> | null;
          is_active: boolean;
          secret?: string;
        } = {
          name: name.trim(),
          url: url.trim(),
          event_subscriptions: events,
          headers,
          is_active: isActive,
        };
        if (secret.trim()) {
          body.secret = secret.trim();
        }
        const result = await api<WebhookCreateResponse>("/webhooks", { method: "POST", body });
        onCreated(result);
      }
    } catch (err) {
      setError(err instanceof ApiError ? String((err.detail as { detail?: string })?.detail ?? err.message) : "Ошибка сохранения");
    } finally {
      setSubmitting(false);
    }
  }

  const isDirty = name.length > 0 || url.length > 0;

  return (
    <Modal
      open={open}
      title={isEdit ? "Редактировать вебхук" : "Создать вебхук"}
      onClose={handleClose}
      isDirty={isDirty}
      width="md"
      footer={
        <>
          <button className="btn-ghost" onClick={handleClose} disabled={submitting}>Отмена</button>
          <button className="btn-primary" onClick={handleSubmit} disabled={submitting}>
            {submitting ? "Сохранение…" : (isEdit ? "Сохранить" : "Создать")}
          </button>
        </>
      }
    >
      <div className="space-y-5">
        {error && (
          <div className="rounded-md bg-danger/10 text-danger px-4 py-2 text-sm">{error}</div>
        )}

        <div>
          <label className="label">Название <span className="text-danger">*</span></label>
          <input className="input" placeholder="Например: Zapier интеграция" value={name} onChange={(e) => setName(e.target.value)} />
        </div>

        <div>
          <label className="label">URL <span className="text-danger">*</span></label>
          <input className="input" type="url" placeholder="https://…" value={url} onChange={(e) => setUrl(e.target.value)} />
        </div>

        <div>
          <label className="label">Подписаться на события <span className="text-danger">*</span></label>

          <div className="mb-3 p-3 bg-gray-50 rounded-md border border-gray-200">
            <label className="flex items-center gap-2 cursor-pointer select-none">
              <input type="checkbox" checked={wildcardSelected} onChange={toggleWildcard} className="rounded" />
              <span className="text-sm font-medium">
                <i className="bi bi-broadcast-pin mr-1 text-primary" />
                Все события (*)
              </span>
            </label>
          </div>

          <div className={`space-y-2 ${wildcardSelected ? "opacity-40 pointer-events-none" : ""}`}>
            {EVENT_CATEGORIES.map((cat) => {
              const relevant = cat.events.filter((e) => availableEvents.length === 0 || availableEvents.includes(e));
              if (relevant.length === 0) return null;
              return (
                <div key={cat.label} className="flex items-start gap-4">
                  <span className="w-28 text-xs text-gray-500 shrink-0 pt-1">{cat.label}</span>
                  <div className="flex flex-wrap gap-x-4 gap-y-1">
                    {relevant.map((ev) => (
                      <label key={ev} className="flex items-center gap-1.5 cursor-pointer select-none">
                        <input type="checkbox" checked={selectedEvents.has(ev)} onChange={() => toggleEvent(ev)} className="rounded" />
                        <span className="text-sm text-gray-700">{ev}</span>
                      </label>
                    ))}
                  </div>
                </div>
              );
            })}
          </div>
        </div>

        {!isEdit && (
          <div>
            <label className="label">Секрет HMAC</label>
            <div className="flex gap-2">
              <input
                className="input flex-1 font-mono text-sm"
                placeholder="Оставьте пустым — сервер сгенерирует автоматически"
                value={secret}
                onChange={(e) => setSecret(e.target.value)}
              />
              <button type="button" className="btn-secondary shrink-0" onClick={generateSecret} title="Сгенерировать">
                <i className="bi bi-arrow-clockwise" />
              </button>
            </div>
            <p className="text-xs text-gray-400 mt-1">Минимум 16 символов, если задаёте вручную</p>
          </div>
        )}

        <div>
          <label className="label">Дополнительные заголовки (JSON, optional)</label>
          <textarea
            className="input font-mono text-xs min-h-[80px] resize-y"
            placeholder={`{"X-Custom-Header": "value"}`}
            value={headersJson}
            onChange={(e) => setHeadersJson(e.target.value)}
          />
        </div>

        {isEdit && (
          <div>
            <label className="flex items-center gap-2 cursor-pointer select-none">
              <input type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} className="rounded" />
              <span className="text-sm text-gray-700">Активен</span>
            </label>
          </div>
        )}

        {/* Rotate HMAC Secret (только редактирование) */}
        {isEdit && (
          <div className="border-t border-gray-100 pt-4 mt-2">
            <label className="label">Секрет HMAC</label>

            <button
              type="button"
              className="btn-secondary"
              onClick={() => setRotateStep("confirm")}
              disabled={submitting || rotateStep === "loading"}
            >
              <i className="bi bi-arrow-clockwise mr-2" />
              Сгенерировать новый секрет
            </button>

            {rotateStep === "confirm" && (
              <div className="mt-3 rounded-lg bg-warning/10 border border-warning/30 p-4">
                <p className="text-sm text-gray-800 font-medium mb-1">
                  <i className="bi bi-exclamation-triangle text-warning mr-1" />
                  Старый секрет перестанет работать
                </p>
                <p className="text-sm text-gray-600 mb-3">
                  Все подписчики получат ошибку HMAC при следующем событии,
                  пока не обновят секрет на своей стороне.
                </p>
                <div className="flex gap-2 justify-end">
                  <button type="button" className="btn-ghost" onClick={() => setRotateStep("idle")}>
                    Отмена
                  </button>
                  <button type="button" className="btn-primary" onClick={handleRotateSecret}>
                    Да, сгенерировать
                  </button>
                </div>
              </div>
            )}

            {rotateStep === "loading" && (
              <div className="mt-2 text-sm text-gray-500">
                <i className="bi bi-arrow-clockwise animate-spin mr-1" /> Генерируем…
              </div>
            )}

            {rotateStep === "revealed" && newPlaintextSecret && (
              <div className="mt-3 rounded-lg bg-success/10 border border-success/30 p-4">
                <p className="text-xs text-success font-medium mb-2">
                  <i className="bi bi-check-circle mr-1" />
                  Новый секрет сгенерирован. Сохрани — больше не покажем.
                </p>
                <div className="flex gap-2">
                  <input
                    className="input flex-1 font-mono text-xs"
                    value={newPlaintextSecret}
                    readOnly
                  />
                  <button
                    type="button"
                    className="btn-secondary shrink-0"
                    onClick={handleCopySecret}
                    title="Скопировать"
                  >
                    <i className={copied ? "bi bi-check2 text-success" : "bi bi-clipboard"} />
                  </button>
                </div>
                {copied && (
                  <p className="text-xs text-success mt-1">Скопировано в буфер</p>
                )}
              </div>
            )}

            {rotateError && (
              <p className="text-xs text-danger mt-1">{rotateError}</p>
            )}
          </div>
        )}

        {/* Per-webhook Delivery Settings (только редактирование) */}
        {isEdit && (
          <div className="border-t border-gray-100 pt-4 mt-2">
            <p className="text-sm font-medium text-gray-700 mb-3">Настройки доставки</p>
            <div className="grid grid-cols-3 gap-4">
              <div>
                <label className="label">
                  Макс. попыток <span className="text-danger">*</span>
                </label>
                <input
                  className="input"
                  type="number"
                  min={1}
                  max={20}
                  value={maxRetries}
                  onChange={(e) => { setMaxRetries(e.target.value); setMaxRetriesError(null); }}
                />
                {maxRetriesError && (
                  <p className="text-xs text-danger mt-1">{maxRetriesError}</p>
                )}
                <p className="text-xs text-gray-400 mt-1">Сколько раз пытаться доставить при ошибке (1–20)</p>
              </div>

              <div>
                <label className="label">
                  Задержка между попытками (сек) <span className="text-danger">*</span>
                </label>
                <input
                  className="input"
                  type="number"
                  min={10}
                  max={3600}
                  value={retryDelay}
                  onChange={(e) => { setRetryDelay(e.target.value); setRetryDelayError(null); }}
                />
                {retryDelayError && (
                  <p className="text-xs text-danger mt-1">{retryDelayError}</p>
                )}
                <p className="text-xs text-gray-400 mt-1">Пауза перед следующей попыткой (10–3600)</p>
              </div>

              <div>
                <label className="label">
                  Timeout запроса (сек) <span className="text-danger">*</span>
                </label>
                <input
                  className="input"
                  type="number"
                  min={1}
                  max={60}
                  value={timeoutSec}
                  onChange={(e) => { setTimeoutSec(e.target.value); setTimeoutError(null); }}
                />
                {timeoutError && (
                  <p className="text-xs text-danger mt-1">{timeoutError}</p>
                )}
                <p className="text-xs text-gray-400 mt-1">Время ожидания ответа от сервера (1–60)</p>
              </div>
            </div>
          </div>
        )}
      </div>
    </Modal>
  );
}
