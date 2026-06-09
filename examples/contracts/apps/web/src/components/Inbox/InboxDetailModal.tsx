"use client";

import useSWR from "swr";
import { useRouter } from "next/navigation";
import { Modal } from "@/components/Modal";
import { KindBadge } from "@/components/Inbox/KindBadge";
import { fetcher } from "@/lib/api";
import type { Channel, InboundMessage } from "@/lib/types";
import { formatDateTime } from "@/lib/dates";

interface Props {
  messageId: number | null;
  open: boolean;
  onClose: () => void;
}

/** Деталка входящего сообщения: метаданные + body + raw_payload. */
export function InboxDetailModal({ messageId, open, onClose }: Props) {
  const router = useRouter();
  const { data: msg, isLoading } = useSWR<InboundMessage>(
    open && messageId ? `/inbox/${messageId}` : null,
    fetcher,
  );
  // Канал нужен для отображения kind/name. Берём из общего списка (с кешем SWR).
  const { data: channels } = useSWR<Channel[]>(open ? "/channels" : null, fetcher);
  const channel = msg ? channels?.find((c) => c.id === msg.channel_id) : undefined;

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={msg ? `Сообщение #${msg.id}` : "Сообщение"}
      width="lg"
      footer={<button className="btn-secondary" onClick={onClose}>Закрыть</button>}
    >
      {isLoading && <div className="text-gray-500">Загрузка…</div>}
      {!isLoading && !msg && <div className="text-gray-500">Не удалось загрузить</div>}
      {msg && (
        <div className="space-y-5">
          {/* Мета-сетка */}
          <div className="grid grid-cols-2 md:grid-cols-3 gap-3 text-sm p-4 rounded-xl bg-gray-50 dark:bg-gray-800/50 border border-gray-100 dark:border-gray-700/50">
            <Cell label="Канал">
              {channel ? (
                <div className="space-y-1">
                  <KindBadge kind={channel.kind} />
                  <div className="text-xs text-gray-500 dark:text-gray-400">{channel.name}</div>
                </div>
              ) : (
                <span className="text-gray-500">#{msg.channel_id}</span>
              )}
            </Cell>
            <Cell label="Получено">{formatDateTime(msg.received_at)}</Cell>
            <Cell label="External ID">
              <span className="font-mono text-xs text-gray-600 dark:text-gray-300 break-all">{msg.external_id ?? "—"}</span>
            </Cell>
            <Cell label="От (имя)">{msg.from_name ?? "—"}</Cell>
            <Cell label="От (идентификатор)">
              <span className="font-mono text-xs text-gray-600 dark:text-gray-300 break-all">{msg.from_identifier ?? "—"}</span>
            </Cell>
            <Cell label="Сделка">
              {msg.target_deal_id ? (
                <button
                  className="inline-flex items-center gap-1 text-primary dark:text-primary-light hover:underline font-medium"
                  onClick={() => {
                    onClose();
                    router.push(`/deals/${msg.target_deal_id}`);
                  }}
                >
                  <i className="bi bi-kanban" />
                  #{msg.target_deal_id}
                  {msg.target_deal_created && (
                    <span className="text-success">
                      <i className="bi bi-patch-check-fill text-[10px]" />
                    </span>
                  )}
                </button>
              ) : msg.routing_status === "failed" ? (
                <span className="badge badge-danger text-xs">
                  <i className="bi bi-exclamation-triangle mr-1" />не разобрано
                </span>
              ) : (
                <span className="text-xs text-gray-500 dark:text-gray-400">не привязана</span>
              )}
            </Cell>
          </div>

          {msg.subject && (
            <div>
              <div className="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500 mb-1.5">Тема</div>
              <div className="text-sm font-semibold text-gray-900 dark:text-gray-100">{msg.subject}</div>
            </div>
          )}

          <div>
            <div className="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500 mb-1.5">Текст сообщения</div>
            {msg.body ? (
              <div className="text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4 whitespace-pre-wrap leading-relaxed text-gray-700 dark:text-gray-200">
                {msg.body}
              </div>
            ) : (
              <div className="text-sm text-gray-400 dark:text-gray-500 italic">пусто</div>
            )}
          </div>

          <div>
            <div className="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500 mb-1.5">Raw payload</div>
            {msg.raw_payload ? (
              <pre className="text-xs bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4 overflow-x-auto whitespace-pre-wrap text-gray-600 dark:text-gray-300 leading-relaxed">
                {JSON.stringify(msg.raw_payload, null, 2)}
              </pre>
            ) : (
              <div className="text-sm text-gray-400 dark:text-gray-500 italic">пусто</div>
            )}
          </div>
        </div>
      )}
    </Modal>
  );
}

function Cell({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <div className="text-xs font-medium text-gray-400 dark:text-gray-500 mb-0.5 uppercase tracking-wide">{label}</div>
      <div className="text-sm text-gray-700 dark:text-gray-200">{children}</div>
    </div>
  );
}
