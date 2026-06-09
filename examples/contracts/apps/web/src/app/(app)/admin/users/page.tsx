"use client";

import { useState } from "react";
import useSWR from "swr";
import { PageHeader } from "@/components/PageHeader";
import { Modal } from "@/components/Modal";
import { Field, SelectField } from "@/components/Field";
import { RoleGate } from "@/components/RoleGate";
import { UserSelect } from "@/components/UserSelect";
import { DepartmentSelect } from "@/components/Departments/DepartmentSelect";
import { DataTable, type DataTableColumn } from "@/components/ui/DataTable";
import { useToast } from "@/components/ui/Toast";
import { api, ApiError, fetcher } from "@/lib/api";
import { RoleLabels, type User, type UserRole, type Department } from "@/lib/types";

type FormUser = Partial<User> & { password?: string; manager_id?: number | null };

const EMPTY: FormUser = {
  email: "", password: "", full_name: "",
  role: "manager", telegram_user_id: null,
  department_id: null, manager_id: null,
};

const ROLE_OPTS = (Object.entries(RoleLabels) as [UserRole, string][]).map(([v, l]) => ({ value: v, label: l }));

const ROLE_BADGE: Record<string, string> = {
  admin:    "bg-danger-50   text-danger-700   dark:bg-danger-500/10   dark:text-danger-400",
  director: "bg-warning-50  text-warning-700  dark:bg-warning-500/10  dark:text-warning-400",
  lawyer:   "bg-info-50     text-info-700     dark:bg-info-500/10     dark:text-info-400",
  manager:  "bg-gray-100    text-gray-700     dark:bg-gray-700        dark:text-gray-300",
};

export default function UsersPage() {
  const { data, mutate } = useSWR<User[]>("/users", fetcher);
  const { data: departments, isLoading: depsLoading } = useSWR<Department[]>("/departments", fetcher);
  const [form, setForm] = useState<FormUser | null>(null);
  const [initial, setInitial] = useState<FormUser | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const { toast } = useToast();

  function openCreate() {
    const s = { ...EMPTY };
    setForm(s); setInitial(s); setError(null);
  }
  function openEdit(u: User) {
    const s = { ...u, password: "" };
    setForm(s); setInitial(s); setError(null);
  }
  function isDirty() {
    if (!form || !initial) return false;
    return JSON.stringify(form) !== JSON.stringify(initial);
  }
  function isValid() {
    if (!form?.email?.trim() || !form?.full_name?.trim() || !form?.role) return false;
    if (!(form as User).id && !form?.password?.trim()) return false;
    return true;
  }

  async function save(): Promise<boolean> {
    if (!form || !isValid()) {
      setError("Заполните обязательные поля");
      return false;
    }
    setSaving(true);
    setError(null);
    try {
      const id = (form as User).id;
      if (id) {
        await api(`/users/${id}`, { method: "PATCH", body: form });
        toast.success("Пользователь обновлён");
      } else {
        await api("/users", { method: "POST", body: form });
        toast.success("Пользователь создан");
      }
      await mutate();
      return true;
    } catch (err) {
      const msg = err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Ошибка";
      setError(msg);
      toast.error(msg);
      return false;
    } finally {
      setSaving(false);
    }
  }

  const columns: DataTableColumn<User>[] = [
    {
      key: "full_name",
      header: "ФИО",
      skeletonWidth: "55%",
      render: (u) => (
        <span className="font-medium text-gray-900 dark:text-gray-100">{u.full_name}</span>
      ),
    },
    {
      key: "email",
      header: "Email",
      skeletonWidth: "65%",
      render: (u) => (
        <span className="text-gray-600 dark:text-gray-400">{u.email}</span>
      ),
    },
    {
      key: "role",
      header: "Роль",
      width: "10rem",
      skeletonWidth: "70%",
      render: (u) => (
        <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${ROLE_BADGE[u.role] ?? ROLE_BADGE.manager}`}>
          {RoleLabels[u.role]}
        </span>
      ),
    },
    {
      key: "telegram_user_id",
      header: "Telegram",
      width: "9rem",
      skeletonWidth: "50%",
      render: (u) => (
        <span className="text-gray-500 dark:text-gray-400 tabular-nums">
          {u.telegram_user_id ?? "—"}
        </span>
      ),
    },
  ];

  return (
    <RoleGate allowed={["admin"]} fallback={<UsersNoAccess />}>
      <PageHeader
        title="Пользователи"
        description="Управление учётными записями и ролями"
        actions={
          <button className="btn-primary" onClick={openCreate}>
            <i className="bi bi-plus-lg mr-1" /> Добавить
          </button>
        }
      />
      <div className="p-8">
        <DataTable
          columns={columns}
          rows={data}
          getRowKey={(u) => u.id}
          onRowClick={openEdit}
          ariaLabel="Список пользователей"
          skeletonRows={5}
          emptyIcon="bi-people"
          emptyTitle="Нет пользователей"
          emptyText="Добавьте первого пользователя системы"
          emptyCta={
            <button className="btn-primary" onClick={openCreate}>
              <i className="bi bi-plus-lg mr-1" /> Добавить
            </button>
          }
        />
      </div>

      <Modal
        open={!!form}
        onClose={() => setForm(null)}
        onTrySave={save}
        isDirty={isDirty()}
        title={(form as User)?.id ? "Изменить пользователя" : "Новый пользователь"}
        width="md"
        footer={
          <>
            <button className="btn-secondary" onClick={() => setForm(null)}>Отмена</button>
            <button onClick={save} disabled={saving || !isValid()} className="btn-primary">
              {saving ? "Сохранение…" : "Сохранить"}
            </button>
          </>
        }
      >
        {form && (
          <div className="space-y-4">
            {error && (
              <div className="text-danger text-sm bg-danger/10 px-3 py-2 rounded">
                {error}
              </div>
            )}
            <Field label="ФИО" value={form.full_name ?? ""} onChange={(v) => setForm({ ...form, full_name: v })} required />
            <Field label="Email" type="email" value={form.email ?? ""} onChange={(v) => setForm({ ...form, email: v })} required />
            <Field
              label={(form as User).id ? "Новый пароль (если меняем)" : "Пароль"}
              type="password"
              value={form.password ?? ""}
              onChange={(v) => setForm({ ...form, password: v })}
              required={!(form as User).id}
            />
            <SelectField
              label="Роль"
              value={form.role as UserRole}
              onChange={(v) => setForm({ ...form, role: v })}
              options={ROLE_OPTS}
              required
            />
            <Field
              label="Telegram User ID"
              value={form.telegram_user_id?.toString() ?? ""}
              onChange={(v) => setForm({ ...form, telegram_user_id: v ? Number(v) : null })}
              placeholder="например 440622916"
              inputMode="numeric"
              hint={
                <>
                  Попросите сотрудника написать{" "}
                  <a href="https://t.me/Contract_generator_MACRO_bot" target="_blank" rel="noreferrer" className="text-primary underline">
                    @Contract_generator_MACRO_bot
                  </a>{" "}
                  команду <code className="bg-gray-100 dark:bg-gray-700 px-1 rounded">/whoami</code> — впишите цифры сюда.
                </>
              }
            />

            <div className="pt-4 border-t border-gray-200 dark:border-gray-700">
              <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">
                Отдел и иерархия
              </p>
              <div className="space-y-4">
                <div>
                  <label className="label">Отдел</label>
                  {depsLoading ? (
                    <select className="input opacity-60" disabled>
                      <option>Загружаем…</option>
                    </select>
                  ) : (
                    <DepartmentSelect
                      value={form.department_id != null ? String(form.department_id) : ""}
                      onChange={(v) => setForm({ ...form, department_id: v ? Number(v) : null })}
                      departments={departments ?? []}
                      placeholder="Не выбрано"
                    />
                  )}
                </div>
                <div>
                  <label className="label">Прямой руководитель</label>
                  <UserSelect
                    value={form.manager_id != null ? String(form.manager_id) : ""}
                    onChange={(v) => setForm({ ...form, manager_id: v ? Number(v) : null })}
                    users={data}
                    placeholder="Не назначен"
                  />
                  <p className="text-xs text-gray-500 mt-1">
                    Прямой руководитель может отличаться от руководителя отдела.
                  </p>
                </div>
              </div>
            </div>
          </div>
        )}
      </Modal>
    </RoleGate>
  );
}

function UsersNoAccess() {
  return (
    <div className="p-8">
      <div className="card flex flex-col items-center justify-center py-16 text-center">
        <i className="bi bi-shield-lock text-5xl text-gray-300 dark:text-gray-600 mb-4" />
        <p className="text-base font-semibold text-gray-700 dark:text-gray-300 mb-1">
          Доступ ограничен
        </p>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Управление пользователями доступно только администраторам.
        </p>
      </div>
    </div>
  );
}
