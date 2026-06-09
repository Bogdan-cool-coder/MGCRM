"use client";

import { KindBadge } from "@/components/Inbox/KindBadge";
import { BlurFade } from "@/components/magicui/BlurFade";
import { EmptyState } from "@/components/EmptyState";
import type { Channel, InboundMessage } from "@/lib/types";

interface Props {
  messages: InboundMessage[];
  channels: Channel[] | undefined;
  onRowClick: (msg: InboundMessage) => void;
  onDealClick: (dealId: number) => void;
  /** Состояние загрузки — показывает skeleton строки */
  isLoading?: boolean;
}

function fmtDate(dt: string): { date: string; time: string } {
  try {
    const d = new Date(dt);
    return {
      date: d.toLocaleDateString("ru-RU", { day: "numeric", month: "short" }),
      time: d.toLocaleTimeString("ru-RU", { hour: "2-digit", minute: "2-digit" }),
    };
  } catch {
    return { date: dt, time: "" };
  }
}

function truncate(s: string | null, max = 80): string {
  if (!s) return "—";
  return s.length > max ? s.slice(0, max) + "…" : s;
}

/** Skeleton-заглушка одной строки при загрузке */
function SkeletonRow() {
  return (
    <div className="flex items-center gap-4 px-4 py-3 border-b border-gray-100 dark:border-gray-800 animate-pulse">
      <div className="w-[72px] shrink-0">
        <div className="h-3 bg-gray-100 dark:bg-gray-800 rounded w-full mb-1" />
        <div className="h-3 bg-gray-100 dark:bg-gray-800 rounded w-3/4" />
      </div>
      <div className="w-24 shrink-0">
        <div className="h-5 bg-gray-100 dark:bg-gray-800 rounded-full w-20" />
      </div>
      <div className="w-32 shrink-0">
        <div className="h-3.5 bg-gray-100 dark:bg-gray-800 rounded w-full mb-1" />
        <div className="h-3 bg-gray-100 dark:bg-gray-800 rounded w-2/3" />
      </div>
      <div className="flex-1 min-w-0">
        <div className="h-3.5 bg-gray-100 dark:bg-gray-800 rounded w-1/3 mb-1" />
        <div className="h-3 bg-gray-100 dark:bg-gray-800 rounded w-4/5" />
      </div>
      <div className="w-20 shrink-0 h-5 bg-gray-100 dark:bg-gray-800 rounded" />
    </div>
  );
}

/** Карточка-строка одного сообщения (list-view v2) */
function MessageRow({
  m,
  ch,
  onRowClick,
  onDealClick,
  delay,
}: {
  m: InboundMessage;
  ch: Channel | undefined;
  onRowClick: (msg: InboundMessage) => void;
  onDealClick: (dealId: number) => void;
  delay: number;
}) {
  const { date, time } = fmtDate(m.received_at);
  const display = m.from_name || m.from_identifier || "—";
  const ident = m.from_name && m.from_identifier ? m.from_identifier : null;
  const isUnread = !m.target_deal_id && m.routing_status !== "failed";

  return (
    <BlurFade delay={delay} duration={0.25}>
      <div
        role="button"
        tabIndex={0}
        aria-label={`Сообщение от ${display}`}
        className={[
          "group flex items-start gap-4 px-4 py-3",
          "border-b border-gray-100 dark:border-gray-800 last:border-0",
          "hover:bg-primary/[0.03] dark:hover:bg-primary/[0.06]",
          "transition-colors duration-100 cursor-pointer",
          "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary/40",
        ].join(" ")}
        onClick={() => onRowClick(m)}
        onKeyDown={(e) => {
          if (e.key === "Enter" || e.key === " ") {
            e.preventDefault();
            onRowClick(m);
          }
        }}
      >
        {/* Временная метка */}
        <div className="w-[72px] shrink-0 pt-0.5 text-right">
          <div className="text-xs font-medium text-gray-700 dark:text-gray-300">{date}</div>
          <div className="text-xs text-gray-400 dark:text-gray-500">{time}</div>
        </div>

        {/* Канал */}
        <div className="w-24 shrink-0 pt-0.5">
          {ch ? (
            <KindBadge kind={ch.kind} size="sm" />
          ) : (
            <span className="text-xs text-gray-400">#{m.channel_id}</span>
          )}
        </div>

        {/* Отправитель */}
        <div className="w-36 shrink-0 min-w-0">
          <div className={["text-sm truncate", isUnread ? "font-semibold text-gray-900 dark:text-gray-100" : "font-medium text-gray-700 dark:text-gray-300"].join(" ")}>
            {display}
          </div>
          {ident && (
            <div className="text-xs text-gray-400 font-mono truncate">{ident}</div>
          )}
        </div>

        {/* Содержимое */}
        <div className="flex-1 min-w-0">
          {m.subject && (
            <div className={["text-sm truncate mb-0.5", isUnread ? "font-semibold text-gray-900 dark:text-gray-100" : "font-medium text-gray-800 dark:text-gray-200"].join(" ")}>
              {truncate(m.subject, 80)}
            </div>
          )}
          <div className="text-xs text-gray-500 dark:text-gray-400 line-clamp-1">
            {truncate(m.body, 200)}
          </div>
        </div>

        {/* Привязка к сделке */}
        <div className="shrink-0 flex items-start pt-0.5">
          {m.target_deal_id ? (
            <button
              className="inline-flex items-center gap-1 text-xs font-medium text-primary dark:text-primary-light hover:underline"
              onClick={(e) => {
                e.stopPropagation();
                onDealClick(m.target_deal_id!);
              }}
            >
              <i className="bi bi-kanban" />
              #{m.target_deal_id}
              {m.target_deal_created && (
                <span className="ml-0.5 text-success" title="Сделка создана этим сообщением">
                  <i className="bi bi-patch-check-fill text-[10px]" />
                </span>
              )}
            </button>
          ) : m.routing_status === "failed" ? (
            <span className="badge badge-danger text-xs">
              <i className="bi bi-exclamation-triangle mr-1" />
              не разобрано
            </span>
          ) : (
            <span className="text-xs text-gray-300 dark:text-gray-600">—</span>
          )}
        </div>

        {/* Chevron-hint появляется при hover */}
        <div className="shrink-0 pt-0.5 opacity-0 group-hover:opacity-100 transition-opacity duration-100 text-gray-400">
          <i className="bi bi-chevron-right text-xs" />
        </div>
      </div>
    </BlurFade>
  );
}

/** Табличный список InboundMessage с возможностью drill-in (Modal/Deal). */
export function InboxTable({ messages, channels, onRowClick, onDealClick, isLoading }: Props) {
  const channelById = new Map((channels ?? []).map((c) => [c.id, c]));

  return (
    <div className="card rounded-2xl overflow-hidden shadow-elev-1">
      {/* Заголовок колонок */}
      <div className="flex items-center gap-4 px-4 py-2 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900">
        <div className="w-[72px] shrink-0 text-right">
          <span className="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">Когда</span>
        </div>
        <div className="w-24 shrink-0">
          <span className="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">Канал</span>
        </div>
        <div className="w-36 shrink-0">
          <span className="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">От кого</span>
        </div>
        <div className="flex-1">
          <span className="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">Тема / Текст</span>
        </div>
        <div className="shrink-0">
          <span className="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">Сделка</span>
        </div>
        <div className="w-4 shrink-0" />
      </div>

      {/* Skeleton при загрузке */}
      {isLoading && (
        <div>
          {Array.from({ length: 8 }).map((_, i) => (
            <SkeletonRow key={i} />
          ))}
        </div>
      )}

      {/* Пустое состояние */}
      {!isLoading && messages.length === 0 && (
        <EmptyState
          icon="bi-inbox"
          title="Входящих нет"
          description="Сообщения появятся здесь, как только придут через подключённые каналы"
        />
      )}

      {/* Список сообщений */}
      {!isLoading && messages.length > 0 && (
        <div>
          {messages.map((m, i) => (
            <MessageRow
              key={m.id}
              m={m}
              ch={channelById.get(m.channel_id)}
              onRowClick={onRowClick}
              onDealClick={onDealClick}
              delay={Math.min(i * 0.03, 0.3)}
            />
          ))}
        </div>
      )}
    </div>
  );
}
