"use client";

import { useState } from "react";
import useSWR from "swr";
import { EmptyState } from "@/components/EmptyState";
import { Modal } from "@/components/Modal";
import { api, ApiError, fetcher } from "@/lib/api";
import type { OAuthClient, OAuthClientCreateResponse } from "@/lib/types";

const AVAILABLE_SCOPES = [
  "read:leads",
  "write:leads",
  "read:deals",
  "write:deals",
  "read:contacts",
  "write:contacts",
  "read:companies",
  "write:companies",
  "read:contracts",
  "write:contracts",
  "read:subscriptions",
  "write:subscriptions",
];

function OAuthClientRow({
  client,
  onEdit,
  onDelete,
}: {
  client: OAuthClient;
  onEdit: () => void;
  onDelete: () => void;
}) {
  return (
    <tr className="group hover:bg-primary/[0.03] dark:hover:bg-primary/[0.06] transition-colors duration-100">
      <td className="px-4 py-2.5">
        <div className="font-medium text-gray-900 dark:text-gray-100">{client.name}</div>
        <div className="text-xs text-gray-400 dark:text-gray-500 font-mono mt-0.5">{client.client_id}</div>
      </td>
      <td className="px-4 py-2.5 text-xs text-gray-500 dark:text-gray-400">
        {client.redirect_uris.length > 0 ? (
          <div className="space-y-0.5">
            {client.redirect_uris.map((uri) => (
              <div key={uri} className="font-mono">{uri}</div>
            ))}
          </div>
        ) : (
          "—"
        )}
      </td>
      <td className="px-4 py-2.5">
        <div className="flex flex-wrap gap-1">
          {client.scopes.map((s) => (
            <span key={s} className="badge text-[10px] bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400">
              {s}
            </span>
          ))}
        </div>
      </td>
      <td className="px-4 py-2.5">
        <span
          className={[
            "inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium",
            client.is_active
              ? "bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400"
              : "bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400",
          ].join(" ")}
        >
          <span className={`w-1.5 h-1.5 rounded-full ${client.is_active ? "bg-success-500" : "bg-gray-400"}`} />
          {client.is_active ? "Активно" : "Отключено"}
        </span>
      </td>
      <td className="px-4 py-2.5">
        <div className="opacity-0 group-hover:opacity-100 transition-opacity duration-100 flex items-center gap-1">
          <button className="btn-ghost text-xs" onClick={onEdit}>
            <i className="bi bi-pencil" />
          </button>
          <button className="btn-ghost text-xs text-danger" onClick={onDelete}>
            <i className="bi bi-trash" />
          </button>
        </div>
      </td>
    </tr>
  );
}

interface FormState {
  name: string;
  redirectUris: string;
  scopes: string[];
  isActive: boolean;
}

function defaultForm(): FormState {
  return { name: "", redirectUris: "", scopes: [], isActive: true };
}

export function OAuthPanel() {
  const { data: clients, error, isLoading, mutate } = useSWR<OAuthClient[]>("/oauth/clients", fetcher);

  const [createOpen, setCreateOpen] = useState(false);
  const [editClient, setEditClient] = useState<OAuthClient | null>(null);
  const [form, setForm] = useState<FormState>(defaultForm());
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [revealSecret, setRevealSecret] = useState<string | null>(null);

  function openCreate() {
    setForm(defaultForm());
    setFormError(null);
    setEditClient(null);
    setCreateOpen(true);
  }

  function openEdit(client: OAuthClient) {
    setForm({
      name: client.name,
      redirectUris: client.redirect_uris.join("\n"),
      scopes: [...client.scopes],
      isActive: client.is_active,
    });
    setFormError(null);
    setEditClient(client);
    setCreateOpen(true);
  }

  function toggleScope(scope: string) {
    setForm((prev) => ({
      ...prev,
      scopes: prev.scopes.includes(scope)
        ? prev.scopes.filter((s) => s !== scope)
        : [...prev.scopes, scope],
    }));
  }

  async function handleSubmit() {
    if (!form.name.trim()) {
      setFormError("Введите название приложения");
      return;
    }
    const redirectUris = form.redirectUris
      .split("\n")
      .map((u) => u.trim())
      .filter(Boolean);
    if (redirectUris.length === 0) {
      setFormError("Введите хотя бы один Redirect URI");
      return;
    }
    if (form.scopes.length === 0) {
      setFormError("Выберите хотя бы один доступ");
      return;
    }

    setSubmitting(true);
    setFormError(null);
    try {
      if (editClient) {
        await api<OAuthClient>(`/oauth/clients/${editClient.id}`, {
          method: "PATCH",
          body: {
            name: form.name.trim(),
            redirect_uris: redirectUris,
            scopes: form.scopes,
            is_active: form.isActive,
          },
        });
        setCreateOpen(false);
        void mutate();
      } else {
        const result = await api<OAuthClientCreateResponse>("/oauth/clients", {
          method: "POST",
          body: {
            name: form.name.trim(),
            redirect_uris: redirectUris,
            scopes: form.scopes,
          },
        });
        setCreateOpen(false);
        void mutate();
        setRevealSecret(result.plaintext_secret);
      }
    } catch (err) {
      setFormError(
        err instanceof ApiError
          ? String((err.detail as { detail?: string })?.detail ?? err.message)
          : "Ошибка сохранения"
      );
    } finally {
      setSubmitting(false);
    }
  }

  async function handleDelete(client: OAuthClient) {
    if (!confirm(`Удалить OAuth приложение «${client.name}»?`)) return;
    try {
      await api(`/oauth/clients/${client.id}`, { method: "DELETE" });
      void mutate();
    } catch {
      // silently handle
    }
  }

  return (
    <>
      <div className="flex items-center justify-between mb-6">
        <div>
          <h2 className="text-base font-semibold text-gray-900 dark:text-gray-100">OAuth приложения</h2>
          <p className="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
            Управление сторонними приложениями, имеющими доступ к MACRO CRM
          </p>
        </div>
        <button className="btn-primary" onClick={openCreate}>
          <i className="bi bi-plus-lg mr-1" />
          Создать приложение
        </button>
      </div>

      {isLoading && (
        <div className="card rounded-2xl shadow-elev-1 overflow-hidden border border-gray-100 dark:border-gray-800 animate-pulse">
          {[1, 2, 3].map((i) => (
            <div key={i} className="h-14 border-b border-gray-100 dark:border-gray-800 last:border-0 bg-white dark:bg-gray-900" />
          ))}
        </div>
      )}

      {error && (
        <div className="rounded-lg bg-danger/10 text-danger px-4 py-3 text-sm flex items-center gap-2">
          <i className="bi bi-exclamation-circle shrink-0" />
          Не удалось загрузить OAuth приложения
        </div>
      )}

      {!isLoading && !error && (!clients || clients.length === 0) && (
        <EmptyState
          icon="bi-key"
          title="Нет OAuth приложений"
          description="Создайте первое приложение для авторизации сторонних систем"
          cta={
            <button className="btn-primary" onClick={openCreate}>
              <i className="bi bi-plus-lg mr-1" />
              Создать приложение
            </button>
          }
        />
      )}

      {!isLoading && clients && clients.length > 0 && (
        <div className="card rounded-2xl shadow-elev-1 overflow-hidden border border-gray-100 dark:border-gray-800">
          <div className="overflow-x-auto">
            <table className="min-w-full text-sm">
              <thead className="border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
                <tr>
                  <th className="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Приложение</th>
                  <th className="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Redirect URIs</th>
                  <th className="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Доступы</th>
                  <th className="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Статус</th>
                  <th className="px-4 py-2.5 w-24" />
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                {clients.map((client) => (
                  <OAuthClientRow
                    key={client.id}
                    client={client}
                    onEdit={() => openEdit(client)}
                    onDelete={() => handleDelete(client)}
                  />
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* Create / Edit modal */}
      <Modal
        open={createOpen}
        title={editClient ? `Редактировать: ${editClient.name}` : "Создать OAuth приложение"}
        onClose={() => setCreateOpen(false)}
        width="md"
        isDirty={form.name.length > 0 || form.redirectUris.length > 0}
        footer={
          <>
            <button className="btn-ghost" onClick={() => setCreateOpen(false)} disabled={submitting}>
              Отмена
            </button>
            <button className="btn-primary" onClick={handleSubmit} disabled={submitting}>
              {submitting ? "Сохранение…" : editClient ? "Сохранить" : "Создать"}
            </button>
          </>
        }
      >
        <div className="space-y-5">
          {formError && (
            <div className="rounded-md bg-danger/10 text-danger px-4 py-2 text-sm">{formError}</div>
          )}
          <div>
            <label className="label">Название приложения <span className="text-danger">*</span></label>
            <input
              className="input"
              placeholder="Например: 1C Connector"
              value={form.name}
              onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
            />
          </div>
          <div>
            <label className="label">
              Redirect URIs <span className="text-danger">*</span>
              <span className="ml-1 text-xs text-gray-400 font-normal">(по одному на строку)</span>
            </label>
            <textarea
              className="input font-mono text-sm"
              rows={4}
              placeholder="https://your-app.com/oauth/callback"
              value={form.redirectUris}
              onChange={(e) => setForm((f) => ({ ...f, redirectUris: e.target.value }))}
            />
          </div>
          <div>
            <label className="label">
              Доступы <span className="text-danger">*</span>
            </label>
            <div className="grid grid-cols-2 gap-2 mt-1">
              {AVAILABLE_SCOPES.map((scope) => (
                <label key={scope} className="flex items-center gap-2 cursor-pointer select-none">
                  <input
                    type="checkbox"
                    checked={form.scopes.includes(scope)}
                    onChange={() => toggleScope(scope)}
                    className="rounded"
                  />
                  <span className="text-sm text-gray-700 dark:text-gray-300 font-mono">{scope}</span>
                </label>
              ))}
            </div>
          </div>
          {editClient && (
            <div>
              <label className="flex items-center gap-2 cursor-pointer select-none">
                <input
                  type="checkbox"
                  checked={form.isActive}
                  onChange={(e) => setForm((f) => ({ ...f, isActive: e.target.checked }))}
                  className="rounded"
                />
                <span className="text-sm font-medium text-gray-700 dark:text-gray-300">Приложение активно</span>
              </label>
            </div>
          )}
        </div>
      </Modal>

      {/* Secret reveal modal */}
      <Modal
        open={revealSecret !== null}
        title="OAuth Client Secret"
        onClose={() => setRevealSecret(null)}
        width="sm"
        footer={
          <button className="btn-primary" onClick={() => setRevealSecret(null)}>
            Готово
          </button>
        }
      >
        <div className="space-y-4">
          <div className="bg-danger/10 text-danger rounded-lg p-3 text-sm flex items-start gap-2">
            <i className="bi bi-exclamation-triangle shrink-0 mt-0.5" />
            <span>
              <strong>Сохрани секрет сейчас!</strong> Он отображается только один раз и не может
              быть восстановлен. Если потеряешь — создай новое приложение.
            </span>
          </div>
          <div>
            <label className="label text-xs">Client Secret</label>
            <div className="flex items-center gap-2 bg-gray-50 dark:bg-gray-700 rounded-lg px-4 py-3">
              <code className="flex-1 text-sm font-mono text-gray-800 dark:text-gray-200 break-all">
                {revealSecret}
              </code>
              <button
                className="btn-ghost text-xs shrink-0"
                onClick={() => {
                  if (revealSecret) void navigator.clipboard.writeText(revealSecret);
                }}
              >
                <i className="bi bi-clipboard" />
              </button>
            </div>
          </div>
        </div>
      </Modal>
    </>
  );
}
