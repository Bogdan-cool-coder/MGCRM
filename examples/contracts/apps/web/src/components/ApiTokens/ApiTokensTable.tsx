"use client";

import { useState } from "react";
import { api, ApiError } from "@/lib/api";
import { useMe } from "@/lib/auth";
import type { APIToken } from "@/lib/types";
import { ScopeBadgeList } from "./ScopeBadge";
import { formatDate } from "@/lib/dates";

interface Props {
  tokens: APIToken[];
  onChanged: () => void;
}

function formatRelative(dateStr: string | null): string {
  if (!dateStr) return "—";
  const diff = Date.now() - new Date(dateStr).getTime();
  const min = Math.floor(diff / 60000);
  if (min < 2) return "только что";
  if (min < 60) return `${min} мин. назад`;
  const hours = Math.floor(min / 60);
  if (hours < 24) return `${hours} ч. назад`;
  const days = Math.floor(hours / 24);
  if (days < 30) return `${days} д. назад`;
  return new Date(dateStr).toLocaleDateString("ru-RU");
}

function tokenStatus(t: APIToken): { label: string; cls: string } {
  if (t.revoked_at !== null) {
    return {
      label: `Отозван ${formatDate(t.revoked_at)}`,
      cls: "bg-danger/10 text-danger",
    };
  }
  if (t.expires_at && new Date(t.expires_at) < new Date()) {
    return { label: "Истёк", cls: "bg-warning/10 text-warning" };
  }
  if (!t.is_active) {
    return { label: "Неактивен", cls: "bg-gray-100 text-gray-500" };
  }
  return { label: "Активен", cls: "bg-success/10 text-success" };
}

export function ApiTokensTable({ tokens, onChanged }: Props) {
  const { user } = useMe();
  const isAdmin = user?.role === "admin";
  const [busy, setBusy] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function handleRevoke(id: number) {
    setBusy(id);
    setError(null);
    try {
      await api(`/api/api-tokens/${id}/revoke`, { method: "PATCH" });
      onChanged();
    } catch (err) {
      setError(err instanceof ApiError ? String((err.detail as { detail?: string })?.detail ?? err.message) : "Ошибка");
    } finally {
      setBusy(null);
    }
  }

  async function handleDelete(id: number) {
    if (!confirm("Удалить токен? Это действие нельзя отменить.")) return;
    setBusy(id);
    setError(null);
    try {
      await api(`/api/api-tokens/${id}`, { method: "DELETE" });
      onChanged();
    } catch (err) {
      setError(err instanceof ApiError ? String((err.detail as { detail?: string })?.detail ?? err.message) : "Ошибка");
    } finally {
      setBusy(null);
    }
  }

  if (tokens.length === 0) return null;

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
                <th className="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Доступы</th>
                <th className="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Создан</th>
                <th className="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Последнее использование</th>
                <th className="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Истекает</th>
                <th className="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Лимит/ч</th>
                <th className="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Статус</th>
                <th className="px-4 py-2.5 w-32" />
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
              {tokens.map((t) => {
                const st = tokenStatus(t);
                const canRevoke = t.is_active && t.revoked_at === null;
                const canDelete = isAdmin && (!t.is_active || t.revoked_at !== null || (t.expires_at !== null && new Date(t.expires_at) < new Date()));
                return (
                  <tr key={t.id} className="group hover:bg-primary/[0.03] dark:hover:bg-primary/[0.06] transition-colors duration-100">
                    <td className="px-4 py-2.5 font-medium text-gray-900 dark:text-gray-100">{t.name}</td>
                    <td className="px-4 py-2.5">
                      <ScopeBadgeList scopes={t.scopes} max={3} />
                    </td>
                    <td className="px-4 py-2.5 text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">{formatDate(t.created_at)}</td>
                    <td className="px-4 py-2.5 text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">
                      {t.last_used_at ? (
                        <span title={t.last_used_ip ?? undefined}>
                          {formatRelative(t.last_used_at)}
                          {t.last_used_ip && (
                            <span className="ml-1 text-gray-400 dark:text-gray-500">({t.last_used_ip})</span>
                          )}
                        </span>
                      ) : "—"}
                    </td>
                    <td className="px-4 py-2.5 text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">
                      {t.expires_at ? formatDate(t.expires_at) : <span className="text-gray-400 dark:text-gray-500">Бессрочный</span>}
                    </td>
                    <td className="px-4 py-2.5 text-xs text-gray-500 dark:text-gray-400 tabular-nums whitespace-nowrap">
                      {(t.rate_limit_per_hour ?? 1000).toLocaleString("ru-RU")}
                    </td>
                    <td className="px-4 py-2.5">
                      <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${st.cls}`}>
                        {st.label}
                      </span>
                    </td>
                    <td className="px-4 py-2.5 text-right">
                      <div className="opacity-0 group-hover:opacity-100 transition-opacity duration-100 flex items-center justify-end gap-1">
                        {canRevoke && (
                          <button
                            className="btn-ghost text-warning text-xs"
                            disabled={busy === t.id}
                            onClick={() => handleRevoke(t.id)}
                          >
                            <i className="bi bi-slash-circle mr-1" />
                            Отозвать
                          </button>
                        )}
                        {canDelete && (
                          <button
                            className="btn-ghost text-danger text-xs"
                            disabled={busy === t.id}
                            onClick={() => handleDelete(t.id)}
                          >
                            <i className="bi bi-trash" />
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
    </div>
  );
}
