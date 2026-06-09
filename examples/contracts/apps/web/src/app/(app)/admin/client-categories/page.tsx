"use client";

import { useState } from "react";
import useSWR from "swr";
import { PageHeader } from "@/components/PageHeader";
import { Modal } from "@/components/Modal";
import { Field } from "@/components/Field";
import { FloatingInput } from "@/components/ui/FloatingInput";
import { DataTable } from "@/components/ui/DataTable";
import type { DataTableColumn } from "@/components/ui/DataTable";
import { CategoryBadge } from "@/components/CategoryBadge";
import { useToast } from "@/components/ui/Toast";
import { api, ApiError, fetcher } from "@/lib/api";
import type { CategoryClient, ClientCategory, ClientGroup } from "@/lib/types";

type OptionEntry = { key: string; value: string; type: "string" | "number" | "boolean" };

type Form = {
  id?: number;
  code: string;
  name: string;
  group: string;
  min_amount: string;
  max_amount: string;
  color: string;
  description: string;
  options: OptionEntry[];
  is_active: boolean;
  sort_order: number;
};

function fmt(n: number | null | undefined): string {
  return n == null ? "—" : Number(n).toLocaleString("ru-RU", { maximumFractionDigits: 2 });
}

function errText(e: unknown): string {
  return e instanceof ApiError
    ? String((e.detail as { detail?: string })?.detail ?? e.message)
    : "Ошибка";
}

function CategoryClients({ code }: { code: string }) {
  const { data } = useSWR<CategoryClient[]>(`/client-categories/${code}/clients`, fetcher);
  if (!data) return <div className="text-xs text-gray-400 px-3 py-2">Загрузка…</div>;
  if (data.length === 0) return <div className="text-xs text-gray-400 px-3 py-2">Нет клиентов в этой категории.</div>;
  return (
    <ul className="px-3 py-2 space-y-1">
      {data.map((c) => (
        <li key={c.id} className="flex justify-between text-sm">
          <span>{c.name}{c.group_id ? " (холдинг)" : ""}</span>
          <span className="tabular-nums text-gray-500">{fmt(c.turnover_rub)} ₽</span>
        </li>
      ))}
    </ul>
  );
}

/** Конвертация options_map в OptionEntry[] */
function optionsMapToEntries(optionsMap: Record<string, unknown> | null | undefined): OptionEntry[] {
  if (!optionsMap) return [];
  return Object.entries(optionsMap).map(([k, v]) => ({
    key: k,
    value: String(v),
    type: typeof v === "number" ? "number" : typeof v === "boolean" ? "boolean" : "string",
  }));
}

/** Конвертация OptionEntry[] в options_map объект */
function entriesToOptionsMap(entries: OptionEntry[]): Record<string, unknown> {
  return Object.fromEntries(
    entries
      .filter((o) => o.key.trim())
      .map((o) => {
        if (o.type === "number") return [o.key, Number(o.value)];
        if (o.type === "boolean") return [o.key, o.value === "true"];
        return [o.key, o.value];
      }),
  );
}

// ── DataTable для холдингов ───────────────────────────────────────────────────

const GROUP_COLUMNS: DataTableColumn<ClientGroup>[] = [
  {
    key: "name",
    header: "Название",
    skeletonWidth: "60%",
    render: (g) => (
      <span className="font-medium text-gray-900 dark:text-gray-100">{g.name}</span>
    ),
  },
  {
    key: "category_code",
    header: "Категория",
    width: "8rem",
    align: "center",
    skeletonWidth: "50%",
    render: (g) =>
      g.category_code ? <CategoryBadge code={g.category_code} /> : <span className="text-gray-400">—</span>,
  },
  {
    key: "member_ids",
    header: "Юрлиц",
    width: "6rem",
    align: "right",
    skeletonWidth: "40%",
    render: (g) => (
      <span className="tabular-nums text-gray-600 dark:text-gray-400">{g.member_ids.length}</span>
    ),
  },
  {
    key: "turnover_rub",
    header: "Оборот ₽",
    width: "10rem",
    align: "right",
    skeletonWidth: "55%",
    render: (g) => (
      <span className="tabular-nums text-gray-700 dark:text-gray-300">{fmt(g.turnover_rub)} ₽</span>
    ),
  },
];

export default function ClientCategoriesPage() {
  const { data: cats, mutate } = useSWR<ClientCategory[]>("/client-categories", fetcher);
  const { data: groups, mutate: mutateGroups } = useSWR<ClientGroup[]>("/client-groups", fetcher);
  const { toast } = useToast();
  const [form, setForm] = useState<Form | null>(null);
  const [expanded, setExpanded] = useState<string | null>(null);
  const [newGroup, setNewGroup] = useState("");
  const [busy, setBusy] = useState(false);
  const [formErr, setFormErr] = useState<string | null>(null);

  function fromCat(c: ClientCategory): Form {
    return {
      id: c.id,
      code: c.code,
      name: c.name,
      group: c.group ?? "",
      min_amount: String(c.min_amount),
      max_amount: c.max_amount == null ? "" : String(c.max_amount),
      color: c.color ?? "",
      description: c.description ?? "",
      options: optionsMapToEntries(c.options_map),
      is_active: c.is_active,
      sort_order: c.sort_order,
    };
  }

  async function saveCat() {
    if (!form) return;
    setBusy(true); setFormErr(null);
    try {
      const options_map = entriesToOptionsMap(form.options);
      const body = {
        code: form.code.trim(),
        name: form.name.trim(),
        group: form.group.trim() || null,
        min_amount: Number(form.min_amount) || 0,
        max_amount: form.max_amount.trim() === "" ? null : Number(form.max_amount),
        color: form.color.trim() || null,
        description: form.description.trim() || null,
        options_map,
        sort_order: Number(form.sort_order) || 0,
        is_active: form.is_active,
      };
      if (form.id) await api(`/client-categories/${form.id}`, { method: "PATCH", body });
      else await api("/client-categories", { method: "POST", body });
      await mutate();
      setForm(null);
      toast.success(form.id ? "Категория обновлена" : "Категория добавлена");
    } catch (err) {
      setFormErr(errText(err));
    } finally { setBusy(false); }
  }

  async function recompute() {
    setBusy(true);
    try {
      const res = await api<{ recomputed: number }>("/client-categories/recompute", { method: "POST" });
      toast.success(`Пересчитано клиентов/групп: ${res.recomputed}`);
    } catch (err) {
      toast.error(errText(err));
    } finally { setBusy(false); }
  }

  async function createGroup() {
    if (!newGroup.trim()) return;
    try {
      await api("/client-groups", { method: "POST", body: { name: newGroup.trim() } });
      setNewGroup("");
      await mutateGroups();
      toast.success("Холдинг добавлен");
    } catch (err) {
      toast.error(errText(err));
    }
  }

  async function deleteGroup(id: number, name: string) {
    if (!confirm("Удалить холдинг? Контрагенты станут самостоятельными.")) return;
    try {
      await api(`/client-groups/${id}`, { method: "DELETE" });
      await mutateGroups();
      toast.success(`Холдинг «${name}» удалён`);
    } catch (err) {
      toast.error(errText(err));
    }
  }

  function addOption() {
    if (!form) return;
    setForm({ ...form, options: [...form.options, { key: "", value: "", type: "string" }] });
  }

  function removeOption(i: number) {
    if (!form) return;
    setForm({ ...form, options: form.options.filter((_, idx) => idx !== i) });
  }

  function updateOption(i: number, field: keyof OptionEntry, value: string) {
    if (!form) return;
    setForm({
      ...form,
      options: form.options.map((opt, idx) =>
        idx === i ? { ...opt, [field]: value } : opt,
      ),
    });
  }

  return (
    <div>
      <PageHeader
        title="Категории клиентов"
        actions={
          <div className="flex gap-2">
            <button onClick={recompute} disabled={busy} className="btn-secondary text-sm">
              <i className="bi bi-arrow-repeat mr-1" /> Пересчитать категории
            </button>
            <button
              onClick={() => { setFormErr(null); setForm({ code: "", name: "", group: "", min_amount: "0", max_amount: "", color: "", description: "", options: [], is_active: true, sort_order: 0 }); }}
              className="btn-primary text-sm"
            >
              <i className="bi bi-plus-lg mr-1" /> Категория
            </button>
          </div>
        }
      />

      <div className="p-8 space-y-8">
        {/* ── Категории ── */}
        <div className="space-y-2">
          {cats === undefined ? (
            /* skeleton */
            <div className="space-y-2">
              {[1, 2, 3].map((i) => (
                <div key={i} className="h-14 animate-pulse rounded-xl bg-gray-100 dark:bg-gray-800" />
              ))}
            </div>
          ) : cats.length === 0 ? (
            <div className="card flex flex-col items-center justify-center py-10 text-center">
              <i className="bi bi-tag text-4xl text-gray-300 mb-3" />
              <p className="text-sm font-medium text-gray-500">Нет категорий</p>
              <p className="text-xs text-gray-400 mt-1">Добавьте первую категорию клиентов</p>
            </div>
          ) : (
            cats.map((c) => (
              <div key={c.id} className="card overflow-hidden lift">
                <div className="flex items-center gap-3 px-4 py-3">
                  <CategoryBadge code={c.code} />
                  <div className="flex-1 min-w-0">
                    <div className="font-medium text-gray-900 dark:text-gray-100">{c.name}</div>
                    <div className="text-xs text-gray-500 dark:text-gray-400">
                      {fmt(c.min_amount)} ₽ {c.max_amount == null ? "и выше" : `– ${fmt(c.max_amount)} ₽`}
                      {c.options_map && Object.keys(c.options_map).length > 0 && ` · атрибутов: ${Object.keys(c.options_map).length}`}
                    </div>
                    {c.description && (
                      <p className="text-xs text-gray-400 mt-0.5 truncate max-w-xs">{c.description}</p>
                    )}
                  </div>
                  {/* Soft status badge */}
                  {c.is_active ? (
                    <span className="inline-flex items-center gap-1 rounded-full bg-success/10 px-2 py-0.5 text-xs font-medium text-success shrink-0">
                      <i className="bi bi-circle-fill text-[6px]" />
                      Активна
                    </span>
                  ) : (
                    <span className="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-500 dark:bg-gray-700 dark:text-gray-400 shrink-0">
                      <i className="bi bi-circle-fill text-[6px]" />
                      Выкл
                    </span>
                  )}
                  <button
                    onClick={() => setExpanded(expanded === c.code ? null : c.code)}
                    className="btn-ghost text-xs shrink-0"
                  >
                    <i className="bi bi-people mr-1" /> Клиенты
                  </button>
                  <button
                    onClick={() => { setFormErr(null); setForm(fromCat(c)); }}
                    className="btn-ghost text-xs shrink-0"
                    title="Редактировать"
                  >
                    <i className="bi bi-pencil" />
                  </button>
                </div>
                {expanded === c.code && (
                  <div className="border-t border-gray-100 dark:border-gray-700">
                    <CategoryClients code={c.code} />
                  </div>
                )}
              </div>
            ))
          )}
        </div>

        {/* ── Холдинги ── */}
        <div>
          <h3 className="font-semibold text-gray-700 dark:text-gray-300 mb-4">Холдинги (группы юрлиц)</h3>

          {/* Добавить холдинг */}
          <div className="flex gap-2 mb-4 max-w-md">
            <FloatingInput
              label="Название холдинга"

              value={newGroup}
              onChange={(e) => setNewGroup(e.target.value)}
              onKeyDown={(e) => { if (e.key === "Enter") void createGroup(); }}
            />
            <button
              onClick={() => void createGroup()}
              className="btn-secondary whitespace-nowrap self-end mb-[1px]"
            >
              <i className="bi bi-plus-lg mr-1" /> Добавить
            </button>
          </div>

          <DataTable
            columns={GROUP_COLUMNS}
            rows={groups}
            getRowKey={(g) => g.id}
            density="compact"
            maxHeight="40vh"
            ariaLabel="Холдинги"
            emptyIcon="bi-diagram-3"
            emptyTitle="Нет холдингов"
            emptyText="Контрагенты привязываются к холдингу в карточке контрагента"
            rowActions={(g) => (
              <button
                onClick={() => void deleteGroup(g.id, g.name)}
                className="btn-ghost p-1 text-gray-400 hover:text-danger"
                title="Удалить"
              >
                <i className="bi bi-trash text-xs" />
              </button>
            )}
          />
        </div>
      </div>

      {form && (
        <Modal
          open
          title={form.id ? `Категория ${form.code}` : "Новая категория"}
          onClose={() => setForm(null)}
          width="lg"
        >
          <div className="space-y-4">
            <div className="grid grid-cols-2 gap-3">
              <Field label="Код" value={form.code} onChange={(v) => setForm({ ...form, code: v })} required />
              <Field label="Название" value={form.name} onChange={(v) => setForm({ ...form, name: v })} required />
            </div>
            <div className="grid grid-cols-3 gap-3">
              <Field label="Мин. оборот ₽" type="number" value={form.min_amount} onChange={(v) => setForm({ ...form, min_amount: v })} />
              <Field label="Макс. ₽ (пусто=∞)" type="number" value={form.max_amount} onChange={(v) => setForm({ ...form, max_amount: v })} />
              <Field label="Группа (напр. S)" value={form.group} onChange={(v) => setForm({ ...form, group: v })} />
            </div>

            <div>
              <label className="label">Описание</label>
              <textarea
                className="input"
                rows={3}
                value={form.description}
                onChange={(e) => setForm({ ...form, description: e.target.value })}
                placeholder="Кратко: кто попадает в эту категорию и какие условия работы"
              />
            </div>

            {/* Атрибуты категории (options_map) */}
            <div>
              <div className="flex items-center justify-between mb-2">
                <label className="label mb-0">Атрибуты категории</label>
                <button onClick={addOption} className="btn-ghost text-xs">
                  <i className="bi bi-plus-lg mr-1" />+ Добавить атрибут
                </button>
              </div>

              {form.options.length > 0 && (
                <div className="space-y-2">
                  <div className="grid grid-cols-[1fr_1fr_auto_auto] gap-2 text-xs text-gray-400 px-1">
                    <span>Ключ</span>
                    <span>Значение</span>
                    <span>Тип</span>
                    <span />
                  </div>
                  {form.options.map((opt, i) => (
                    <div key={i} className="grid grid-cols-[1fr_1fr_auto_auto] gap-2 items-center">
                      <input
                        className="input text-sm"
                        placeholder="ключ"
                        value={opt.key}
                        onChange={(e) => updateOption(i, "key", e.target.value)}
                      />
                      <input
                        className="input text-sm"
                        placeholder="значение"
                        value={opt.value}
                        onChange={(e) => updateOption(i, "value", e.target.value)}
                      />
                      <select
                        className="input text-sm w-24"
                        value={opt.type}
                        onChange={(e) => updateOption(i, "type", e.target.value)}
                      >
                        <option value="string">Текст</option>
                        <option value="number">Число</option>
                        <option value="boolean">Да/Нет</option>
                      </select>
                      <button
                        className="btn-ghost text-danger p-1"
                        onClick={() => removeOption(i)}
                        title="Удалить атрибут"
                      >
                        <i className="bi bi-x-lg" />
                      </button>
                    </div>
                  ))}
                </div>
              )}

              {form.options.length === 0 && (
                <p className="text-xs text-gray-400 py-2">
                  Нет атрибутов. Нажмите «+ Добавить атрибут» чтобы добавить пару ключ=значение.
                </p>
              )}
            </div>

            <div className="grid grid-cols-2 gap-3 items-end">
              <Field label="Цвет (hex)" value={form.color} onChange={(v) => setForm({ ...form, color: v })} placeholder="#172747" />
              <label className="flex items-center gap-2 pb-2">
                <input type="checkbox" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />
                Активна
              </label>
            </div>

            {formErr && (
              <div className="rounded-md bg-danger/10 px-3 py-2 text-sm text-danger">{formErr}</div>
            )}
            <div className="flex justify-end gap-2 pt-2">
              <button onClick={() => setForm(null)} className="btn-ghost">Отмена</button>
              <button onClick={saveCat} disabled={busy} className="btn-primary">{busy ? "Сохранение…" : "Сохранить"}</button>
            </div>
          </div>
        </Modal>
      )}
    </div>
  );
}
