"use client";

import { useState } from "react";
import { api, ApiError } from "@/lib/api";
import type { Webhook } from "@/lib/types";
import { EventBadgeList } from "./EventBadge";

interface Props {
  webhooks: Webhook[];
  onEdit: (wh: Webhook) => void;
  onChanged: () => void;
}

type TestResult = { kind: "ok" | "err"; text: string };

export function WebhooksTable({ webhooks, onEdit, onChanged }: Props) {
  const [busy, setBusy] = useState<number | null>(null);
  const [testResults, setTestResults] = useState<Record<number, TestResult>>({});
  const [error, setError] = useState<string | null>(null);

  async function handleTest(wh: Webhook) {
    const event = wh.event_subscriptions[0] ?? "lead.created";
    setBusy(wh.id);
    setError(null);
    try {
      const delivery = await api<{ last_http_code: number | null; status: string }>(
        `/webhooks/${wh.id}/test`,
        { method: "POST", body: { event } },
      );
      const code = delivery.last_http_code;
      const isOk = delivery.status === "success" || (code !== null && code >= 200 && code < 300);
      setTestResults((prev) => ({
        ...prev,
        [wh.id]: {
          kind: isOk ? "ok" : "err",
          text: code !== null ? `${isOk ? "OK" : "Ошибка"} ${code}` : (isOk ? "OK" : "Ошибка"),
        },
      }));
      setTimeout(() => {
        setTestResults((prev) => {
          const next = { ...prev };
          delete next[wh.id];
          return next;
        });
      }, 3000);
    } catch (err) {
      const msg = err instanceof ApiError ? String((err.detail as { detail?: string })?.detail ?? err.message) : "Ошибка теста";
      setTestResults((prev) => ({ ...prev, [wh.id]: { kind: "err", text: msg } }));
      setTimeout(() => {
        setTestResults((prev) => {
          const next = { ...prev };
          delete next[wh.id];
          return next;
        });
      }, 3000);
    } finally {
      setBusy(null);
    }
  }

  async function handleDelete(wh: Webhook) {
    if (!confirm(`Удалить вебхук «${wh.name}»?`)) return;
    setBusy(wh.id);
    setError(null);
    try {
      await api(`/webhooks/${wh.id}`, { method: "DELETE" });
      onChanged();
    } catch (err) {
      setError(err instanceof ApiError ? String((err.detail as { detail?: string })?.detail ?? err.message) : "Ошибка удаления");
    } finally {
      setBusy(null);
    }
  }

  if (webhooks.length === 0) return null;

  return (
    <div>
      {error && (
        <div className="mb-3 rounded-lg bg-danger/10 text-danger px-4 py-2.5 text-sm flex items-center gap-2">
          <i className="bi bi-exclamation-circle shrink-0" />
          {error}
        </div>
      )}
      <div className="card rounded-2xl shadow-elev-1 overflow-hidden border border-gray-100 dark:border-gray-800">
        <div className="overflow-x-auto">
          <table className="min-w-full text-sm">
            <thead className="border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
              <tr>
                <th className="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Название</th>
                <th className="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">URL</th>
                <th className="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">События</th>
                <th className="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Статус</th>
                <th className="px-4 py-2.5 w-40" />
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
              {webhooks.map((wh) => {
                const testResult = testResults[wh.id];
                return (
                  <tr key={wh.id} className="group hover:bg-primary/[0.03] dark:hover:bg-primary/[0.06] transition-colors duration-100">
                    <td className="px-4 py-2.5 font-medium text-gray-900 dark:text-gray-100">{wh.name}</td>
                    <td className="px-4 py-2.5 max-w-[220px]">
                      <span className="block truncate text-gray-500 dark:text-gray-400 text-xs font-mono" title={wh.url}>
                        {wh.url}
                      </span>
                    </td>
                    <td className="px-4 py-2.5">
                      <EventBadgeList events={wh.event_subscriptions} max={3} />
                    </td>
                    <td className="px-4 py-2.5">
                      <span
                        className={[
                          "inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium",
                          wh.is_active
                            ? "bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400"
                            : "bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400",
                        ].join(" ")}
                      >
                        <span className={`w-1.5 h-1.5 rounded-full ${wh.is_active ? "bg-success-500" : "bg-gray-400"}`} />
                        {wh.is_active ? "Активен" : "Отключён"}
                      </span>
                    </td>
                    <td className="px-4 py-2.5 text-right">
                      <div className="opacity-0 group-hover:opacity-100 transition-opacity duration-100 flex items-center justify-end gap-1">
                        <button
                          className="btn-ghost text-xs"
                          disabled={busy === wh.id}
                          onClick={() => handleTest(wh)}
                          title="Тестовая доставка"
                        >
                          {busy === wh.id ? (
                            <i className="bi bi-arrow-clockwise animate-spin" />
                          ) : (
                            <i className="bi bi-send-check" />
                          )}
                          {" "}Тест
                        </button>
                        {testResult && (
                          <span
                            className={[
                              "text-xs rounded-full px-2 py-0.5 font-medium",
                              testResult.kind === "ok"
                                ? "bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400"
                                : "bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400",
                            ].join(" ")}
                          >
                            {testResult.text}
                          </span>
                        )}
                        <button
                          className="btn-ghost text-xs"
                          disabled={busy === wh.id}
                          onClick={() => onEdit(wh)}
                          title="Редактировать"
                        >
                          <i className="bi bi-pencil" />
                        </button>
                        <button
                          className="btn-ghost text-danger text-xs"
                          disabled={busy === wh.id}
                          onClick={() => handleDelete(wh)}
                          title="Удалить"
                        >
                          <i className="bi bi-trash" />
                        </button>
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
