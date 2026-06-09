"use client";

import { useState } from "react";
import useSWR from "swr";
import { fetcher } from "@/lib/api";
import { PageHeader } from "@/components/PageHeader";
import { DataTable, type DataTableColumn } from "@/components/ui/DataTable";
import { TemplateEditModal } from "@/components/Notifications/TemplateEditModal";
import { useToast } from "@/components/ui/Toast";
import type { NotificationTemplate } from "@/lib/types";

const CHANNEL_LABELS: Record<string, string> = {
  in_app: "В приложении",
  tg:     "Telegram",
  email:  "Email",
};

const CHANNEL_BADGE: Record<string, string> = {
  in_app: "bg-info-50    text-info-700    dark:bg-info-500/10    dark:text-info-400",
  tg:     "bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400",
  email:  "bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400",
};

const KIND_LABELS: Record<string, string> = {
  task_assigned:          "Назначена задача",
  task_status_changed:    "Изменён статус задачи",
  task_extend_requested:  "Запрос продления срока",
  deal_won:               "Выиграна сделка",
  deal_stage_changed:     "Изменился этап сделки",
  approval_needed:        "Требуется согласование",
  sla_breach:             "Нарушен SLA",
  course_assigned:        "Назначен курс",
  course_completed:       "Курс завершён",
  contract_signed:        "Подписан договор",
  mention:                "Упоминание",
  system:                 "Системное сообщение",
};

interface TemplatesResponse {
  items: NotificationTemplate[];
  total: number;
}

export default function NotificationTemplatesPage() {
  const [kindFilter, setKindFilter] = useState("");
  const [channelFilter, setChannelFilter] = useState("");
  const [localeFilter, setLocaleFilter] = useState("");
  const [editingTemplate, setEditingTemplate] = useState<NotificationTemplate | null>(null);
  const { toast } = useToast();

  const params = new URLSearchParams();
  if (kindFilter) params.set("kind", kindFilter);
  if (channelFilter) params.set("channel", channelFilter);
  if (localeFilter) params.set("locale", localeFilter);

  const swrKey = `/api/admin/notification-templates?${params.toString()}`;
  const { data, isLoading, error, mutate } = useSWR<TemplatesResponse>(swrKey, fetcher);

  const templates = isLoading ? undefined : (data?.items ?? []);

  function getKindLabel(kind: string): string {
    return KIND_LABELS[kind] ?? kind;
  }

  const columns: DataTableColumn<NotificationTemplate>[] = [
    {
      key: "kind",
      header: "Тип",
      skeletonWidth: "55%",
      render: (t) => (
        <span className="text-gray-800 dark:text-gray-200">
          {getKindLabel(t.kind)}
        </span>
      ),
    },
    {
      key: "channel",
      header: "Канал",
      width: "9rem",
      skeletonWidth: "60%",
      render: (t) => (
        <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${CHANNEL_BADGE[t.channel] ?? "bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300"}`}>
          {CHANNEL_LABELS[t.channel] ?? t.channel}
        </span>
      ),
    },
    {
      key: "locale",
      header: "Язык",
      width: "5rem",
      skeletonWidth: "40%",
      render: (t) => (
        <span className="text-gray-600 dark:text-gray-400 uppercase text-xs font-mono">
          {t.locale}
        </span>
      ),
    },
    {
      key: "subject",
      header: "Заголовок",
      skeletonWidth: "70%",
      render: (t) => (
        <span className="text-gray-600 dark:text-gray-400 truncate block max-w-[240px]">
          {t.subject ? t.subject.slice(0, 60) : (
            <span className="text-gray-400 italic text-xs">без заголовка</span>
          )}
        </span>
      ),
    },
  ];

  return (
    <>
      <PageHeader title="Шаблоны уведомлений" description="тексты push, email и Telegram-уведомлений" />

      <div className="p-8 space-y-4">
        {/* Фильтры */}
        <div className="flex flex-wrap gap-3">
          <select
            className="input text-sm"
            value={kindFilter}
            onChange={(e) => setKindFilter(e.target.value)}
          >
            <option value="">Все типы</option>
            {Object.entries(KIND_LABELS).map(([k, label]) => (
              <option key={k} value={k}>{label}</option>
            ))}
          </select>

          <select
            className="input text-sm"
            value={channelFilter}
            onChange={(e) => setChannelFilter(e.target.value)}
          >
            <option value="">Все каналы</option>
            <option value="in_app">В приложении</option>
            <option value="tg">Telegram</option>
            <option value="email">Email</option>
          </select>

          <select
            className="input text-sm"
            value={localeFilter}
            onChange={(e) => setLocaleFilter(e.target.value)}
          >
            <option value="">Все локали</option>
            <option value="ru">ru</option>
            <option value="en">en</option>
          </select>
        </div>

        <DataTable
          columns={columns}
          rows={templates}
          getRowKey={(t) => t.id}
          onRowClick={setEditingTemplate}
          isError={!!error}
          errorText="Не удалось загрузить шаблоны"
          skeletonRows={6}
          emptyIcon="bi-file-earmark-text"
          emptyTitle="Шаблоны не найдены"
          emptyText="Попробуйте изменить фильтры"
          ariaLabel="Шаблоны уведомлений"
          rowActions={(t) => (
            <button
              type="button"
              onClick={(e) => { e.stopPropagation(); setEditingTemplate(t); }}
              className="p-1.5 rounded hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-400 hover:text-primary transition-colors"
              title="Редактировать"
            >
              <i className="bi bi-pencil text-xs" />
            </button>
          )}
        />
      </div>

      {editingTemplate && (
        <TemplateEditModal
          template={editingTemplate}
          kindLabel={getKindLabel(editingTemplate.kind)}
          onClose={() => setEditingTemplate(null)}
          onSaved={() => {
            setEditingTemplate(null);
            void mutate();
            toast.success("Шаблон обновлён");
          }}
        />
      )}
    </>
  );
}
