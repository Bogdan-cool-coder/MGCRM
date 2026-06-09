"use client";

import { useState } from "react";
import clsx from "clsx";
import { api, ApiError } from "@/lib/api";
import type { Channel, CrmForm } from "@/lib/types";

interface Props {
  forms: CrmForm[];
  channels: Channel[] | undefined;
  canMutate: boolean;
  onEdit: (form: CrmForm) => void;
  onChanged: () => void;
}

function ActiveToggle({
  formId,
  isActive,
  disabled,
  onChanged,
}: {
  formId: number;
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
      await api(`/forms/${formId}`, { method: "PATCH", body: { is_active: !isActive } });
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
        title={isActive ? "Активна" : "Отключена"}
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

function PublicLinkCell({ slug }: { slug: string }) {
  const [copied, setCopied] = useState(false);
  const origin = typeof window !== "undefined" ? window.location.origin : "";
  const url = `${origin}/f/${slug}`;

  async function copy() {
    try {
      await navigator.clipboard.writeText(url);
      setCopied(true);
      setTimeout(() => setCopied(false), 1500);
    } catch {
      alert(url);
    }
  }

  return (
    <div className="flex items-center gap-1 text-xs">
      <a
        href={`/f/${slug}`}
        target="_blank"
        rel="noopener noreferrer"
        className="text-primary hover:underline font-mono"
        onClick={(e) => e.stopPropagation()}
      >
        /f/{slug}
      </a>
      <button
        type="button"
        onClick={(e) => {
          e.stopPropagation();
          void copy();
        }}
        className="text-gray-500 hover:text-primary"
        title="Копировать полный URL"
      >
        <i className={`bi ${copied ? "bi-check2" : "bi-copy"}`} />
      </button>
    </div>
  );
}

export function FormsTable({ forms, channels, canMutate, onEdit, onChanged }: Props) {
  const [deleting, setDeleting] = useState<number | null>(null);
  const [delErr, setDelErr] = useState<string | null>(null);
  const channelById = new Map((channels ?? []).map((c) => [c.id, c]));

  async function del(f: CrmForm) {
    if (!confirm(`Удалить форму «${f.name}»? Действие необратимо.`)) return;
    setDeleting(f.id);
    setDelErr(null);
    try {
      await api(`/forms/${f.id}`, { method: "DELETE" });
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

  if (forms.length === 0) {
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
            <i className="bi bi-ui-checks-grid text-3xl block mb-3 opacity-30" />
            Форм пока нет
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
                <th className="text-left px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Активна</th>
                <th className="text-left px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Название</th>
                <th className="text-left px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Публичный URL</th>
                <th className="text-left px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Полей</th>
                <th className="text-left px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Канал</th>
                <th className="w-20" />
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
              {forms.map((f) => {
                const ch = f.channel_id ? channelById.get(f.channel_id) : undefined;
                return (
                  <tr
                    key={f.id}
                    className="group hover:bg-primary/[0.03] dark:hover:bg-primary/[0.06] cursor-pointer transition-colors duration-100"
                    onClick={() => onEdit(f)}
                  >
                    <td className="px-4 py-2.5 align-middle" onClick={(e) => e.stopPropagation()}>
                      <ActiveToggle
                        formId={f.id}
                        isActive={f.is_active}
                        disabled={!canMutate}
                        onChanged={onChanged}
                      />
                    </td>
                    <td className="px-4 py-2.5">
                      <div className="font-medium text-gray-900 dark:text-gray-100">{f.name}</div>
                      <div className="text-xs text-gray-400 dark:text-gray-500 mt-0.5">#{f.id}</div>
                    </td>
                    <td className="px-4 py-2.5"><PublicLinkCell slug={f.public_slug} /></td>
                    <td className="px-4 py-2.5 text-sm text-gray-600 dark:text-gray-400">{f.fields.length}</td>
                    <td className="px-4 py-2.5 text-sm">
                      {ch ? (
                        <span className="text-gray-700 dark:text-gray-300">{ch.name}{" "}
                          <span className="text-xs text-gray-400 dark:text-gray-500">({ch.kind})</span>
                        </span>
                      ) : f.channel_id ? (
                        <span className="text-xs text-gray-400 dark:text-gray-500">#{f.channel_id}</span>
                      ) : (
                        <span className="text-xs text-danger">не задан</span>
                      )}
                    </td>
                    <td className="px-4 py-2.5 text-right" onClick={(e) => e.stopPropagation()}>
                      <div className="opacity-0 group-hover:opacity-100 transition-opacity duration-100 flex items-center justify-end gap-1">
                        <button
                          className="btn-ghost text-xs"
                          title="Редактировать"
                          onClick={() => onEdit(f)}
                        >
                          <i className="bi bi-pencil" />
                        </button>
                        {canMutate && (
                          <button
                            className="btn-ghost text-xs text-danger"
                            title="Удалить"
                            disabled={deleting === f.id}
                            onClick={() => del(f)}
                          >
                            {deleting === f.id
                              ? <i className="bi bi-arrow-clockwise animate-spin" />
                              : <i className="bi bi-trash" />}
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
