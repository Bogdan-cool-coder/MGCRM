"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import useSWR from "swr";
import { ActivityForm } from "./ActivityForm";
import { TimelineItem } from "./TimelineItem";
import { fetcher } from "@/lib/api";
import { useMe } from "@/lib/auth";
import { formatDateTimeRelative } from "@/lib/dates";
import {
  ACTIVITY_KIND_LABELS,
  type Activity,
  type ActivityKind,
  type ActivityTargetType,
} from "@/lib/types";

interface TimelineProps {
  targetType: ActivityTargetType;
  targetId: number;
  /** Стартовый лимит. По «Показать ещё» увеличиваем на тот же шаг. */
  initialLimit?: number;
  /**
   * Порядок отображения групп:
   * - "desc" (по умолчанию) — новое сверху (классический таймлайн).
   * - "asc" — старое сверху, новое снизу (чат-режим карточки сделки).
   */
  order?: "asc" | "desc";
  /**
   * Положение формы-композера:
   * - "top" (по умолчанию) — над лентой.
   * - "bottom" — под лентой (чат-режим).
   */
  composer?: "top" | "bottom";
  /**
   * Чат-режим: лента занимает доступную высоту и скроллится, композер
   * закреплён снизу. Требует, чтобы родитель задал высоту контейнеру.
   */
  chat?: boolean;
  /**
   * Wave 5: запрашивать связанные активности (с дочерних сущностей).
   * Для company — подтягивает активности по её сделкам/контактам.
   * Добавляет `&include_related=true` в запрос.
   */
  includeRelated?: boolean;
  /**
   * Wave 5: показывать бейдж источника записи («по компании» / «по сделке»).
   * Имеет смысл вместе с includeRelated.
   */
  showSourceBadge?: boolean;
  /**
   * Wave 5: связанные сделки (id+title) для резолва названия в бейдже
   * «по сделке «{title}»». Передаётся со страницы (уже загружены).
   */
  relatedDeals?: { id: number; title: string }[];
}

type CompletedFilter = "" | "open" | "done";
const KIND_FILTERS: { value: ActivityKind | ""; label: string }[] = [
  { value: "", label: "Все" },
  { value: "call", label: ACTIVITY_KIND_LABELS.call },
  { value: "meeting", label: ACTIVITY_KIND_LABELS.meeting },
  { value: "task", label: ACTIVITY_KIND_LABELS.task },
  { value: "note", label: ACTIVITY_KIND_LABELS.note },
];

const COMPLETED_FILTERS: { value: CompletedFilter; label: string }[] = [
  { value: "", label: "Все" },
  { value: "open", label: "Открытые" },
  { value: "done", label: "Выполненные" },
];

function dayKey(iso: string): string {
  const d = new Date(iso);
  if (isNaN(d.getTime())) return iso;
  return `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`;
}

/**
 * Wave 5: текст бейджа источника записи для агрегированной ленты компании.
 * Возвращает null, если запись принадлежит самой просматриваемой сущности
 * (бейдж не нужен).
 */
function resolveSourceBadge(
  a: Activity,
  targetType: ActivityTargetType,
  targetId: number,
  dealTitleById: Map<number, string>,
): string | null {
  // Запись по самой компании — бейдж не показываем.
  if (a.target_type === targetType && a.target_id === targetId) return null;
  if (a.target_type === "deal" && a.target_id != null) {
    const title = dealTitleById.get(a.target_id);
    return title ? `по сделке «${title}»` : "по сделке";
  }
  if (a.target_type === "contact") return "по контакту";
  if (a.target_type === "company") return "по компании";
  return null;
}

export function Timeline({
  targetType,
  targetId,
  initialLimit = 50,
  order = "desc",
  composer = "top",
  chat = false,
  includeRelated = false,
  showSourceBadge = false,
  relatedDeals,
}: TimelineProps) {
  const { user } = useMe();
  const canDeleteAny = user?.role === "admin" || user?.role === "director";

  const [kind, setKind] = useState<ActivityKind | "">("");
  const [completed, setCompleted] = useState<CompletedFilter>("");
  const [limit, setLimit] = useState(initialLimit);
  const [editing, setEditing] = useState<Activity | null>(null);

  const feedRef = useRef<HTMLDivElement>(null);

  const swrKey = useMemo(() => {
    const qs = new URLSearchParams();
    qs.set("target_type", targetType);
    qs.set("target_id", String(targetId));
    if (kind) qs.set("kind", kind);
    if (completed === "done") qs.set("completed", "true");
    if (completed === "open") qs.set("completed", "false");
    if (includeRelated) qs.set("include_related", "true");
    qs.set("limit", String(limit));
    return `/activities?${qs.toString()}`;
  }, [targetType, targetId, kind, completed, limit, includeRelated]);

  const { data: activities, error, isLoading, mutate } = useSWR<Activity[]>(swrKey, fetcher);

  const handleSaved = useCallback(() => {
    mutate();
    setEditing(null);
  }, [mutate]);

  const handleEdit = useCallback((a: Activity) => {
    setEditing(a);
    // Прокрутка к форме. В чат-режиме форма снизу — скроллим ленту вниз.
    if (typeof window !== "undefined" && !chat) {
      window.scrollTo({ top: 0, behavior: "smooth" });
    }
  }, [chat]);

  const grouped = useMemo(() => {
    const groups: { day: string; label: string; items: Activity[] }[] = [];
    const map = new Map<string, Activity[]>();
    for (const a of activities ?? []) {
      const k = dayKey(a.created_at);
      let arr = map.get(k);
      if (!arr) {
        arr = [];
        map.set(k, arr);
        groups.push({ day: k, label: formatDateTimeRelative(a.created_at), items: arr });
      }
      arr.push(a);
    }
    // API отдаёт новое-сверху (desc). Для чат-режима (asc) разворачиваем
    // и группы, и элементы внутри них, чтобы новое было снизу.
    if (order === "asc") {
      groups.reverse();
      for (const g of groups) g.items.reverse();
    }
    return groups;
  }, [activities, order]);

  const hasMore = (activities?.length ?? 0) >= limit;

  // Чат-режим: автоскролл ленты вниз при появлении новых записей.
  useEffect(() => {
    if (!chat || order !== "asc") return;
    const el = feedRef.current;
    if (el) el.scrollTop = el.scrollHeight;
  }, [chat, order, activities?.length]);

  function canDeleteItem(a: Activity): boolean {
    return canDeleteAny || (user?.id != null && user.id === a.created_by_id);
  }

  // Резолв названий связанных сделок для бейджа «по сделке «{title}»».
  const dealTitleById = useMemo(() => {
    const m = new Map<number, string>();
    for (const d of relatedDeals ?? []) m.set(d.id, d.title);
    return m;
  }, [relatedDeals]);

  const composerNode = (
    <div className="space-y-1">
      <ActivityForm
        targetType={targetType}
        targetId={targetId}
        editingActivity={editing}
        onSaved={handleSaved}
        onCancel={() => setEditing(null)}
      />
      {includeRelated && (
        <p className="text-xs text-gray-400 dark:text-gray-500">
          Новая запись привяжется к этой компании. Активности по сделкам компании
          показаны ниже и отмечены бейджем.
        </p>
      )}
    </div>
  );

  const filtersNode = (
    <div className="flex flex-wrap items-center gap-2">
      <span className="text-xs text-gray-500 uppercase tracking-wide mr-1">Тип:</span>
      <div className="inline-flex rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden text-xs">
        {KIND_FILTERS.map((f) => (
          <button
            key={f.value || "all"}
            type="button"
            onClick={() => setKind(f.value)}
            className={kind === f.value
              ? "px-2.5 py-1 bg-primary text-white"
              : "px-2.5 py-1 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700"}
          >
            {f.label}
          </button>
        ))}
      </div>
      <span className="text-xs text-gray-500 uppercase tracking-wide ml-2 mr-1">Статус:</span>
      <div className="inline-flex rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden text-xs">
        {COMPLETED_FILTERS.map((f) => (
          <button
            key={f.value || "all"}
            type="button"
            onClick={() => setCompleted(f.value)}
            className={completed === f.value
              ? "px-2.5 py-1 bg-primary text-white"
              : "px-2.5 py-1 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700"}
          >
            {f.label}
          </button>
        ))}
      </div>
    </div>
  );

  // «Показать ещё» в asc-режиме логичнее держать СВЕРХУ ленты (более старые
  // записи догружаются над текущими). В desc — снизу (как было).
  const loadMoreNode = hasMore ? (
    <div className="text-center pt-2">
      <button
        type="button"
        onClick={() => setLimit((l) => l + initialLimit)}
        className="btn-ghost text-sm"
      >
        <i className="bi bi-chevron-up mr-1" /> Показать более ранние
      </button>
    </div>
  ) : null;

  const statesNode = (
    <>
      {error && (
        <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">
          Не удалось загрузить ленту.
        </div>
      )}
      {isLoading && !activities && (
        <div className="text-sm text-gray-500">Загрузка…</div>
      )}
      {activities && activities.length === 0 && !isLoading && (
        <div className="text-sm text-gray-500 text-center py-8">
          {kind || completed
            ? "По заданным фильтрам ничего не найдено."
            : "Записей пока нет. Добавьте первое событие."}
        </div>
      )}
    </>
  );

  const groupsNode = grouped.map((g) => (
    <div key={g.day} className="space-y-2">
      <div className="text-xs uppercase tracking-wide text-gray-400 border-b border-gray-100 dark:border-gray-800 pb-1">
        {g.label}
      </div>
      <div className="space-y-2">
        {g.items.map((a) => (
          <TimelineItem
            key={a.id}
            activity={a}
            canDelete={canDeleteItem(a)}
            onMutated={() => mutate()}
            onEdit={handleEdit}
            sourceBadge={
              showSourceBadge
                ? resolveSourceBadge(a, targetType, targetId, dealTitleById)
                : null
            }
          />
        ))}
      </div>
    </div>
  ));

  // ── Чат-режим: фикс-высота, лента скроллится, композер снизу ──────────────
  if (chat) {
    return (
      <div className="flex flex-col h-full min-h-0">
        <div className="shrink-0 pb-3">{filtersNode}</div>
        <div ref={feedRef} className="flex-1 min-h-0 overflow-y-auto space-y-4 pr-1">
          {order === "asc" && loadMoreNode}
          {statesNode}
          {groupsNode}
          {order === "desc" && loadMoreNode}
        </div>
        {composer === "bottom" && (
          <div className="shrink-0 pt-3 border-t border-gray-200 dark:border-gray-700 mt-3">
            {composerNode}
          </div>
        )}
      </div>
    );
  }

  // ── Обычный режим (обратная совместимость) ────────────────────────────────
  return (
    <div className="space-y-4">
      {composer === "top" && composerNode}
      {filtersNode}
      {statesNode}
      {order === "asc" && loadMoreNode}
      {groupsNode}
      {order === "desc" && loadMoreNode}
      {composer === "bottom" && composerNode}
    </div>
  );
}
