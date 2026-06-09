"use client";

import { useEffect, useRef, useState } from "react";
import useSWR from "swr";
import { fetcher, errorMessage } from "@/lib/api";
import { PageHeader } from "@/components/PageHeader";
import { NotificationItem } from "@/components/Notifications/NotificationItem";
import { useNotifications } from "@/hooks/useNotifications";
import type { Notification, NotificationListOut } from "@/lib/types";

// Вспомогательный хук: аккумулирует страницы уведомлений при нажатии «Загрузить ещё».
// Сбрасывает накопленный список при смене фильтров (filterKey).
function useAccumulatedNotifications(swrKey: string, filterKey: string) {
  const { data, isLoading, mutate } = useSWR<NotificationListOut>(swrKey, fetcher, {
    refreshInterval: 60_000,
  });
  const [accumulated, setAccumulated] = useState<Notification[]>([]);
  const prevFilterKey = useRef<string>(filterKey);
  const prevSwrKey = useRef<string>(swrKey);

  useEffect(() => {
    if (filterKey !== prevFilterKey.current) {
      // Фильтры изменились — сбрасываем накопленное
      setAccumulated([]);
      prevFilterKey.current = filterKey;
      prevSwrKey.current = swrKey;
      return;
    }
    if (!data?.items) return;
    if (swrKey === prevSwrKey.current && accumulated.length === 0) {
      // Первая загрузка или мутация — просто берём свежие данные
      setAccumulated(data.items);
      return;
    }
    if (swrKey !== prevSwrKey.current) {
      // Offset изменился (нажали «Загрузить ещё») — добавляем к накопленным
      prevSwrKey.current = swrKey;
      setAccumulated((prev) => {
        const existingIds = new Set(prev.map((n) => n.id));
        const newItems = data.items.filter((n) => !existingIds.has(n.id));
        return [...prev, ...newItems];
      });
    } else {
      // Тот же ключ (мутация/рефреш) — обновляем накопленное
      setAccumulated(data.items);
    }
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [data, swrKey, filterKey]);

  return { data, isLoading, mutate, accumulated, setAccumulated };
}

// ── Тип + kind опции ────────────────────────────────────────────────────

const KIND_OPTIONS = [
  { value: "task_assigned", label: "Назначена задача" },
  { value: "task_status_changed", label: "Изменён статус задачи" },
  { value: "task_extend_requested", label: "Запрос продления срока" },
  { value: "deal_won", label: "Выиграна сделка" },
  { value: "deal_stage_changed", label: "Изменился этап сделки" },
  { value: "approval_needed", label: "Требуется согласование" },
  { value: "sla_breach", label: "Нарушен SLA" },
  { value: "course_assigned", label: "Назначен курс" },
  { value: "course_completed", label: "Курс завершён" },
  { value: "contract_signed", label: "Подписан договор" },
  { value: "mention", label: "Упоминание" },
  { value: "system", label: "Системное сообщение" },
  { value: "webhook_delivery_failed", label: "Ошибка вебхука" },
];

type SortOption = "newest" | "oldest" | "priority";
type PeriodOption = "all" | "today" | "yesterday" | "week" | "month" | "prev_month";

function getPeriodDates(period: PeriodOption): { date_from?: string; date_to?: string } {
  const now = new Date();
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());

  const fmt = (d: Date) => d.toISOString().split("T")[0];

  switch (period) {
    case "today":
      return { date_from: fmt(today) };
    case "yesterday": {
      const y = new Date(today);
      y.setDate(y.getDate() - 1);
      return { date_from: fmt(y), date_to: fmt(today) };
    }
    case "week": {
      const w = new Date(today);
      w.setDate(w.getDate() - 7);
      return { date_from: fmt(w) };
    }
    case "month": {
      const m = new Date(today.getFullYear(), today.getMonth(), 1);
      return { date_from: fmt(m) };
    }
    case "prev_month": {
      const pm = new Date(today.getFullYear(), today.getMonth() - 1, 1);
      const pmEnd = new Date(today.getFullYear(), today.getMonth(), 1);
      return { date_from: fmt(pm), date_to: fmt(pmEnd) };
    }
    default:
      return {};
  }
}

const PAGE_LIMIT = 50;

// ── Multi-select kind dropdown ──────────────────────────────────────────

function KindMultiSelect({
  selected,
  onChange,
}: {
  selected: string[];
  onChange: (v: string[]) => void;
}) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    function handleMouseDown(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) {
        setOpen(false);
      }
    }
    if (open) {
      document.addEventListener("mousedown", handleMouseDown);
    }
    return () => document.removeEventListener("mousedown", handleMouseDown);
  }, [open]);

  function toggle(value: string) {
    if (selected.includes(value)) {
      onChange(selected.filter((v) => v !== value));
    } else {
      onChange([...selected, value]);
    }
  }

  const label =
    selected.length === 0
      ? "Тип: все"
      : `Тип: ${selected.length} выбрано`;

  return (
    <div className="relative" ref={ref}>
      <button
        type="button"
        className="btn-secondary text-sm py-1.5 px-3 flex items-center gap-2"
        onClick={() => setOpen((v) => !v)}
      >
        {label}
        <i className={`bi-chevron-down text-xs transition-transform ${open ? "rotate-180" : ""}`} />
      </button>

      {open && (
        <div className="absolute z-20 mt-1 card shadow-md w-56 p-2 space-y-0.5 max-h-72 overflow-y-auto">
          {KIND_OPTIONS.map((opt) => (
            <label
              key={opt.value}
              className="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer text-sm text-gray-700 dark:text-gray-300"
            >
              <input
                type="checkbox"
                className="accent-primary"
                checked={selected.includes(opt.value)}
                onChange={() => toggle(opt.value)}
              />
              {opt.label}
            </label>
          ))}
        </div>
      )}
    </div>
  );
}

// ── Page ────────────────────────────────────────────────────────────────

export default function NotificationsPage() {
  const [selectedKinds, setSelectedKinds] = useState<string[]>([]);
  const [period, setPeriod] = useState<PeriodOption>("all");
  const [sort, setSort] = useState<SortOption>("newest");
  const [unreadOnly, setUnreadOnly] = useState(false);
  const [offset, setOffset] = useState(0);
  const [bulkReading, setBulkReading] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);

  const { date_from, date_to } = getPeriodDates(period);

  // filterKey меняется при смене любого фильтра (не offset) — сигнал для сброса аккумулятора
  const filterKey = `${selectedKinds.join(",")}|${period}|${sort}|${unreadOnly}`;

  const params = new URLSearchParams({ limit: String(PAGE_LIMIT), offset: String(offset) });
  if (selectedKinds.length === 1) params.set("kind", selectedKinds[0]);
  if (selectedKinds.length > 1) {
    selectedKinds.forEach((k) => params.append("kind[]", k));
  }
  if (date_from) params.set("date_from", date_from);
  if (date_to) params.set("date_to", date_to);
  if (sort !== "newest") params.set("sort", sort);
  if (unreadOnly) params.set("unread_only", "true");

  const swrKey = `/api/notifications?${params.toString()}`;

  const { data, isLoading, mutate, accumulated, setAccumulated } = useAccumulatedNotifications(swrKey, filterKey);
  const { markRead, markAllRead, bulkMarkRead } = useNotifications();

  const notifications: Notification[] = accumulated;
  const total = data?.total ?? 0;
  const hasMore = offset + PAGE_LIMIT < total;

  const hasFilters =
    selectedKinds.length > 0 || period !== "all" || sort !== "newest" || unreadOnly;

  async function handleMarkAllRead() {
    setActionError(null);
    try {
      await markAllRead();
      setAccumulated([]);
      await mutate();
    } catch (err: unknown) {
      setActionError(errorMessage(err, "Не удалось отметить все прочитанными"));
    }
  }

  async function handleMarkRead(id: number) {
    setActionError(null);
    try {
      await markRead(id);
      // Обновляем is_read прямо в аккумуляторе — без сброса пагинации
      setAccumulated((prev) => prev.map((n) => n.id === id ? { ...n, is_read: true } : n));
      await mutate();
    } catch (err: unknown) {
      setActionError(errorMessage(err, "Не удалось отметить прочитанным"));
    }
  }

  async function handleBulkReadPage() {
    setBulkReading(true);
    setActionError(null);
    try {
      const ids = notifications.filter((n) => !n.is_read).map((n) => n.id);
      if (ids.length > 0) {
        await bulkMarkRead(ids);
      }
      setAccumulated((prev) => prev.map((n) => ids.includes(n.id) ? { ...n, is_read: true } : n));
      await mutate();
    } catch (err: unknown) {
      setActionError(errorMessage(err, "Не удалось отметить страницу прочитанной"));
    } finally {
      setBulkReading(false);
    }
  }

  function handleReset() {
    setSelectedKinds([]);
    setPeriod("all");
    setSort("newest");
    setUnreadOnly(false);
    setOffset(0);
  }

  function handleKindsChange(v: string[]) {
    setSelectedKinds(v);
    setOffset(0);
  }

  function handlePeriodChange(v: PeriodOption) {
    setPeriod(v);
    setOffset(0);
  }

  function handleSortChange(v: SortOption) {
    setSort(v);
    setOffset(0);
  }

  function handleUnreadToggle() {
    setUnreadOnly((v) => !v);
    setOffset(0);
  }

  return (
    <>
      <PageHeader
        title="Уведомления"
        description="события и упоминания"
        actions={
          <div className="flex items-center gap-2">
            <button
              type="button"
              className="btn-ghost text-sm"
              disabled={bulkReading || notifications.length === 0}
              onClick={() => void handleBulkReadPage()}
            >
              {bulkReading ? (
                <><i className="bi-arrow-clockwise animate-spin mr-1" />Читаем…</>
              ) : (
                <><i className="bi-check2 mr-1" />Прочитать всё на странице</>
              )}
            </button>
            <button
              type="button"
              className="btn-ghost text-sm"
              onClick={() => void handleMarkAllRead()}
            >
              <i className="bi-check2-all mr-1" /> Прочитать все
            </button>
          </div>
        }
      />

      <div className="p-8 max-w-3xl space-y-4">
        {actionError && (
          <div className="card p-3 text-sm text-danger bg-danger/10 border border-danger/20">
            {actionError}
          </div>
        )}

        {/* Filters */}
        <div className="card p-4">
          <div className="flex flex-wrap items-center gap-3">
            <KindMultiSelect selected={selectedKinds} onChange={handleKindsChange} />

            <select
              className="input text-sm"
              value={period}
              onChange={(e) => handlePeriodChange(e.target.value as PeriodOption)}
            >
              <option value="all">За всё время</option>
              <option value="today">Сегодня</option>
              <option value="yesterday">Вчера</option>
              <option value="week">Эта неделя</option>
              <option value="month">Этот месяц</option>
              <option value="prev_month">Прошлый месяц</option>
            </select>

            <select
              className="input text-sm"
              value={sort}
              onChange={(e) => handleSortChange(e.target.value as SortOption)}
            >
              <option value="newest">Новые сверху</option>
              <option value="oldest">Старые сверху</option>
              <option value="priority">По приоритету</option>
            </select>

            <label className="flex items-center gap-2 cursor-pointer text-sm text-gray-700 dark:text-gray-300">
              <input
                type="checkbox"
                className="accent-primary"
                checked={unreadOnly}
                onChange={handleUnreadToggle}
              />
              Непрочитанные
            </label>

            {hasFilters && (
              <button
                type="button"
                className="btn-ghost text-sm ml-auto"
                onClick={handleReset}
              >
                <i className="bi-x-circle mr-1" />
                Сбросить фильтры
              </button>
            )}
          </div>
        </div>

        {/* List */}
        <div className="card overflow-hidden">
          {isLoading && (
            <div className="space-y-0">
              {[1, 2, 3, 4, 5].map((i) => (
                <div key={i} className="animate-pulse h-16 bg-gray-100 dark:bg-gray-700 m-4 rounded" />
              ))}
            </div>
          )}

          {!isLoading && notifications.length === 0 && (
            <div className="flex flex-col items-center justify-center py-16 gap-3">
              {hasFilters ? (
                <>
                  <i className="bi-funnel text-5xl text-gray-300 dark:text-gray-600" />
                  <div className="text-center">
                    <div className="font-medium text-gray-700 dark:text-gray-300">
                      Нет уведомлений с этими фильтрами
                    </div>
                    <button
                      type="button"
                      className="btn-ghost text-sm mt-2"
                      onClick={handleReset}
                    >
                      Сбросить фильтры
                    </button>
                  </div>
                </>
              ) : (
                <>
                  <i className="bi-bell-slash text-5xl text-gray-300 dark:text-gray-600" />
                  <div className="text-center">
                    <div className="text-sm text-gray-500 dark:text-gray-400 mt-1">
                      Когда что-то важное произойдёт — ты увидишь это здесь
                    </div>
                  </div>
                </>
              )}
            </div>
          )}

          {!isLoading &&
            notifications.map((n) => (
              <NotificationItem key={n.id} item={n} onMarkRead={handleMarkRead} />
            ))}
        </div>

        {hasMore && (
          <div className="text-center">
            <button
              type="button"
              className="btn-secondary"
              onClick={() => setOffset((v) => v + PAGE_LIMIT)}
            >
              Загрузить ещё
            </button>
          </div>
        )}
      </div>
    </>
  );
}
