"use client";

import { useState } from "react";
import useSWR from "swr";
import { Modal } from "@/components/Modal";
import { fetcher } from "@/lib/api";
import {
  type RightsTransfer,
  type RightsTransferItem,
  TRANSFER_CATEGORY_LABELS,
} from "@/lib/types";
import { formatDateTime } from "@/lib/dates";

interface Props {
  open: boolean;
  transfer: RightsTransfer | null;
  onClose: () => void;
}

const PAGE_SIZE = 20;

export function RightsTransferViewModal({ open, transfer, onClose }: Props) {
  const [page, setPage] = useState(0);

  const { data: items, isLoading } = useSWR<RightsTransferItem[]>(
    open && transfer ? `/admin/rights-transfers/${transfer.id}/items` : null,
    fetcher
  );

  const totalPages = items ? Math.ceil(items.length / PAGE_SIZE) : 0;
  const pageItems = items ? items.slice(page * PAGE_SIZE, (page + 1) * PAGE_SIZE) : [];

  if (!transfer) return null;

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={`Передача прав · ${formatDateTime(transfer.created_at)}`}
      width="lg"
      footer={
        <button className="btn-ghost" onClick={onClose}>Закрыть</button>
      }
    >
      <div className="space-y-5">
        {/* Meta */}
        <div className="grid grid-cols-2 gap-4 text-sm">
          <div>
            <span className="text-gray-500 dark:text-gray-400">От:</span>
            <span className="ml-2 font-medium">{transfer.from_user_name}</span>
          </div>
          <div>
            <span className="text-gray-500 dark:text-gray-400">Кому:</span>
            <span className="ml-2 font-medium">{transfer.to_user_name}</span>
          </div>
          <div>
            <span className="text-gray-500 dark:text-gray-400">Инициатор:</span>
            <span className="ml-2">{transfer.initiated_by_name ?? "—"}</span>
          </div>
          <div>
            <span className="text-gray-500 dark:text-gray-400">Записей:</span>
            <span className="ml-2 font-semibold text-primary">{transfer.items_count}</span>
          </div>
          {transfer.reason && (
            <div className="col-span-2">
              <span className="text-gray-500 dark:text-gray-400">Причина:</span>
              <span className="ml-2">{transfer.reason}</span>
            </div>
          )}
          <div className="col-span-2 flex flex-wrap gap-1">
            <span className="text-gray-500 dark:text-gray-400 mr-1">Категории:</span>
            {transfer.categories.map((cat) => (
              <span key={cat} className="badge bg-info/10 text-info text-xs">
                {TRANSFER_CATEGORY_LABELS[cat]}
              </span>
            ))}
          </div>
        </div>

        {/* Items table */}
        {isLoading && (
          <div className="space-y-2">
            {[1, 2, 3].map((i) => (
              <div key={i} className="animate-pulse h-8 bg-gray-100 dark:bg-gray-700 rounded" />
            ))}
          </div>
        )}

        {!isLoading && items && items.length === 0 && (
          <div className="text-sm text-gray-500 text-center py-6">Нет записей</div>
        )}

        {!isLoading && pageItems.length > 0 && (
          <>
            <div className="card overflow-hidden">
              <table className="w-full text-sm">
                <thead className="bg-gray-50 dark:bg-gray-700/50">
                  <tr>
                    <th className="text-left px-4 py-2 font-semibold text-gray-600 dark:text-gray-400">Тип</th>
                    <th className="text-left px-4 py-2 font-semibold text-gray-600 dark:text-gray-400">Название</th>
                    <th className="text-left px-4 py-2 font-semibold text-gray-600 dark:text-gray-400">Был</th>
                    <th className="text-left px-4 py-2 font-semibold text-gray-600 dark:text-gray-400">Стал</th>
                  </tr>
                </thead>
                <tbody>
                  {pageItems.map((item) => (
                    <tr key={item.id} className="border-t border-gray-100 dark:border-gray-700">
                      <td className="px-4 py-2 text-gray-500">{item.entity_type}</td>
                      <td className="px-4 py-2">{item.entity_name ?? `#${item.entity_id}`}</td>
                      <td className="px-4 py-2 text-gray-500">{item.old_owner_id ?? "—"}</td>
                      <td className="px-4 py-2 text-gray-500">{item.new_owner_id ?? "—"}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {totalPages > 1 && (
              <div className="flex items-center gap-2 justify-center">
                <button
                  className="btn-ghost text-sm"
                  disabled={page === 0}
                  onClick={() => setPage(page - 1)}
                >
                  ← Предыдущая
                </button>
                <span className="text-sm text-gray-500">{page + 1} / {totalPages}</span>
                <button
                  className="btn-ghost text-sm"
                  disabled={page >= totalPages - 1}
                  onClick={() => setPage(page + 1)}
                >
                  Следующая →
                </button>
              </div>
            )}
          </>
        )}
      </div>
    </Modal>
  );
}
