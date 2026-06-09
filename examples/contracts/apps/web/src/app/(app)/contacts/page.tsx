"use client";

import { useMemo, useState, useCallback } from "react";
import { useRouter } from "next/navigation";
import useSWR, { mutate as globalMutate } from "swr";
import { PageHeader } from "@/components/PageHeader";
import { Modal } from "@/components/Modal";
import { UserSelect } from "@/components/UserSelect";
import { ContactTypeTag } from "@/components/CRM/ContactTypeTag";
import { SourceSelect } from "@/components/CRM/SourceSelect";
import { ContactQuickForm } from "@/components/CRM/ContactQuickForm";
import { DataTable } from "@/components/ui/DataTable";
import type { DataTableColumn } from "@/components/ui/DataTable";
import { api, ApiError, fetcher } from "@/lib/api";
import { useMe } from "@/lib/auth";
import type { UnifiedContactItem, User } from "@/lib/types";

// ─── Тип для ответа unified endpoint ─────────────────────────────────────────

interface UnifiedResponse {
  items: UnifiedContactItem[];
  total: number;
}

function isUnifiedResponse(v: unknown): v is UnifiedResponse {
  return (
    typeof v === "object" &&
    v !== null &&
    "items" in v &&
    Array.isArray((v as UnifiedResponse).items)
  );
}

// ─── Вспомогательные функции ─────────────────────────────────────────────────

function getExtraString(item: UnifiedContactItem, key: string): string | null {
  const v = item.extra[key];
  return typeof v === "string" ? v : null;
}

function getExtraNumber(item: UnifiedContactItem, key: string): number | null {
  const v = item.extra[key];
  return typeof v === "number" ? v : null;
}

// ─── Avatar инициалы ──────────────────────────────────────────────────────────

function ContactAvatar({ item }: { item: UnifiedContactItem }) {
  const initials = item.name
    .split(" ")
    .slice(0, 2)
    .map((w) => w[0]?.toUpperCase() ?? "")
    .join("");

  const bg =
    item.kind === "person"
      ? "bg-info/10 text-info"
      : "bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary-light";

  return (
    <span
      className={`inline-flex items-center justify-center w-7 h-7 rounded-full text-[11px] font-semibold shrink-0 select-none ${bg}`}
      aria-hidden="true"
    >
      {initials || <i className={`bi ${item.kind === "person" ? "bi-person" : "bi-building"} text-xs`} />}
    </span>
  );
}

// ─── Компонент ────────────────────────────────────────────────────────────────

export default function ContactsPage() {
  const router = useRouter();
  const { user } = useMe();
  const canDelete = user?.role === "admin" || user?.role === "director";

  // ── Фильтры ──────────────────────────────────────────────────────────────────
  const [q, setQ] = useState("");
  const [kindFilter, setKindFilter] = useState<"all" | "person" | "company">("all");
  const [sourceFilter, setSourceFilter] = useState("");
  const [ownerFilter, setOwnerFilter] = useState("");

  const filtersDirty =
    q.length > 0 || kindFilter !== "all" || sourceFilter !== "" || ownerFilter !== "";

  function resetFilters() {
    setQ("");
    setKindFilter("all");
    setSourceFilter("");
    setOwnerFilter("");
  }

  // ── SWR ───────────────────────────────────────────────────────────────────────
  const swrKey = useMemo(() => {
    const qs = new URLSearchParams();
    if (kindFilter !== "all") qs.set("type", kindFilter === "person" ? "person" : "company");
    if (q.trim()) qs.set("q", q.trim());
    if (sourceFilter) qs.set("source", sourceFilter);
    if (ownerFilter) qs.set("owner_id", ownerFilter);
    const s = qs.toString();
    return s ? `/contacts/unified?${s}` : "/contacts/unified";
  }, [q, kindFilter, sourceFilter, ownerFilter]);

  const { data: rawData, error: loadError } = useSWR<unknown>(swrKey, fetcher);
  const { data: users } = useSWR<User[]>("/users", fetcher);

  const items: UnifiedContactItem[] | undefined = useMemo(() => {
    if (rawData === undefined) return undefined; // loading
    if (isUnifiedResponse(rawData)) return rawData.items;
    if (Array.isArray(rawData)) return rawData as UnifiedContactItem[];
    return [];
  }, [rawData]);

  const userMap = useMemo(() => {
    const m = new Map<number, string>();
    (users ?? []).forEach((u) => m.set(u.id, u.full_name));
    return m;
  }, [users]);

  // ── Создание контакта ─────────────────────────────────────────────────────────
  const [quickFormOpen, setQuickFormOpen] = useState(false);

  // ── Удаление ──────────────────────────────────────────────────────────────────
  const [deleteTarget, setDeleteTarget] = useState<UnifiedContactItem | null>(null);
  const [deleting, setDeleting] = useState(false);
  const [deleteError, setDeleteError] = useState<string | null>(null);

  const handleDeleteConfirm = useCallback(async () => {
    if (!deleteTarget) return;
    setDeleting(true);
    setDeleteError(null);
    try {
      const endpoint =
        deleteTarget.kind === "person"
          ? `/contacts/${deleteTarget.id}`
          : `/companies/${deleteTarget.id}`;
      await api(endpoint, { method: "DELETE" });
      await globalMutate(
        (key: unknown) => typeof key === "string" && key.includes("/contacts"),
      );
      setDeleteTarget(null);
    } catch (err) {
      setDeleteError(
        err instanceof ApiError
          ? String((err.detail as { detail?: string })?.detail ?? err.message)
          : "Не удалось удалить",
      );
    } finally {
      setDeleting(false);
    }
  }, [deleteTarget]);

  // ── Навигация при клике на строку ─────────────────────────────────────────────
  const handleRowClick = useCallback(
    (item: UnifiedContactItem) => {
      if (item.kind === "person") {
        router.push(`/contacts/${item.id}`);
      } else {
        router.push(`/companies/${item.id}`);
      }
    },
    [router],
  );

  // ── Колонки DataTable ─────────────────────────────────────────────────────────
  // Порядок: Имя → Тип → Телефон → Email → Связь → Владелец
  // Ширины подобраны под ~1200px рабочей зоны (1440 минус sidebar 240 минус padding 64)
  // ── Колонки DataTable ─────────────────────────────────────────────────────────
  // tableLayout="fixed" → ширины соблюдаются точно.
  // Порядок: Имя(18rem) → Тип(7rem) → Телефон(10rem) → Email(гибкая) → Компания(10rem) → Владелец(9rem)
  // Email без width: в table-fixed забирает весь остаток (≈200-300px на 1200px).
  // ~1200px рабочая зона (1440 − sidebar 240): фикс-сумма ≈904px, Email получает ~232px+.
  const columns = useMemo((): DataTableColumn<UnifiedContactItem>[] => [
    {
      key: "name",
      header: "Имя / Название",
      width: "18rem",
      skeletonWidth: "65%",
      render: (item) => {
        const legalName = getExtraString(item, "legal_name");
        return (
          <div className="flex items-center gap-2 min-w-0">
            <ContactAvatar item={item} />
            <div className="min-w-0">
              <div className="font-medium text-gray-900 dark:text-gray-100 truncate leading-snug">
                {item.name}
              </div>
              {item.kind === "company" && legalName && legalName !== item.name && (
                <div className="text-xs text-gray-400 dark:text-gray-500 truncate leading-snug">
                  {legalName}
                </div>
              )}
            </div>
          </div>
        );
      },
    },
    {
      key: "kind",
      header: "Тип",
      width: "7rem",
      skeletonWidth: "70%",
      render: (item) => <ContactTypeTag kind={item.kind} />,
    },
    {
      key: "phone",
      header: "Телефон",
      width: "10rem",
      skeletonWidth: "55%",
      render: (item) =>
        item.phone ? (
          <span className="tabular-nums text-gray-700 dark:text-gray-300 whitespace-nowrap">
            {item.phone}
          </span>
        ) : (
          <span className="text-gray-300 dark:text-gray-600">—</span>
        ),
    },
    {
      // Нет width — в table-fixed эта колонка получает весь остаток (гибкая).
      key: "email",
      header: "Email",
      skeletonWidth: "70%",
      render: (item) =>
        item.email ? (
          <span className="text-gray-700 dark:text-gray-300 truncate block">
            {item.email}
          </span>
        ) : (
          <span className="text-gray-300 dark:text-gray-600">—</span>
        ),
    },
    {
      key: "link",
      header: "Компания",
      width: "10rem",
      skeletonWidth: "50%",
      render: (item) => {
        const companyId = getExtraNumber(item, "company_id");
        const companyName = getExtraString(item, "company_name");
        if (item.kind === "person" && companyId) {
          return (
            <a
              href={`/companies/${companyId}`}
              onClick={(e) => e.stopPropagation()}
              className="text-primary dark:text-primary-light hover:underline text-sm truncate block"
            >
              {companyName ?? `Компания #${companyId}`}
            </a>
          );
        }
        return <span className="text-gray-300 dark:text-gray-600">—</span>;
      },
    },
    {
      key: "owner_id",
      header: "Владелец",
      width: "9rem",
      skeletonWidth: "55%",
      render: (item) => {
        const name = item.owner_id ? (userMap.get(item.owner_id) ?? `#${item.owner_id}`) : "—";
        return (
          <span className="text-gray-700 dark:text-gray-300 text-sm truncate block">
            {name}
          </span>
        );
      },
    },
  ], [userMap]);

  // ── row actions ───────────────────────────────────────────────────────────────
  const rowActions = useCallback(
    (item: UnifiedContactItem) => {
      const url = item.kind === "person" ? `/contacts/${item.id}` : `/companies/${item.id}`;
      return (
        <>
          <a
            href={url}
            target="_blank"
            rel="noopener noreferrer"
            title="Открыть в новой вкладке"
            onClick={(e) => e.stopPropagation()}
            className="btn-ghost p-1 text-gray-400 hover:text-primary dark:hover:text-primary-light"
          >
            <i className="bi bi-arrow-up-right-square text-sm" />
          </a>
          {canDelete && (
            <button
              type="button"
              title="Удалить"
              onClick={(e) => {
                e.stopPropagation();
                setDeleteTarget(item);
              }}
              className="btn-ghost p-1 text-gray-400 hover:text-danger"
            >
              <i className="bi bi-trash text-sm" />
            </button>
          )}
        </>
      );
    },
    [canDelete],
  );

  // ─── EmptyState CTA ───────────────────────────────────────────────────────────
  const emptyCta = filtersDirty ? (
    <button className="btn-secondary text-sm" onClick={resetFilters}>
      <i className="bi bi-x-circle mr-1" />
      Сбросить фильтры
    </button>
  ) : (
    <button className="btn-primary text-sm" onClick={() => setQuickFormOpen(true)}>
      <i className="bi bi-plus-lg mr-1" />
      Контакт
    </button>
  );

  // ─── Render ──────────────────────────────────────────────────────────────────

  return (
    <>
      <PageHeader
        title="Контакты"
        description="Физические лица и компании — вся база в одном месте"
        actions={
          <button className="btn-primary text-sm" onClick={() => setQuickFormOpen(true)}>
            <i className="bi bi-plus-lg mr-1" />
            Контакт
          </button>
        }
      />

      <div className="px-8 pt-4 pb-8 space-y-4">
        {/* Фильтры */}
        <div className="grid grid-cols-1 md:grid-cols-5 gap-2">
          <input
            className="input md:col-span-2"
            placeholder="Поиск по имени, телефону или email"
            value={q}
            onChange={(e) => setQ(e.target.value)}
          />
          <select
            className="input"
            value={kindFilter}
            onChange={(e) =>
              setKindFilter(e.target.value as "all" | "person" | "company")
            }
          >
            <option value="all">Все типы</option>
            <option value="person">Физ. лицо</option>
            <option value="company">Компания</option>
          </select>
          <SourceSelect
            value={sourceFilter}
            onChange={setSourceFilter}
            placeholder="Все источники"
          />
          <div className="flex gap-2">
            <UserSelect
              value={ownerFilter}
              onChange={setOwnerFilter}
              users={users}
              placeholder="Все владельцы"
              className="input flex-1"
            />
            {filtersDirty && (
              <button
                onClick={resetFilters}
                className="btn-ghost px-2"
                title="Сбросить фильтры"
              >
                <i className="bi bi-x-circle" />
              </button>
            )}
          </div>
        </div>

        {/* Таблица */}
        <DataTable
          columns={columns}
          rows={loadError ? [] : items}
          getRowKey={(item) => `${item.kind}-${item.id}`}
          onRowClick={handleRowClick}
          rowActions={rowActions}
          density="compact"
          tableLayout="fixed"
          ariaLabel="Список контактов"
          isError={!!loadError}
          errorText="Не удалось загрузить контакты"
          emptyIcon="bi-people"
          emptyTitle={filtersDirty ? "Ничего не найдено" : "Пока нет контактов"}
          emptyText={
            filtersDirty
              ? "По заданным фильтрам ничего не найдено"
              : "Создай первый контакт — физлицо или компанию — чтобы вести базу"
          }
          emptyCta={emptyCta}
          skeletonRows={7}
        />
      </div>

      {/* Форма создания */}
      <ContactQuickForm
        open={quickFormOpen}
        onClose={() => setQuickFormOpen(false)}
        onCreated={() =>
          globalMutate(
            (key: unknown) => typeof key === "string" && key.includes("/contacts"),
          )
        }
      />

      {/* Модалка подтверждения удаления */}
      <Modal
        open={!!deleteTarget}
        onClose={() => {
          setDeleteTarget(null);
          setDeleteError(null);
        }}
        title="Удалить запись?"
        width="sm"
        footer={
          <>
            <button
              type="button"
              className="btn-secondary"
              onClick={() => {
                setDeleteTarget(null);
                setDeleteError(null);
              }}
            >
              Отмена
            </button>
            <button
              type="button"
              className="btn-primary bg-danger border-danger hover:bg-danger/90"
              onClick={handleDeleteConfirm}
              disabled={deleting}
            >
              {deleting ? "Удаление…" : "Удалить"}
            </button>
          </>
        }
      >
        {deleteError && (
          <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded mb-3">
            {deleteError}
          </div>
        )}
        {deleteTarget && (
          <p className="text-sm text-gray-700 dark:text-gray-300">
            {deleteTarget.kind === "person" ? (
              <>
                Удалить контакт «<strong>{deleteTarget.name}</strong>»? Действие
                необратимо.
              </>
            ) : (
              <>
                Удалить компанию «<strong>{deleteTarget.name}</strong>»? Все связанные
                контакты сохранятся, но потеряют привязку.
              </>
            )}
          </p>
        )}
      </Modal>
    </>
  );
}
