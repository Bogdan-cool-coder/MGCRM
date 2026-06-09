"use client";

import { useState } from "react";
import useSWR, { mutate } from "swr";
import { PageHeader } from "@/components/PageHeader";
import { RoleGate } from "@/components/RoleGate";
import { Modal } from "@/components/Modal";
import { useToast } from "@/components/ui/Toast";
import { fetcher, api } from "@/lib/api";
import type { FinPermission, FinLegalEntity, User } from "@/lib/types";

/**
 * /finance/settings/permissions — Матрица прав модуля Финансы.
 * Гейтинг: только cfo/admin (capability manage_settings).
 */

const SETTINGS_ROLES = ["cfo", "admin"] as const;

const CAPABILITIES: { code: string; label: string }[] = [
  { code: "view_operations", label: "Просмотр операций" },
  { code: "view_all_operations", label: "Просмотр всех операций" },
  { code: "create_operation", label: "Создание операций" },
  { code: "post_operation", label: "Проведение операций" },
  { code: "manage_accounts", label: "Управление счетами" },
  { code: "manage_categories", label: "Управление статьями ДДС" },
  { code: "close_period", label: "Закрытие периодов" },
  { code: "view_management", label: "Управленческий просмотр" },
  { code: "view_reports", label: "Просмотр отчётов" },
  { code: "manage_settings", label: "Управление настройками" },
  { code: "create_manual_journal", label: "Ручные журналы (создание)" },
  { code: "post_manual_journal", label: "Ручные журналы (проведение)" },
  { code: "view_journal", label: "Просмотр GL-журнала" },
];

const ROLE_DEFAULTS: Record<string, Record<string, boolean>> = {
  accountant: {
    view_operations: true, view_all_operations: true, create_operation: true,
    post_operation: true, manage_accounts: true, manage_categories: true,
    create_manual_journal: true, post_manual_journal: true, view_journal: true,
    view_reports: true,
  },
  cfo: {
    view_operations: true, view_all_operations: true, create_operation: true,
    post_operation: true, manage_accounts: true, manage_categories: true,
    create_manual_journal: true, post_manual_journal: true, view_journal: true,
    close_period: true, view_reports: true, view_management: true, manage_settings: true,
  },
  director: {
    view_operations: true, view_all_operations: true, view_reports: true, view_management: true,
  },
  manager: { view_operations: true },
  admin: Object.fromEntries(CAPABILITIES.map((c) => [c.code, true])),
};

const SEEDED_ROLES = ["accountant", "cfo", "director", "manager", "admin"];

const ROLE_LABELS: Record<string, string> = {
  accountant: "Бухгалтер",
  cfo: "CFO",
  director: "Директор",
  manager: "Менеджер",
  admin: "Админ",
};

interface AddPermissionForm {
  subject_type: "role" | "user";
  role: string;
  user_id: string;
  legal_entity_id: string;
  capability: string;
  allowed: boolean;
}

const EMPTY_FORM: AddPermissionForm = {
  subject_type: "role",
  role: "accountant",
  user_id: "",
  legal_entity_id: "",
  capability: "view_operations",
  allowed: true,
};

function TableSkeleton() {
  return (
    <>
      {Array.from({ length: 3 }).map((_, i) => (
        <tr key={i} className="border-b border-gray-100 dark:border-gray-800 animate-pulse">
          {[32, 40, 24, 20, 16].map((w, j) => (
            <td key={j} className="px-4 py-2.5">
              <div className="h-4 bg-gray-100 dark:bg-gray-800 rounded" style={{ width: `${w * 4}px` }} />
            </td>
          ))}
        </tr>
      ))}
    </>
  );
}

export default function PermissionsPage() {
  const { toast } = useToast();
  const { data: perms, isLoading, error } = useSWR<FinPermission[]>("/api/finance/permissions", fetcher);
  const { data: entities } = useSWR<FinLegalEntity[]>("/api/finance/legal-entities", fetcher);
  const { data: users } = useSWR<User[]>("/api/users", fetcher);

  const [addOpen, setAddOpen] = useState(false);
  const [form, setForm] = useState<AddPermissionForm>(EMPTY_FORM);
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [deletingId, setDeletingId] = useState<number | null>(null);

  function setF<K extends keyof AddPermissionForm>(k: K, v: AddPermissionForm[K]) {
    setForm((prev) => ({ ...prev, [k]: v }));
  }

  async function handleAdd() {
    setFormError(null);
    if (form.subject_type === "role" && !form.role) { setFormError("Выберите роль"); return; }
    if (form.subject_type === "user" && !form.user_id) { setFormError("Выберите пользователя"); return; }
    if (!form.capability) { setFormError("Выберите capability"); return; }

    setSubmitting(true);
    try {
      const body: Record<string, unknown> = { capability: form.capability, allowed: form.allowed };
      if (form.subject_type === "role") body.role = form.role;
      else body.user_id = Number(form.user_id);
      if (form.legal_entity_id) body.legal_entity_id = Number(form.legal_entity_id);
      await api("/finance/permissions", { method: "POST", body });
      await mutate("/api/finance/permissions");
      setAddOpen(false);
      setForm(EMPTY_FORM);
      toast.success("Правило прав добавлено");
    } catch (err: unknown) {
      setFormError(err instanceof Error ? err.message : "Ошибка создания правила");
    } finally {
      setSubmitting(false);
    }
  }

  async function handleDelete(id: number) {
    if (!confirm("Удалить правило прав? Вернётся дефолт роли.")) return;
    setDeletingId(id);
    try {
      await api(`/finance/permissions/${id}`, { method: "DELETE" });
      await mutate("/api/finance/permissions");
      toast.success("Правило удалено");
    } catch (err: unknown) {
      toast.error(err instanceof Error ? err.message : "Не удалось удалить правило");
    } finally {
      setDeletingId(null);
    }
  }

  return (
    <RoleGate
      allowed={[...SETTINGS_ROLES]}
      fallback={
        <div className="p-8 text-center text-danger">
          <i className="bi bi-lock text-3xl mb-3 block" />
          <p>Недостаточно прав. Требуется роль CFO или Администратор.</p>
        </div>
      }
    >
      <div className="flex flex-col h-full">
        <PageHeader
          title="Права доступа — Финансы"
          description="Матрица дефолтных прав ролей и точечных override (manage_settings)"
          actions={
            <button className="btn-primary text-sm" onClick={() => { setAddOpen(true); setForm(EMPTY_FORM); setFormError(null); }}>
              <i className="bi bi-plus mr-1" />
              Добавить правило
            </button>
          }
        />

        <div className="p-6 flex flex-col gap-6">
          {/* Справочная матрица */}
          <section>
            <h2 className="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-3">
              Дефолтная матрица прав
            </h2>
            <div className="card overflow-x-auto">
              <table className="w-full text-xs min-w-[700px]">
                <thead className="bg-gray-50 dark:bg-gray-900/30 border-b border-gray-200 dark:border-gray-700">
                  <tr>
                    <th className="text-left px-4 py-2.5 text-gray-500 dark:text-gray-400 font-medium min-w-[200px]">
                      Capability
                    </th>
                    {SEEDED_ROLES.map((role) => (
                      <th key={role} className="text-center px-3 py-2.5 text-gray-500 dark:text-gray-400 font-medium">
                        {ROLE_LABELS[role] ?? role}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                  {CAPABILITIES.map((cap) => (
                    <tr key={cap.code} className="hover:bg-gray-50/50 dark:hover:bg-gray-800/30">
                      <td className="px-4 py-2 text-gray-700 dark:text-gray-300">
                        <div className="font-medium">{cap.label}</div>
                        <div className="font-mono text-[10px] text-gray-400 dark:text-gray-500">{cap.code}</div>
                      </td>
                      {SEEDED_ROLES.map((role) => {
                        const allowed = ROLE_DEFAULTS[role]?.[cap.code] ?? false;
                        return (
                          <td key={role} className="px-3 py-2 text-center">
                            {allowed ? (
                              <i className="bi bi-check-circle-fill text-success" title="Разрешено" />
                            ) : (
                              <i className="bi bi-dash text-gray-300 dark:text-gray-700" title="Запрещено" />
                            )}
                          </td>
                        );
                      })}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>

          {/* Точечные override */}
          <section>
            <h2 className="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-3">
              Точечные переопределения
              <span className="ml-2 font-normal normal-case text-gray-400">
                ({perms?.length ?? 0} записей)
              </span>
            </h2>

            {error && (
              <div className="card p-4 text-danger text-sm">
                <i className="bi bi-exclamation-triangle mr-2" />
                Не удалось загрузить правила прав
              </div>
            )}

            {!error && (
              <div className="card overflow-hidden">
                {!isLoading && perms && perms.length === 0 ? (
                  <div className="p-8 text-center">
                    <i className="bi bi-shield text-2xl text-gray-300 dark:text-gray-600 mb-2 block" />
                    <p className="text-sm text-gray-400 dark:text-gray-500">
                      Нет точечных переопределений — действуют дефолты ролей
                    </p>
                  </div>
                ) : (
                  <table className="w-full text-sm">
                    <thead className="bg-gray-50 dark:bg-gray-900/30 border-b border-gray-200 dark:border-gray-700">
                      <tr>
                        <th className="text-left px-5 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Субъект</th>
                        <th className="text-left px-3 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Capability</th>
                        <th className="text-left px-3 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Юрлицо</th>
                        <th className="text-center px-3 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Разрешено</th>
                        <th className="text-right px-5 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Действия</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                      {isLoading ? (
                        <TableSkeleton />
                      ) : (
                        perms?.map((perm) => {
                          const entityName = entities?.find((e) => e.id === perm.legal_entity_id)?.name;
                          const userName = users?.find((u) => u.id === perm.user_id)?.full_name;
                          const capLabel = CAPABILITIES.find((c) => c.code === perm.capability)?.label ?? perm.capability;

                          return (
                            <tr key={perm.id} className="hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors">
                              <td className="px-5 py-2.5">
                                {perm.role ? (
                                  <span className="inline-flex items-center gap-1 badge bg-primary/10 text-primary dark:text-blue-400 text-xs px-2 py-0.5 rounded-full">
                                    <i className="bi bi-people" />
                                    Роль: {ROLE_LABELS[perm.role] ?? perm.role}
                                  </span>
                                ) : (
                                  <span className="inline-flex items-center gap-1 badge bg-info/10 text-info text-xs px-2 py-0.5 rounded-full">
                                    <i className="bi bi-person" />
                                    {userName ?? `User #${perm.user_id}`}
                                  </span>
                                )}
                              </td>
                              <td className="px-3 py-2.5">
                                <div className="text-sm text-gray-700 dark:text-gray-300">{capLabel}</div>
                                <div className="font-mono text-[10px] text-gray-400 dark:text-gray-500">{perm.capability}</div>
                              </td>
                              <td className="px-3 py-2.5 text-sm text-gray-500 dark:text-gray-400">
                                {entityName ?? (perm.legal_entity_id ? `ID ${perm.legal_entity_id}` : <span className="italic text-gray-400">Все юрлица</span>)}
                              </td>
                              <td className="px-3 py-2.5 text-center">
                                {perm.allowed ? (
                                  <span className="inline-flex items-center gap-1 badge bg-success/10 text-success text-xs px-2 py-0.5 rounded-full">
                                    <i className="bi bi-check-circle" />Разрешено
                                  </span>
                                ) : (
                                  <span className="inline-flex items-center gap-1 badge bg-danger/10 text-danger text-xs px-2 py-0.5 rounded-full">
                                    <i className="bi bi-x-circle" />Запрещено
                                  </span>
                                )}
                              </td>
                              <td className="px-5 py-2.5 text-right">
                                <button
                                  className="btn-ghost text-danger text-xs px-2 py-1"
                                  disabled={deletingId === perm.id}
                                  onClick={() => handleDelete(perm.id)}
                                >
                                  {deletingId === perm.id ? (
                                    <i className="bi bi-hourglass text-xs" />
                                  ) : (
                                    <i className="bi bi-trash" />
                                  )}
                                </button>
                              </td>
                            </tr>
                          );
                        })
                      )}
                    </tbody>
                  </table>
                )}
              </div>
            )}
          </section>
        </div>
      </div>

      {/* Модалка добавления */}
      <Modal
        open={addOpen}
        title="Добавить правило прав"
        description="Точечное переопределение: роль или конкретный пользователь × capability × юрлицо"
        onClose={() => setAddOpen(false)}
        width="md"
        footer={
          <>
            <button className="btn-ghost" onClick={() => setAddOpen(false)} disabled={submitting}>Отмена</button>
            <button className="btn-primary" onClick={handleAdd} disabled={submitting}>
              {submitting ? "Создание…" : "Создать"}
            </button>
          </>
        }
      >
        <div className="space-y-4">
          {formError && (
            <div className="text-danger text-sm bg-danger/10 rounded px-3 py-2">
              <i className="bi bi-exclamation-triangle mr-2" />{formError}
            </div>
          )}

          <div>
            <label className="label">Субъект</label>
            <div className="flex gap-4 mt-1">
              <label className="flex items-center gap-2 cursor-pointer text-sm">
                <input type="radio" checked={form.subject_type === "role"} onChange={() => setF("subject_type", "role")} />
                Роль
              </label>
              <label className="flex items-center gap-2 cursor-pointer text-sm">
                <input type="radio" checked={form.subject_type === "user"} onChange={() => setF("subject_type", "user")} />
                Пользователь
              </label>
            </div>
          </div>

          {form.subject_type === "role" ? (
            <div>
              <label className="label">Роль</label>
              <select className="input w-full" value={form.role} onChange={(e) => setF("role", e.target.value)}>
                {SEEDED_ROLES.map((r) => (
                  <option key={r} value={r}>{ROLE_LABELS[r] ?? r}</option>
                ))}
              </select>
            </div>
          ) : (
            <div>
              <label className="label">Пользователь</label>
              <select className="input w-full" value={form.user_id} onChange={(e) => setF("user_id", e.target.value)}>
                <option value="">— Выберите пользователя —</option>
                {users?.map((u) => (
                  <option key={u.id} value={String(u.id)}>{u.full_name} ({u.email})</option>
                ))}
              </select>
            </div>
          )}

          <div>
            <label className="label">Capability</label>
            <select className="input w-full" value={form.capability} onChange={(e) => setF("capability", e.target.value)}>
              {CAPABILITIES.map((c) => (
                <option key={c.code} value={c.code}>{c.label} ({c.code})</option>
              ))}
            </select>
          </div>

          <div>
            <label className="label">Юрлицо (необязательно)</label>
            <select className="input w-full" value={form.legal_entity_id} onChange={(e) => setF("legal_entity_id", e.target.value)}>
              <option value="">Все юрлица</option>
              {entities?.map((e) => (
                <option key={e.id} value={String(e.id)}>{e.name}</option>
              ))}
            </select>
          </div>

          <div>
            <label className="label">Значение</label>
            <div className="flex gap-4 mt-1">
              <label className="flex items-center gap-2 cursor-pointer text-sm">
                <input type="radio" checked={form.allowed === true} onChange={() => setF("allowed", true)} />
                <span className="text-success font-medium">Разрешено</span>
              </label>
              <label className="flex items-center gap-2 cursor-pointer text-sm">
                <input type="radio" checked={form.allowed === false} onChange={() => setF("allowed", false)} />
                <span className="text-danger font-medium">Запрещено</span>
              </label>
            </div>
          </div>
        </div>
      </Modal>
    </RoleGate>
  );
}
