"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import useSWR from "swr";
import { fetcher } from "@/lib/api";
import { PageHeader } from "@/components/PageHeader";
import { DataTable, type DataTableColumn } from "@/components/ui/DataTable";
import type { BroadcastListResponse, NotificationBroadcast, BroadcastStatus } from "@/lib/types";
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
  if (!filter || Object.keys(filter).length === 0) return "Все";
  if (typeof filter.role === "string" && filter.role) return `Роль: ${filter.role}`;
  if (filter.department_id != null) return "Отдел";
  if (Array.isArray(filter.user_ids)) return `${filter.user_ids.length} польз.`;
  return "—";
}

const CHANNEL_CHIP: Record<string, { label: string; cls: string }> = {
  in_app: { label: "In-App", cls: "bg-info-50 text-info-700 dark:bg-info-500/10 dark:text-info-400" },
  tg: { label: "TG", cls: "bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400" },
  email: { label: "Email", cls: "bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400" },
};

function ChannelChips({ channels }: { channels: string[] | null }) {
  const list = channels ?? [];
  return (
    <div className="flex flex-wrap gap-1">
      {list.map((ch) => {
        const chip = CHANNEL_CHIP[ch];
        return (
          <span
            key={ch}
            className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${chip?.cls ?? "bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300"}`}
          >
            {chip?.label ?? ch}
          </span>
        );
      })}
    </div>
  );
}

export default function BroadcastsPage() {
  const router = useRouter();
  const { data, isLoading, error } = useSWR<BroadcastListResponse>(
    "/api/admin/notifications/broadcasts?limit=50&offset=0",
    fetcher,
  );

  // Эндпоинт отдаёт массив BroadcastOut напрямую (без {items}).
  const items = data;

  const columns: DataTableColumn<NotificationBroadcast>[] = [
    {
      key: "title",
      header: "Рассылка",
      skeletonWidth: "55%",
      render: (item) => (
        <div>
          <div className="font-medium text-gray-800 dark:text-gray-200 truncate max-w-[280px]">
            {item.title}
          </div>
          {item.body && (
            <div className="text-xs text-gray-500 dark:text-gray-400 mt-0.5 truncate max-w-[280px]">
              {item.body.slice(0, 60)}
            </div>
          )}
        </div>
      ),
    },
    {
      key: "recipients",
      header: "Получатели",
      width: "9rem",
      skeletonWidth: "50%",
      render: (item) => (
        <span className="text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">
          {getRecipientsLabel(item.recipient_filter)}
        </span>
      ),
    },
    {
      key: "channels",
      header: "Каналы",
      width: "8rem",
      skeletonWidth: "70%",
      render: (item) => <ChannelChips channels={item.channels} />,
    },
    {
      key: "created_at",
      header: "Когда",
      width: "10rem",
      skeletonWidth: "70%",
      render: (item) => (
        <span className="text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap tabular-nums">
          {formatDateTime(item.created_at)}
        </span>
      ),
    },
    {
      key: "status",
      header: "Статус",
      width: "8rem",
      skeletonWidth: "60%",
      render: (item) => (
        <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${STATUS_BADGE[item.status]}`}>
          {STATUS_LABELS[item.status]}
        </span>
      ),
    },
    {
      key: "delivered_count",
      header: "Доставлено",
      width: "7rem",
      align: "right",
      skeletonWidth: "50%",
      render: (item) => {
        const total = item.recipients_count ?? 0;
        if (item.status === "completed") {
          return (
            <span className="tabular-nums text-success font-medium">
              {item.delivered_count} / {total}
            </span>
          );
        }
        if (total > 0) {
          return (
            <span className="tabular-nums text-gray-500 dark:text-gray-400">
              {item.delivered_count} / {total}
            </span>
          );
        }
        return <span className="text-gray-400">—</span>;
      },
    },
  ];

  return (
    <>
      <PageHeader
        title="История рассылок"
        actions={
          <Link href="/admin/notifications/broadcast" className="btn-primary">
            <i className="bi bi-plus-lg mr-1" />
            Создать рассылку
          </Link>
        }
      />

      <div className="p-8 space-y-4">
        <DataTable
          columns={columns}
          rows={isLoading ? undefined : (items ?? [])}
          getRowKey={(item) => item.id}
          onRowClick={(item) => router.push(`/admin/notifications/broadcasts/${item.id}`)}
          isError={!!error}
          errorText="Не удалось загрузить рассылки"
          skeletonRows={5}
          emptyIcon="bi-megaphone"
          emptyTitle="Рассылок пока не было"
          emptyText="Создай первую, чтобы уведомить всю команду"
          emptyCta={
            <Link href="/admin/notifications/broadcast" className="btn-primary text-sm mt-1">
              Создать первую
            </Link>
          }
          ariaLabel="История рассылок"
        />
      </div>
    </>
  );
}
