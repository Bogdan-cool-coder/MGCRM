"use client";

import { useState } from "react";
import useSWR from "swr";
import { PageHeader } from "@/components/PageHeader";
import { Modal } from "@/components/Modal";
import { FloatingInput } from "@/components/ui/FloatingInput";
import { DataTable } from "@/components/ui/DataTable";
import type { DataTableColumn } from "@/components/ui/DataTable";
import { CountrySelect } from "@/components/Geo/CountrySelect";
import { useToast } from "@/components/ui/Toast";
import { ActiveBadge } from "@/components/SimpleEntityCrud";
import { api, errorMessage, fetcher } from "@/lib/api";
import type { City } from "@/lib/types";

/**
 * CitiesPage — страница городов.
 * Не использует SimpleEntityCrud напрямую, т.к. требует внешний фильтр по стране
 * (SWR-ключ динамический: /cities?country_code=XX, без выбора страны таблица не отображается).
 * Переработана: использует errorMessage из lib/api, delete-confirm Modal вместо confirm(),
 * ActiveBadge из SimpleEntityCrud — всё остальное идентично исходному поведению.
 */

function isCityArray(v: unknown): v is City[] {
  return Array.isArray(v) && (v.length === 0 || (typeof v[0] === "object" && v[0] !== null && "name" in v[0]));
}

type Form = {
  id?: number;
  country_code: string;
  name: string;
  sort_order: string;
  is_active: boolean;
};

const COLUMNS: DataTableColumn<City>[] = [
  {
    key: "sort_order",
    header: "#",
    width: "3rem",
    align: "right",
    skeletonWidth: "40%",
    render: (c) => (
      <span className="tabular-nums text-gray-400 dark:text-gray-500">{c.sort_order}</span>
    ),
  },
  {
    key: "name",
    header: "Название",
    skeletonWidth: "65%",
    render: (c) => (
      <span className={`font-medium ${c.is_active ? "text-gray-900 dark:text-gray-100" : "text-gray-400 dark:text-gray-500"}`}>
        {c.name}
      </span>
    ),
  },
  {
    key: "is_active",
    header: "Статус",
    width: "7rem",
    align: "center",
    skeletonWidth: "60%",
    render: (c) => <ActiveBadge active={c.is_active} />,
  },
];

export default function CitiesPage() {
  const [country, setCountry] = useState<string | null>(null);
  const [search, setSearch] = useState("");
  const [form, setForm] = useState<Form | null>(null);
  const [busy, setBusy] = useState(false);
  const [formErr, setFormErr] = useState<string | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<City | null>(null);
  const [deleting, setDeleting] = useState(false);
  const { toast } = useToast();

  const swrKey = country ? `/cities?country_code=${encodeURIComponent(country)}` : null;
  const { data: raw, mutate } = useSWR<unknown>(swrKey, fetcher);
  const all = isCityArray(raw) ? raw : [];

  const rows = (() => {
    if (!country) return undefined;
    if (raw === undefined) return undefined;
    const q = search.trim().toLowerCase();
    if (!q) return all;
    return all.filter((c) => c.name.toLowerCase().includes(q));
  })();

  function newCity() {
    setFormErr(null);
    setForm({ country_code: country ?? "", name: "", sort_order: "0", is_active: true });
  }

  async function save() {
    if (!form) return;
    if (!form.country_code) { setFormErr("Выберите страну"); return; }
    if (!form.name.trim()) { setFormErr("Введите название города"); return; }
    setFormErr(null);
    setBusy(true);
    try {
      const body: Record<string, unknown> = {
        country_code: form.country_code,
        name: form.name.trim(),
        sort_order: Number(form.sort_order) || 0,
        is_active: form.is_active,
      };
      if (form.id) await api(`/admin/cities/${form.id}`, { method: "PATCH", body });
      else await api("/admin/cities", { method: "POST", body });
      await mutate();
      setForm(null);
      toast.success(form.id ? "Город обновлён" : "Город добавлен");
    } catch (e) {
      setFormErr(errorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  async function confirmDelete() {
    if (!deleteTarget) return;
    setDeleting(true);
    try {
      await api(`/admin/cities/${deleteTarget.id}`, { method: "DELETE" });
      await mutate();
      toast.success("Город удалён");
      setDeleteTarget(null);
    } catch (e) {
      toast.error(errorMessage(e));
    } finally {
      setDeleting(false);
    }
  }

  return (
    <div>
      <PageHeader
        title="Города"
        description="Справочник городов по странам. Список длинный — выберите страну."
        actions={
          <button className="btn-primary text-sm" onClick={newCity} disabled={!country}>
            <i className="bi bi-plus-lg mr-1" /> Город
          </button>
        }
      />
      <div className="p-8 max-w-3xl space-y-4">
        {/* Фильтр по стране */}
        <div className="max-w-xs">
          <CountrySelect
            label="Страна"
            value={country}
            onChange={(c) => { setCountry(c); setSearch(""); }}
            clearable
          />
        </div>

        {!country ? (
          <div className="card flex flex-col items-center justify-center py-12 text-center">
            <i className="bi bi-geo-alt text-4xl text-gray-300 mb-3" />
            <p className="text-sm font-medium text-gray-500">Выберите страну, чтобы увидеть города</p>
          </div>
        ) : (
          <>
            <div className="relative">
              <i className="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm pointer-events-none" />
              <input
                className="input pl-9"
                placeholder="Поиск города"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
            </div>

            <DataTable<City>
              columns={COLUMNS}
              rows={rows}
              getRowKey={(c) => c.id}
              density="compact"
              stickyHeader
              ariaLabel="Справочник городов"
              emptyIcon="bi-buildings"
              emptyTitle="Нет городов"
              emptyText={search ? "Ничего не найдено по запросу" : "В этой стране нет городов. Добавьте первый."}
              emptyCta={
                !search ? (
                  <button className="btn-primary text-sm" onClick={newCity}>
                    <i className="bi bi-plus-lg mr-1" /> Добавить город
                  </button>
                ) : undefined
              }
              rowActions={(c) => (
                <>
                  <button
                    onClick={() => {
                      setFormErr(null);
                      setForm({ id: c.id, country_code: c.country_code, name: c.name, sort_order: String(c.sort_order), is_active: c.is_active });
                    }}
                    className="btn-ghost p-1 text-gray-400 hover:text-primary"
                    title="Редактировать"
                  >
                    <i className="bi bi-pencil text-xs" />
                  </button>
                  <button
                    onClick={() => setDeleteTarget(c)}
                    className="btn-ghost p-1 text-gray-400 hover:text-danger"
                    title="Удалить"
                  >
                    <i className="bi bi-trash text-xs" />
                  </button>
                </>
              )}
            />
          </>
        )}
      </div>

      {/* Модалка create/edit */}
      {form !== null && (
        <Modal
          open
          title={form.id ? "Редактировать город" : "Новый город"}
          onClose={() => setForm(null)}
          width="sm"
          footer={
            <>
              <button type="button" className="btn-secondary" onClick={() => setForm(null)}>Отмена</button>
              <button type="button" className="btn-primary" disabled={busy} onClick={() => void save()}>
                {busy ? "Сохранение…" : "Сохранить"}
              </button>
            </>
          }
        >
          <div className="space-y-4">
            {formErr && (
              <div className="rounded-md bg-danger/10 px-3 py-2 text-sm text-danger">{formErr}</div>
            )}
            <CountrySelect
              label="Страна"
              value={form.country_code || null}
              onChange={(c) => setForm({ ...form, country_code: c ?? "" })}
              required
            />
            <FloatingInput
              label="Название"
              required
              value={form.name}
              onChange={(e) => setForm({ ...form, name: e.target.value })}
            />
            <FloatingInput
              label="Сортировка"
              type="number"
              value={form.sort_order}
              onChange={(e) => setForm({ ...form, sort_order: e.target.value })}
            />
            <label className="flex items-center gap-2 text-sm cursor-pointer select-none">
              <input
                type="checkbox"
                checked={form.is_active}
                onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
                className="accent-primary"
              />
              Активен
            </label>
          </div>
        </Modal>
      )}

      {/* Модалка удаления */}
      {deleteTarget !== null && (
        <Modal
          open
          title={`Удалить «${deleteTarget.name}»?`}
          onClose={() => setDeleteTarget(null)}
          width="sm"
          footer={
            <>
              <button type="button" className="btn-secondary" onClick={() => setDeleteTarget(null)}>Отмена</button>
              <button
                type="button"
                className="btn-primary bg-danger border-danger hover:bg-danger/90"
                disabled={deleting}
                onClick={() => void confirmDelete()}
              >
                {deleting ? "Удаление…" : "Удалить"}
              </button>
            </>
          }
        >
          <p className="text-sm text-gray-700 dark:text-gray-300">
            Это действие нельзя отменить. Город будет удалён из справочника.
          </p>
        </Modal>
      )}
    </div>
  );
}
