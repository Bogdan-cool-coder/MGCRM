"use client";

import { useState } from "react";
import useSWR from "swr";
import { PageHeader } from "@/components/PageHeader";
import { Modal } from "@/components/Modal";
import { CheckboxGroup } from "@/components/CheckboxGroup";
import { DataTable, type DataTableColumn } from "@/components/ui/DataTable";
import { FloatingInput } from "@/components/ui/FloatingInput";
import { useToast } from "@/components/ui/Toast";
import { api, ApiError, fetcher } from "@/lib/api";
import { ALL_COUNTRIES, ALL_PRODUCTS, type ApprovalRoute, type ApprovalStage, type User } from "@/lib/types";

const PRODUCT_OPTS = [{ value: "*", label: "Все продукты (по умолчанию)" }, ...ALL_PRODUCTS];
const COUNTRY_OPTS = [{ value: "*", label: "Любая страна" }, ...ALL_COUNTRIES];

type Form = {
  id?: number;
  name: string;
  product_codes: string[];
  country_codes: string[];
  stages: ApprovalStage[];
};

const EMPTY: Form = {
  name: "",
  product_codes: [],
  country_codes: [],
  stages: [{ order: 0, name: "Согласование", user_ids: [], min_required: 1 }],
};

// Soft-бейдж для количества согласователей
function ApproversCountBadge({ count }: { count: number }) {
  const cls =
    count === 0
      ? "bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400"
      : "bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400";
  return (
    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${cls}`}>
      {count === 0 ? "нет" : `${count} чел.`}
    </span>
  );
}

export default function ApprovalRoutesPage() {
  const { data, mutate } = useSWR<ApprovalRoute[]>("/approval-routes", fetcher);
  const { data: users } = useSWR<User[]>("/users", fetcher);
  const { toast } = useToast();

  const [form, setForm] = useState<Form | null>(null);
  const [initial, setInitial] = useState<Form | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  function openCreate() {
    const f = { ...EMPTY, stages: [{ ...EMPTY.stages[0] }] };
    setForm(f);
    setInitial(f);
    setError(null);
  }

  function openEdit(r: ApprovalRoute) {
    const stages: ApprovalStage[] = r.stages?.length
      ? r.stages
      : [{ order: 0, name: "Согласование", user_ids: r.approver_user_ids ?? [], min_required: r.min_required ?? 1 }];
    const f: Form = {
      id: r.id,
      name: r.name,
      product_codes: r.product_codes ?? [],
      country_codes: r.country_codes ?? [],
      stages,
    };
    setForm(f);
    setInitial(JSON.parse(JSON.stringify(f)));
    setError(null);
  }

  function isDirty() {
    return form && initial ? JSON.stringify(form) !== JSON.stringify(initial) : false;
  }

  function isValid() {
    if (!form?.name?.trim()) return false;
    if (!form.product_codes?.length) return false;
    if (!form.country_codes?.length) return false;
    if (!form.stages?.length) return false;
    for (const st of form.stages) {
      if (!st.name?.trim() || !st.user_ids?.length) return false;
      if (st.min_required < 1 || st.min_required > st.user_ids.length) return false;
    }
    return true;
  }

  async function save(): Promise<boolean> {
    if (!form || !isValid()) {
      setError("Заполните обязательные поля корректно");
      return false;
    }
    setSaving(true);
    setError(null);
    try {
      const body = {
        name: form.name,
        product_codes: form.product_codes,
        country_codes: form.country_codes,
        stages: form.stages.map((s, idx) => ({ ...s, order: idx })),
        approver_user_ids: [],
        min_required: form.stages[0]?.min_required ?? 1,
      };
      if (form.id) {
        await api(`/approval-routes/${form.id}`, { method: "PATCH", body });
        toast.success("Маршрут обновлён");
      } else {
        await api("/approval-routes", { method: "POST", body });
        toast.success("Маршрут создан");
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

  function userName(uid: number) {
    return users?.find((u) => u.id === uid)?.full_name ?? `User #${uid}`;
  }

  function setStage(idx: number, updater: (s: ApprovalStage) => ApprovalStage) {
    if (!form) return;
    setForm({ ...form, stages: form.stages.map((s, i) => (i === idx ? updater(s) : s)) });
  }

  function addStage() {
    if (!form) return;
    setForm({
      ...form,
      stages: [
        ...form.stages,
        { order: form.stages.length, name: `Этап ${form.stages.length + 1}`, user_ids: [], min_required: 1 },
      ],
    });
  }

  function removeStage(idx: number) {
    if (!form) return;
    setForm({
      ...form,
      stages: form.stages.filter((_, i) => i !== idx).map((s, i) => ({ ...s, order: i })),
    });
  }

  function moveStage(idx: number, dir: -1 | 1) {
    if (!form) return;
    const target = idx + dir;
    if (target < 0 || target >= form.stages.length) return;
    const stages = [...form.stages];
    [stages[idx], stages[target]] = [stages[target], stages[idx]];
    setForm({ ...form, stages: stages.map((s, i) => ({ ...s, order: i })) });
  }

  function toggleStageUser(idx: number, uid: number) {
    setStage(idx, (s) => {
      const has = s.user_ids.includes(uid);
      const user_ids = has ? s.user_ids.filter((x) => x !== uid) : [...s.user_ids, uid];
      const min_required = Math.min(s.min_required || 1, Math.max(user_ids.length, 1));
      return { ...s, user_ids, min_required };
    });
  }

  const columns: DataTableColumn<ApprovalRoute>[] = [
    {
      key: "name",
      header: "Название",
      skeletonWidth: "55%",
      render: (r) => (
        <span className="font-medium text-gray-900 dark:text-gray-100">{r.name}</span>
      ),
    },
    {
      key: "product_codes",
      header: "Продукты",
      width: "12rem",
      skeletonWidth: "60%",
      render: (r) => (
        <span className="text-xs uppercase text-gray-600 dark:text-gray-400">
          {(r.product_codes || []).join(", ") || "—"}
        </span>
      ),
    },
    {
      key: "country_codes",
      header: "Страны",
      width: "8rem",
      skeletonWidth: "40%",
      render: (r) => (
        <span className="text-xs uppercase text-gray-600 dark:text-gray-400">
          {(r.country_codes || []).join(", ") || "—"}
        </span>
      ),
    },
    {
      key: "stages",
      header: "Этапы",
      skeletonWidth: "70%",
      render: (r) => {
        const stages = r.stages?.length
          ? r.stages
          : r.approver_user_ids?.length
            ? [{ order: 0, name: "Согласование", user_ids: r.approver_user_ids, min_required: r.min_required ?? 1 }]
            : [];
        return (
          <div className="space-y-1">
            {stages.map((s, i) => (
              <div key={i} className="flex items-center gap-2 text-xs">
                <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary/10 dark:bg-primary/20 text-primary dark:text-blue-300 font-semibold text-[10px]">
                  {i + 1}
                </span>
                <span className="font-medium text-gray-700 dark:text-gray-300">{s.name}</span>
                <span className="text-gray-500 dark:text-gray-400">
                  {s.user_ids.map(userName).join(", ")}
                </span>
                <ApproversCountBadge count={s.user_ids.length} />
                <span className="text-gray-400 dark:text-gray-500">нужно {s.min_required}</span>
              </div>
            ))}
            {stages.length === 0 && (
              <span className="text-xs text-gray-400 dark:text-gray-500 italic">нет этапов</span>
            )}
          </div>
        );
      },
    },
  ];

  return (
    <>
      <PageHeader
        title="Маршруты согласования"
        description="Для каких продуктов и стран какие пользователи согласовывают. Можно настроить несколько последовательных этапов."
        actions={
          <button className="btn-primary" onClick={openCreate}>
            <i className="bi bi-plus-lg mr-1" /> Новый маршрут
          </button>
        }
      />
      <div className="p-8">
        <DataTable
          columns={columns}
          rows={data}
          getRowKey={(r) => r.id}
          onRowClick={openEdit}
          emptyIcon="bi-diagram-3"
          emptyTitle="Маршрутов нет"
          emptyText="Создайте первый маршрут согласования договоров."
          emptyCta={
            <button className="btn-primary" onClick={openCreate}>
              <i className="bi bi-plus-lg mr-1" /> Новый маршрут
            </button>
          }
          ariaLabel="Маршруты согласования"
        />
      </div>

      <Modal
        open={!!form}
        onClose={() => setForm(null)}
        onTrySave={save}
        isDirty={isDirty()}
        title={form?.id ? "Изменить маршрут" : "Новый маршрут согласования"}
        width="xl"
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
          <div className="space-y-5">
            {error && (
              <div className="text-danger text-sm bg-danger/10 dark:bg-danger-500/10 px-3 py-2 rounded-lg flex items-center gap-2">
                <i className="bi bi-exclamation-triangle shrink-0" />
                {error}
              </div>
            )}

            <FloatingInput
              label="Название маршрута"
              value={form.name}
              onChange={(e) => setForm({ ...form, name: e.target.value })}
              required
            />

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <CheckboxGroup
                label="Продукты"
                options={PRODUCT_OPTS}
                value={form.product_codes}
                onChange={(v) => setForm({ ...form, product_codes: v })}
                required
              />
              <CheckboxGroup
                label="Страны"
                options={COUNTRY_OPTS}
                value={form.country_codes}
                onChange={(v) => setForm({ ...form, country_codes: v })}
                required
              />
            </div>

            {/* Степпер этапов */}
            <div>
              <div className="flex items-center justify-between mb-3">
                <div>
                  <p className="text-sm font-semibold text-gray-800 dark:text-gray-200">
                    Этапы согласования
                  </p>
                  <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                    Следующий этап получает уведомление после прохождения предыдущего.
                  </p>
                </div>
                <button
                  type="button"
                  onClick={addStage}
                  className="btn-ghost text-xs"
                >
                  <i className="bi bi-plus-lg mr-1" /> Добавить этап
                </button>
              </div>

              <div className="space-y-3">
                {form.stages.map((stage, idx) => (
                  <StageCard
                    key={idx}
                    idx={idx}
                    stage={stage}
                    total={form.stages.length}
                    users={users}
                    onNameChange={(v) => setStage(idx, (s) => ({ ...s, name: v }))}
                    onMinChange={(v) => setStage(idx, (s) => ({ ...s, min_required: Math.max(1, v) }))}
                    onToggleUser={(uid) => toggleStageUser(idx, uid)}
                    onMoveUp={() => moveStage(idx, -1)}
                    onMoveDown={() => moveStage(idx, 1)}
                    onRemove={() => removeStage(idx)}
                  />
                ))}
              </div>
            </div>
          </div>
        )}
      </Modal>
    </>
  );
}

// ─── StageCard ────────────────────────────────────────────────────────────────

interface StageCardProps {
  idx: number;
  stage: ApprovalStage;
  total: number;
  users: User[] | undefined;
  onNameChange: (v: string) => void;
  onMinChange: (v: number) => void;
  onToggleUser: (uid: number) => void;
  onMoveUp: () => void;
  onMoveDown: () => void;
  onRemove: () => void;
}

function StageCard({
  idx,
  stage,
  total,
  users,
  onNameChange,
  onMinChange,
  onToggleUser,
  onMoveUp,
  onMoveDown,
  onRemove,
}: StageCardProps) {
  const approvers = users?.filter(
    (u) => u.role === "admin" || u.role === "director" || u.role === "lawyer",
  ) ?? [];

  const hasError = stage.user_ids.length === 0;
  const minError = stage.user_ids.length > 0 && stage.min_required > stage.user_ids.length;

  return (
    <div
      className={[
        "rounded-xl border p-4 transition-colors",
        hasError || minError
          ? "border-danger/40 bg-danger-50/30 dark:border-danger/30 dark:bg-danger-500/5"
          : "border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30",
      ].join(" ")}
    >
      {/* Заголовок карточки */}
      <div className="flex items-center gap-3 mb-4">
        {/* Номер этапа */}
        <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary text-white text-xs font-bold">
          {idx + 1}
        </div>
        <p className="text-sm font-semibold text-gray-800 dark:text-gray-200 flex-1">
          Этап {idx + 1}
        </p>
        {/* Управление порядком */}
        <div className="flex items-center gap-1">
          <button
            type="button"
            onClick={onMoveUp}
            disabled={idx === 0}
            className="btn-ghost text-xs p-1.5 disabled:opacity-30"
            title="Поднять выше"
          >
            <i className="bi bi-chevron-up" />
          </button>
          <button
            type="button"
            onClick={onMoveDown}
            disabled={idx === total - 1}
            className="btn-ghost text-xs p-1.5 disabled:opacity-30"
            title="Опустить ниже"
          >
            <i className="bi bi-chevron-down" />
          </button>
          {total > 1 && (
            <button
              type="button"
              onClick={onRemove}
              className="btn-ghost text-xs p-1.5 text-danger"
              title="Удалить этап"
            >
              <i className="bi bi-trash" />
            </button>
          )}
        </div>
      </div>

      {/* Поля этапа */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
        <div className="md:col-span-2">
          <FloatingInput
            label="Название этапа"
            value={stage.name}
            onChange={(e) => onNameChange(e.target.value)}
            required
          />
        </div>
        <FloatingInput
          label="Голосов нужно"
          type="number"
          inputMode="numeric"
          value={String(stage.min_required)}
          onChange={(e) => onMinChange(Number(e.target.value) || 1)}
          required
          error={minError ? "Больше чем согласователей" : undefined}
        />
      </div>

      {/* Согласователи */}
      <div>
        <label className="label mb-1.5">
          Согласователи этапа
          <span className="text-danger ml-0.5">*</span>
        </label>
        <div
          className={[
            "rounded-xl border p-2 max-h-44 overflow-y-auto bg-white dark:bg-gray-900",
            hasError
              ? "border-danger dark:border-danger"
              : "border-gray-200 dark:border-gray-700",
          ].join(" ")}
        >
          {approvers.length === 0 ? (
            <p className="text-xs text-gray-400 dark:text-gray-500 text-center py-2">
              Нет пользователей с ролью admin/director/lawyer
            </p>
          ) : (
            approvers.map((u) => {
              const checked = stage.user_ids.includes(u.id);
              return (
                <label
                  key={u.id}
                  className="flex items-center gap-2.5 p-1.5 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800 text-sm transition-colors"
                >
                  <input
                    type="checkbox"
                    checked={checked}
                    onChange={() => onToggleUser(u.id)}
                    className="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary dark:border-gray-600 dark:bg-gray-700"
                  />
                  <span className="text-gray-800 dark:text-gray-200">{u.full_name}</span>
                  <span className="text-xs text-gray-400 dark:text-gray-500">({u.role})</span>
                </label>
              );
            })
          )}
        </div>
        {hasError && (
          <p className="text-xs text-danger mt-1">
            <i className="bi bi-exclamation-circle mr-1" /> Выберите хотя бы одного согласователя
          </p>
        )}
      </div>
    </div>
  );
}
