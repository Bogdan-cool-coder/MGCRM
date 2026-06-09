"use client";

/**
 * SimpleEntityCrud<T> — универсальный CRUD для простых справочников.
 *
 * Покрывает: sources, product-groups, contact-positions, company-types,
 * platforms, regions и любой аналогичный справочник с полями
 * id / name / code? / sort_order / is_active.
 *
 * Props:
 *   endpoint     — REST base (напр. "/admin/sources"). api() добавит /api prefix.
 *   title        — заголовок кнопки "Добавить X" и модалки.
 *   entityLabel  — человеческое название для тостов/подтверждений (напр. "Источник").
 *   columns      — DataTableColumn<T>[] для таблицы.
 *   buildBody    — (form) => Record<string, unknown>: формирует тело запроса из формы.
 *   formToEdit   — (row: T) => Form: конвертирует строку таблицы в форму редактирования.
 *   defaultForm  — значение пустой формы при создании.
 *   formFields   — (form, setForm) => ReactNode: базовые поля формы.
 *   extraFields? — (form, setForm) => ReactNode: дополнительные поля (после базовых).
 *   searchable?  — показывать поисковую строку (default: true).
 *   searchFilter?— (row: T, q: string) => boolean: кастомный фильтр поиска.
 *   swrKey?      — переопределить SWR-ключ (по умолчанию = endpoint).
 *   emptyIcon?   — Bootstrap Icons класс для EmptyState.
 *   emptyTitle?  — заголовок EmptyState.
 *   validateForm?— (form) => string | null: валидация перед отправкой.
 *   panelMode?   — true = компонент-панель без PageHeader (для вкладок).
 *   maxHeight?   — maxHeight таблицы (default "60vh").
 *
 * Режимы:
 *   - Полная страница (panelMode=false): только таблица + модалки.
 *   - Панель (panelMode=true): заголовок-описание + кнопка сверху.
 *
 * Обратная совместимость: старые props endpoint/title/hasActive сохранены
 * как fallback-режим для cs-config (platforms, regions).
 */

import { useMemo, useState } from "react";
import useSWR from "swr";
import { Modal } from "@/components/Modal";
import { FloatingInput } from "@/components/ui/FloatingInput";
import { DataTable, DataTableColumn } from "@/components/ui/DataTable";
import { useToast } from "@/components/ui/Toast";
import { api, errorMessage, fetcher } from "@/lib/api";

// ─── Базовый тип строки справочника ──────────────────────────────────────────

export interface SimpleRow {
  id: number;
  name: string;
  sort_order: number;
  is_active: boolean;
  code?: string;
}

// ─── Базовая форма ────────────────────────────────────────────────────────────

export interface SimpleForm {
  id?: number;
  name: string;
  sort_order: string;
  is_active: boolean;
  code?: string;
  [key: string]: unknown;
}

// ─── Props ────────────────────────────────────────────────────────────────────

export interface SimpleEntityCrudProps<T extends SimpleRow, F extends SimpleForm> {
  endpoint: string;
  title: string;
  entityLabel?: string;

  /** Колонки DataTable. Если не передан — используется встроенный набор (name + is_active бейдж). */
  columns?: DataTableColumn<T>[];

  /** Формирует тело POST/PATCH запроса из значений формы. */
  buildBody?: (form: F) => Record<string, unknown>;

  /** Конвертирует строку таблицы в значения формы для редактирования. */
  formToEdit?: (row: T) => F;

  /** Дефолтное значение пустой формы (при создании). */
  defaultForm?: () => F;

  /**
   * Базовые поля формы (рендерятся перед is_active).
   * Если не передан — стандартный набор: code?, name, sort_order.
   */
  formFields?: (form: F, setForm: (f: F) => void) => React.ReactNode;

  /** Доп. поля — рендерятся после базовых, перед is_active. */
  extraFields?: (form: F, setForm: (f: F) => void) => React.ReactNode;

  /** Поиск по name/code (default: true). */
  searchable?: boolean;

  /** Кастомный фильтр для поиска. По умолчанию — по name и code. */
  searchFilter?: (row: T, q: string) => boolean;

  /** SWR ключ. По умолчанию равен endpoint. */
  swrKey?: string;

  emptyIcon?: string;
  emptyTitle?: string;

  /** Валидация перед save. Вернуть строку — показать ошибку. Null — OK. */
  validateForm?: (form: F) => string | null;

  /**
   * true = режим панели (без PageHeader/search, кнопка и таблица в одном блоке).
   * false (default) = full-page режим.
   */
  panelMode?: boolean;

  /** Описание под кнопкой в panelMode. */
  panelDescription?: string;

  maxHeight?: string;

  /**
   * Включить поле "Код" в дефолтную форму и колонку "Код" в дефолтные колонки.
   * Нужен для legacy-режима (cs-config: platforms/regions), которые передают только
   * endpoint/title/hasActive без явного defaultForm.
   * В явных формах (formFields) управляй code сам.
   */
  hasCode?: boolean;

  // ── Legacy props (для cs-config backward compat) ─────────────────────────
  /** @deprecated используй columns + buildBody */
  hasActive?: boolean;
}

// ─── Встроенные утилиты ───────────────────────────────────────────────────────

function defaultBuildBody(form: SimpleForm): Record<string, unknown> {
  const body: Record<string, unknown> = {
    name: form.name.trim(),
    sort_order: Number(form.sort_order) || 0,
    is_active: form.is_active,
  };
  if ("code" in form && form.code !== undefined) {
    body.code = (form.code as string).trim();
  }
  return body;
}

function defaultFormToEdit<T extends SimpleRow>(row: T): SimpleForm {
  return {
    id: row.id,
    name: row.name,
    sort_order: String(row.sort_order),
    is_active: row.is_active,
    ...(row.code !== undefined ? { code: row.code } : {}),
  };
}

function defaultEmptyForm(): SimpleForm {
  return { name: "", sort_order: "0", is_active: true };
}

/** Бейдж is_active — зелёный/серый. */
export function ActiveBadge({ active, labelOn = "Активен", labelOff = "Выкл" }: { active: boolean; labelOn?: string; labelOff?: string }) {
  if (active) {
    return (
      <span className="inline-flex items-center gap-1 rounded-full bg-success/10 px-2 py-0.5 text-xs font-medium text-success">
        <i className="bi bi-circle-fill text-[6px]" />
        {labelOn}
      </span>
    );
  }
  return (
    <span className="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-500 dark:bg-gray-700 dark:text-gray-400">
      <i className="bi bi-circle-fill text-[6px]" />
      {labelOff}
    </span>
  );
}

/** Дефолтные колонки: # / Название / is_active бейдж. */
function makeDefaultColumns<T extends SimpleRow>(hasCode: boolean): DataTableColumn<T>[] {
  const cols: DataTableColumn<T>[] = [
    {
      key: "sort_order",
      header: "#",
      width: "3rem",
      align: "right",
      skeletonWidth: "40%",
      render: (r) => (
        <span className="tabular-nums text-gray-400 dark:text-gray-500">{r.sort_order}</span>
      ),
    },
    {
      key: "name",
      header: "Название",
      skeletonWidth: "65%",
      render: (r) => (
        <span className={`font-medium ${r.is_active ? "text-gray-900 dark:text-gray-100" : "text-gray-400 dark:text-gray-500"}`}>
          {r.name}
        </span>
      ),
    },
  ];

  if (hasCode) {
    cols.push({
      key: "code",
      header: "Код",
      width: "11rem",
      skeletonWidth: "50%",
      render: (r) => (
        <span className="font-mono text-xs text-gray-500 dark:text-gray-400">{r.code ?? "—"}</span>
      ),
    });
  }

  cols.push({
    key: "is_active",
    header: "Статус",
    width: "7rem",
    align: "center",
    skeletonWidth: "60%",
    render: (r) => <ActiveBadge active={r.is_active} />,
  });

  return cols;
}

// ─── Компонент ────────────────────────────────────────────────────────────────

export function SimpleEntityCrud<T extends SimpleRow, F extends SimpleForm = SimpleForm>({
  endpoint,
  title,
  entityLabel,
  columns,
  buildBody,
  formToEdit,
  defaultForm,
  formFields,
  extraFields,
  searchable = true,
  searchFilter,
  swrKey,
  emptyIcon = "bi-list-ul",
  emptyTitle,
  validateForm,
  panelMode = false,
  panelDescription,
  maxHeight = "60vh",
  hasCode = false,
  hasActive, // legacy — ignored (is_active always included in defaultBuildBody)
}: SimpleEntityCrudProps<T, F>) {
  const key = swrKey ?? endpoint;
  const { data: rawData, mutate } = useSWR<unknown>(key, fetcher);
  const { toast } = useToast();

  const [search, setSearch] = useState("");
  const [form, setForm] = useState<F | null>(null);
  const [busy, setBusy] = useState(false);
  const [formErr, setFormErr] = useState<string | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<T | null>(null);
  const [deleting, setDeleting] = useState(false);

  // Проверяем что данные — массив SimpleRow
  const all = useMemo<T[]>(() => {
    if (!Array.isArray(rawData)) return [];
    return rawData.filter(
      (item): item is T =>
        item !== null && typeof item === "object" && "id" in item && "name" in item,
    );
  }, [rawData]);

  const isLoading = rawData === undefined;

  // Поиск
  const rows = useMemo<T[] | undefined>(() => {
    if (isLoading) return undefined;
    const q = search.trim().toLowerCase();
    if (!q) return all;
    if (searchFilter) return all.filter((r) => searchFilter(r, q));
    return all.filter(
      (r) =>
        r.name.toLowerCase().includes(q) ||
        (r.code && r.code.toLowerCase().includes(q)),
    );
  }, [all, isLoading, search, searchFilter]);

  // Определяем наличие code: явный prop или детектируем из первой строки данных
  const effectiveHasCode = hasCode || (all.length > 0 && "code" in all[0]);

  const resolvedColumns = columns ?? makeDefaultColumns<T>(effectiveHasCode);

  // ── Helpers ───────────────────────────────────────────────────────────────

  function openCreate() {
    setFormErr(null);
    if (defaultForm) {
      setForm(defaultForm());
    } else {
      // hasCode (explicit prop) или детектируем по уже загруженным данным
      const withCode = hasCode || (all.length > 0 && "code" in all[0]);
      const empty: SimpleForm = { name: "", sort_order: "0", is_active: true };
      if (withCode) empty.code = "";
      setForm(empty as F);
    }
  }

  function openEdit(row: T) {
    setFormErr(null);
    if (formToEdit) {
      setForm(formToEdit(row));
    } else {
      setForm(defaultFormToEdit(row) as F);
    }
  }

  async function save() {
    if (!form) return;
    if (validateForm) {
      const err = validateForm(form);
      if (err) { setFormErr(err); return; }
    } else {
      if (!form.name?.toString().trim()) { setFormErr("Введите название"); return; }
    }
    setFormErr(null);
    setBusy(true);
    try {
      const body = buildBody ? buildBody(form) : defaultBuildBody(form);
      if (form.id) {
        await api(`${endpoint}/${form.id}`, { method: "PATCH", body });
      } else {
        await api(endpoint, { method: "POST", body });
      }
      await mutate();
      setForm(null);
      const label = entityLabel ?? title;
      toast.success(form.id ? `${label} обновлён` : `${label} добавлен`);
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
      await api(`${endpoint}/${deleteTarget.id}`, { method: "DELETE" });
      await mutate();
      const label = entityLabel ?? title;
      toast.success(`${label} удалён`);
      setDeleteTarget(null);
    } catch (e) {
      toast.error(errorMessage(e));
    } finally {
      setDeleting(false);
    }
  }

  // ── Форма: базовые поля ───────────────────────────────────────────────────

  function renderFormFields(f: F, setF: (v: F) => void): React.ReactNode {
    if (formFields) return formFields(f, setF);
    // Дефолт: code (если есть в форме), name, sort_order
    return (
      <>
        {f.code !== undefined && (
          <FloatingInput
            label="Код"
            required
            value={String(f.code ?? "")}
            onChange={(e) => setF({ ...f, code: e.target.value })}
          />
        )}
        <FloatingInput
          label="Название"
          required
          value={f.name}
          onChange={(e) => setF({ ...f, name: e.target.value })}
        />
        <FloatingInput
          label="Сортировка"
          type="number"
          value={f.sort_order}
          onChange={(e) => setF({ ...f, sort_order: e.target.value })}
        />
      </>
    );
  }

  // ── Render ────────────────────────────────────────────────────────────────

  const addButton = (
    <button className="btn-primary text-sm" onClick={openCreate}>
      <i className="bi bi-plus-lg mr-1" /> {title}
    </button>
  );

  const searchBar = searchable && (
    <div className="relative">
      <i className="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm pointer-events-none" />
      <input
        className="input pl-9"
        placeholder="Поиск по названию или коду"
        value={search}
        onChange={(e) => setSearch(e.target.value)}
      />
    </div>
  );

  const table = (
    <DataTable<T>
      columns={resolvedColumns}
      rows={rows}
      getRowKey={(r) => r.id}
      density="compact"
      stickyHeader
      maxHeight={maxHeight}
      ariaLabel={title}
      emptyIcon={emptyIcon}
      emptyTitle={emptyTitle ?? `Нет: ${title}`}
      emptyText={search ? "Ничего не найдено по запросу" : `Добавьте первый(ую) ${entityLabel ?? title.toLowerCase()}`}
      emptyCta={
        !search ? (
          <button className="btn-primary text-sm" onClick={openCreate}>
            <i className="bi bi-plus-lg mr-1" /> Добавить
          </button>
        ) : undefined
      }
      rowActions={(row) => (
        <>
          <button
            onClick={() => openEdit(row)}
            className="btn-ghost p-1 text-gray-400 hover:text-primary"
            title="Редактировать"
          >
            <i className="bi bi-pencil text-xs" />
          </button>
          <button
            onClick={() => setDeleteTarget(row)}
            className="btn-ghost p-1 text-gray-400 hover:text-danger"
            title="Удалить"
          >
            <i className="bi bi-trash text-xs" />
          </button>
        </>
      )}
    />
  );

  return (
    <>
      {panelMode ? (
        // Режим панели (для встраивания во вкладку)
        <div className="max-w-2xl space-y-4">
          <div className="flex items-center justify-between">
            {panelDescription && (
              <p className="text-sm text-gray-500 dark:text-gray-400">{panelDescription}</p>
            )}
            {addButton}
          </div>
          {table}
        </div>
      ) : (
        // Full-page режим
        <div className="space-y-4">
          <div className="flex items-center justify-end">{addButton}</div>
          {searchable && searchBar}
          {table}
        </div>
      )}

      {/* ── Модалка create/edit ─────────────────────────────────────────── */}
      {form !== null && (
        <Modal
          open
          title={form.id ? `Редактировать: ${entityLabel ?? title}` : `Новый(ая): ${entityLabel ?? title}`}
          onClose={() => setForm(null)}
          width="sm"
          footer={
            <>
              <button type="button" className="btn-secondary" onClick={() => setForm(null)}>
                Отмена
              </button>
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
            {renderFormFields(form, setForm)}
            {extraFields && extraFields(form, setForm)}
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

      {/* ── Модалка подтверждения удаления ──────────────────────────────── */}
      {deleteTarget !== null && (
        <Modal
          open
          title={`Удалить «${deleteTarget.name}»?`}
          onClose={() => setDeleteTarget(null)}
          width="sm"
          footer={
            <>
              <button type="button" className="btn-secondary" onClick={() => setDeleteTarget(null)}>
                Отмена
              </button>
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
            Это действие нельзя отменить. {entityLabel ?? title} будет удалён из справочника.
          </p>
        </Modal>
      )}
    </>
  );
}
