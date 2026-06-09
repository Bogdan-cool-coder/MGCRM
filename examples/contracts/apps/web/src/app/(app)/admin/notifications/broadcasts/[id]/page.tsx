"use client";

import Link from "next/link";
import useSWR from "swr";
import { fetcher } from "@/lib/api";
import { PageHeader } from "@/components/PageHeader";
import type { NotificationBroadcast, BroadcastStatus } from "@/lib/types";
import { formatDateTime } from "@/lib/dates";

const STATUS_LABELS: Record<BroadcastStatus, string> = {
  pending:   "Ожидает",
  running:   "Отправляется",
  completed: "Отправлено",
  failed:    "Ошибка",
};

const STATUS_BADGE: Record<BroadcastStatus, string> = {
  pending:   "bg-info-50    text-info-700    dark:bg-info-500/10    dark:text-info-400",
  running:   "bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400",
  completed: "bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400",
  failed:    "bg-danger-50  text-danger-700  dark:bg-danger-500/10  dark:text-danger-400",
};

// Канонический recipient_filter (см. normalize_recipient_filter): пустой
// объект = «всем», иначе один из ключей role / department_id / user_ids.
function getRecipientsLabel(filter: Record<string, unknown> | null): string {
  if (!filter || Object.keys(filter).length === 0) return "Все активные пользователи";
  if (typeof filter.role === "string" && filter.role) return `По роли: ${filter.role}`;
  if (filter.department_id != null) return `По отделу (ID: ${String(filter.department_id)})`;
  if (Array.isArray(filter.user_ids)) return `Конкретные пользователи (${filter.user_ids.length})`;
  return "—";
}

const CHANNEL_LABELS: Record<string, string> = {
  in_app: "В приложении",
  tg: "Telegram",
  email: "Email",
};

function FieldRow({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="py-3 border-b border-gray-100 dark:border-gray-700/50 last:border-0 grid grid-cols-3 gap-4">
      <dt className="text-sm font-medium text-gray-500 dark:text-gray-400">{label}</dt>
      <dd className="col-span-2 text-sm text-gray-800 dark:text-gray-200">{value}</dd>
    </div>
  );
}

interface PageProps {
  params: { id: string };
}

export default function BroadcastDetailPage({ params }: PageProps) {
  const { data: broadcast, isLoading, error } = useSWR<NotificationBroadcast>(
    `/api/admin/notifications/broadcasts/${params.id}`,
    fetcher,
  );

  if (isLoading) {
    return (
      <>
        <PageHeader title="Рассылка" />
        <div className="p-8 max-w-3xl space-y-3 animate-pulse">
          {[1, 2, 3, 4].map((i) => (
            <div key={i} className="h-14 bg-gray-100 dark:bg-gray-800 rounded-lg" />
          ))}
        </div>
      </>
    );
  }

  if (error || !broadcast) {
    return (
      <>
        <PageHeader title="Рассылка" />
        <div className="p-8 max-w-3xl">
          <div className="card rounded-2xl p-6">
            <p className="text-danger text-sm mb-4">Не удалось загрузить рассылку</p>
            <Link href="/admin/notifications/broadcasts" className="btn-ghost text-sm inline-flex items-center">
              <i className="bi bi-arrow-left mr-1" />
              Назад
            </Link>
          </div>
        </div>
      </>
    );
  }

  const totalRecipients = broadcast.recipients_count ?? 0;
  const deliveryPct =
    totalRecipients > 0
      ? Math.round((broadcast.delivered_count / totalRecipients) * 100)
      : 0;

  const channelList = (broadcast.channels ?? [])
    .map((k) => CHANNEL_LABELS[k] ?? k)
    .join(", ");

  return (
    <>
      <PageHeader
        title={broadcast.title ?? "Рассылка"}
        actions={
          <span className={`inline-flex items-center rounded-full px-3 py-1 text-xs font-medium ${STATUS_BADGE[broadcast.status]}`}>
            {STATUS_LABELS[broadcast.status]}
          </span>
        }
      />

      <div className="p-8 max-w-3xl space-y-6">
        <Link href="/admin/notifications/broadcasts" className="btn-ghost text-sm inline-flex items-center">
          <i className="bi bi-arrow-left mr-1" />
          Назад к рассылкам
        </Link>

        <div className="card rounded-2xl shadow-elev-1 p-6">
          <dl>
            <FieldRow label="Текст" value={broadcast.body} />
            {broadcast.link && (
              <FieldRow
                label="Ссылка"
                value={
                  <a
                    href={broadcast.link}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="text-primary hover:underline"
                  >
                    {broadcast.link}
                  </a>
                }
              />
            )}
            <FieldRow label="Получатели" value={getRecipientsLabel(broadcast.recipient_filter)} />
            <FieldRow label="Каналы" value={channelList || "—"} />
            <FieldRow label="Создано" value={formatDateTime(broadcast.created_at)} />
            <FieldRow label="Завершено" value={formatDateTime(broadcast.completed_at)} />
            <FieldRow
              label="Доставлено"
              value={
                <span className={broadcast.status === "completed" ? "text-success font-medium tabular-nums" : "tabular-nums"}>
                  {broadcast.delivered_count} / {totalRecipients}
                </span>
              }
            />
            {broadcast.failed_count > 0 && (
              <FieldRow
                label="Ошибок"
                value={<span className="text-danger tabular-nums">{broadcast.failed_count}</span>}
              />
            )}
          </dl>
        </div>

        {/* Прогресс-бар для running */}
        {broadcast.status === "running" && totalRecipients > 0 && (
          <div className="card rounded-2xl shadow-elev-1 p-5 space-y-2">
            <div className="flex items-center justify-between text-sm">
              <span className="text-gray-600 dark:text-gray-400 flex items-center gap-1.5">
                <i className="bi bi-arrow-clockwise animate-spin text-warning-500" />
                Отправка…
              </span>
              <span className="font-semibold tabular-nums text-gray-800 dark:text-gray-200">
                {deliveryPct}%
              </span>
            </div>
            <div className="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-1.5 overflow-hidden">
              <div
                className="bg-primary h-full rounded-full transition-all duration-500"
                style={{ width: `${deliveryPct}%` }}
              />
            </div>
            <div className="text-xs text-gray-500 dark:text-gray-400 tabular-nums">
              {broadcast.delivered_count} из {totalRecipients}
            </div>
          </div>
        )}
      </div>
    </>
  );
}
