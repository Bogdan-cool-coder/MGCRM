"use client";

import { useState } from "react";
import clsx from "clsx";
import { KindBadge } from "@/components/Inbox/KindBadge";
import { WebhookCell } from "@/components/Channels/WebhookCell";
import { api, ApiError } from "@/lib/api";
import type { Channel } from "@/lib/types";

interface Props {
  channels: Channel[];
  isAdmin: boolean;
  onEdit: (channel: Channel) => void;
  onChanged: () => void;
}

/** Inline toggle is_active. */
function ActiveToggle({
  channelId,
  isActive,
  disabled,
  onChanged,
}: {
  channelId: number;
  isActive: boolean;
  disabled: boolean;
  onChanged: () => void;
}) {
  const [pending, setPending] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  async function toggle(e: React.MouseEvent) {
    e.stopPropagation();
    if (pending || disabled) return;
    setPending(true);
    setErr(null);
    try {
      await api(`/channels/${channelId}`, {
        method: "PATCH",
        body: { is_active: !isActive },
      });
      onChanged();
    } catch (error) {
      setErr(
        error instanceof ApiError
          ? String((error.detail as { detail?: string })?.detail ?? error.message)
          : "Не удалось переключить",
      );
    } finally {
      setPending(false);
    }
  }

  return (
    <div className="flex flex-col items-start gap-0.5">
      <button
        type="button"
        onClick={toggle}
        disabled={pending || disabled}
        className={clsx(
          "relative inline-flex h-5 w-9 items-center rounded-full transition-colors",
          isActive ? "bg-success" : "bg-gray-300",
          (pending || disabled) && "opacity-60 cursor-not-allowed",
        )}
        title={isActive ? "Активен" : "Отключён"}
      >
        <span
          className={clsx(
            "inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform",
            isActive ? "translate-x-[18px]" : "translate-x-0.5",
          )}
        />
      </button>
      {err && <span className="text-xs text-danger">{err}</span>}
    </div>
  );
}

export function ChannelsTable({ channels, isAdmin, onEdit, onChanged }: Props) {
  const [deleting, setDeleting] = useState<number | null>(null);
  const [delErr, setDelErr] = useState<string | null>(null);

  async function del(ch: Channel) {
    if (!confirm(`Удалить канал «${ch.name}»? Это действие необратимо.`)) return;
    setDeleting(ch.id);
    setDelErr(null);
    try {
      await api(`/channels/${ch.id}`, { method: "DELETE" });
      onChanged();
    } catch (e) {
      setDelErr(
        e instanceof ApiError
          ? String((e.detail as { detail?: string })?.detail ?? e.message)
          : "Не удалось удалить",
      );
    } finally {
      setDeleting(null);
    }
  }

  if (channels.length === 0) {
    return (
      <div className="space-y-2">
        {delErr && (
          <div className="rounded-lg bg-danger/10 text-danger px-4 py-2.5 text-sm flex items-center gap-2">
            <i className="bi bi-exclamation-circle shrink-0" />
            {delErr}
          </div>
        )}
        <div className="card rounded-2xl shadow-elev-1 border border-gray-100 dark:border-gray-800">
          <div className="py-16 text-center text-sm text-gray-500 dark:text-gray-400">
            <i className="bi bi-broadcast text-3xl block mb-3 opacity-30" />
            Каналов пока нет
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-2">
      {delErr && (
        <div className="rounded-lg bg-danger/10 text-danger px-4 py-2.5 text-sm flex items-center gap-2">
          <i className="bi bi-exclamation-circle shrink-0" />
          {delErr}
        </div>
      )}
      <div className="card rounded-2xl shadow-elev-1 overflow-hidden border border-gray-100 dark:border-gray-800">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
              <tr>
                <th className="text-left px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Активен</th>
                <th className="text-left px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Название</th>
                <th className="text-left px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Тип</th>
                <th className="text-left px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Webhook URL</th>
                <th className="text-left px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Источник по умолчанию</th>
                <th className="w-20" />
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
              {channels.map((ch) => (
                <tr
                  key={ch.id}
                  className="group hover:bg-primary/[0.03] dark:hover:bg-primary/[0.06] cursor-pointer transition-colors duration-100"
                  onClick={() => onEdit(ch)}
                >
                  <td className="px-4 py-2.5 align-middle" onClick={(e) => e.stopPropagation()}>
                    <ActiveToggle
                      channelId={ch.id}
                      isActive={ch.is_active}
                      disabled={!isAdmin}
                      onChanged={onChanged}
                    />
                  </td>
                  <td className="px-4 py-2.5">
                    <div className="font-medium text-gray-900 dark:text-gray-100">{ch.name}</div>
                    <div className="text-xs text-gray-400 dark:text-gray-500 mt-0.5">#{ch.id}</div>
                  </td>
                  <td className="px-4 py-2.5"><KindBadge kind={ch.kind} /></td>
                  <td className="px-4 py-2.5" onClick={(e) => e.stopPropagation()}>
                    <WebhookCell
                      channel={ch}
                      isAdmin={isAdmin}
                      onRegenerated={() => onChanged()}
                    />
                  </td>
                  <td className="px-4 py-2.5 text-xs font-mono text-gray-600 dark:text-gray-400">
                    {ch.default_lead_source ?? "—"}
                  </td>
                  <td className="px-4 py-2.5 text-right" onClick={(e) => e.stopPropagation()}>
                    <div className="opacity-0 group-hover:opacity-100 transition-opacity duration-100 flex items-center justify-end gap-1">
                      <button
                        className="btn-ghost text-xs"
                        title="Редактировать"
                        onClick={() => onEdit(ch)}
                      >
                        <i className="bi bi-pencil" />
                      </button>
                      {isAdmin && (
                        <button
                          className="btn-ghost text-xs text-danger"
                          title="Удалить"
                          disabled={deleting === ch.id}
                          onClick={() => del(ch)}
                        >
                          {deleting === ch.id
                            ? <i className="bi bi-arrow-clockwise animate-spin" />
                            : <i className="bi bi-trash" />
                          }
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
