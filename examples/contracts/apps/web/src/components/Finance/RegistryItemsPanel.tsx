"use client";

import { useState } from "react";
import useSWR, { mutate } from "swr";
import { Modal } from "@/components/Modal";
import { MoneyCell } from "@/components/Finance/MoneyCell";
import { OperationStatusBadge } from "@/components/Finance/OperationStatusBadge";
import { api, fetcher } from "@/lib/api";
import type { FinRegistryDetail, FinOperationListResponse, FinOperation } from "@/lib/types";

interface Props {
  registryId: number;
  registry: FinRegistryDetail;
}

function OperationPickerModal({
  open,
  registryId,
  sourceAccountId,
  existingIds,
  onClose,
}: {
  open: boolean;
  registryId: number;
  sourceAccountId: number;
  existingIds: Set<number>;
  onClose: () => void;
}) {
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState("");

  const swrKey = open
    ? `/api/finance/operations?direction=out&account_from_id=${sourceAccountId}`
    : null;
  const { data, isLoading } = useSWR<FinOperationListResponse>(swrKey, fetcher);

  const available = (data?.items ?? []).filter(
    (op) => !existingIds.has(op.id)
  );

  function toggle(id: number) {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  function handleClose() {
    setSelected(new Set());
    setError("");
    onClose();
  }

  async function handleAdd() {
    if (selected.size === 0) return;
    setError("");
    setSubmitting(true);
    try {
      await api(`/api/finance/registries/${registryId}/items`, {
        method: "POST",
        body: { operation_ids: Array.from(selected) },
      });
      await mutate(`/api/finance/registries/${registryId}`);
      handleClose();
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Не удалось добавить операции");
    } finally {
      setSubmitting(false);
    }
  }

  const STATUS_LABELS: Record<string, string> = {
    to_pay: "К оплате",
    planned: "Запланировано",
    posted: "Проведено",
    on_hold: "Заморожено",
  };

  return (
    <Modal
      open={open}
      title="Выбрать операции"
      onClose={handleClose}
      width="lg"
      footer={
        <>
          <button type="button" className="btn-ghost" onClick={handleClose}>Отмена</button>
          <button
            type="button"
            className="btn-primary"
            disabled={submitting || selected.size === 0}
            onClick={handleAdd}
          >
            {submitting ? "Добавление..." : `Добавить выбранные (${selected.size})`}
          </button>
        </>
      }
    >
      {error && (
        <div className="text-danger text-sm p-3 bg-red-50 dark:bg-red-900/20 rounded mb-3">
          {error}
        </div>
      )}
      {isLoading && (
        <div className="animate-pulse space-y-2">
          {[1, 2, 3].map((i) => (
            <div key={i} className="h-8 bg-gray-100 dark:bg-gray-700 rounded" />
          ))}
        </div>
      )}
      {!isLoading && available.length === 0 && (
        <p className="text-sm text-gray-400 dark:text-gray-500 text-center py-6">
          Нет доступных операций для добавления
        </p>
      )}
      {!isLoading && available.length > 0 && (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-gray-200 dark:border-gray-700">
                <th className="py-2 px-3 text-left font-medium text-gray-500 dark:text-gray-400 w-8">
                  <span className="sr-only">Выбрать</span>
                </th>
                <th className="py-2 px-3 text-left font-medium text-gray-500 dark:text-gray-400">Операция</th>
                <th className="py-2 px-3 text-left font-medium text-gray-500 dark:text-gray-400">Назначение</th>
                <th className="py-2 px-3 text-right font-medium text-gray-500 dark:text-gray-400">Сумма</th>
                <th className="py-2 px-3 text-left font-medium text-gray-500 dark:text-gray-400">Статус</th>
              </tr>
            </thead>
            <tbody>
              {available.map((op) => (
                <tr
                  key={op.id}
                  className={`border-b border-gray-100 dark:border-gray-700 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 ${
                    selected.has(op.id) ? "bg-primary-light/10 dark:bg-primary/10" : ""
                  }`}
                  onClick={() => toggle(op.id)}
                >
                  <td className="py-2 px-3">
                    <input
                      type="checkbox"
                      checked={selected.has(op.id)}
                      onChange={() => toggle(op.id)}
                      className="w-4 h-4 rounded"
                      onClick={(e) => e.stopPropagation()}
                    />
                  </td>
                  <td className="py-2 px-3 text-gray-600 dark:text-gray-300">
                    {op.number ?? `#${op.id}`}
                  </td>
                  <td className="py-2 px-3 text-gray-700 dark:text-gray-200 max-w-[200px] truncate">
                    {op.purpose ?? "—"}
                  </td>
                  <td className="py-2 px-3 text-right">
                    <MoneyCell amount={op.amount} currency={op.currency} direction="out" />
                  </td>
                  <td className="py-2 px-3">
                    <span className={`text-xs px-2 py-0.5 rounded-full ${
                      op.status === "to_pay"
                        ? "bg-yellow-50 text-yellow-700 dark:bg-yellow-900/20 dark:text-yellow-400"
                        : "bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400"
                    }`}>
                      {STATUS_LABELS[op.status] ?? op.status}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Modal>
  );
}

export function RegistryItemsPanel({ registryId, registry }: Props) {
  const [pickerOpen, setPickerOpen] = useState(false);
  const [deletingId, setDeletingId] = useState<number | null>(null);

  const isDraft = registry.approval_status === "draft";
  const items: FinOperation[] = registry.items ?? [];
  const existingIds = new Set(items.map((op) => op.id));

  async function handleRemove(opId: number) {
    if (!confirm("Убрать операцию из реестра?")) return;
    setDeletingId(opId);
    try {
      await api(`/api/finance/registries/${registryId}/items/${opId}`, { method: "DELETE" });
      await mutate(`/api/finance/registries/${registryId}`);
    } catch {
      // noop
    } finally {
      setDeletingId(null);
    }
  }

  return (
    <div className="card overflow-hidden">
      <div className="flex items-center justify-between px-5 py-4 border-b border-gray-200 dark:border-gray-700">
        <h2 className="text-base font-semibold dark:text-gray-100">Позиции реестра</h2>
        {isDraft && (
          <button
            type="button"
            className="btn-secondary text-sm"
            onClick={() => setPickerOpen(true)}
          >
            <i className="bi bi-plus-lg mr-1" />
            Добавить операции
          </button>
        )}
      </div>

      {!isDraft && (
        <div className="px-5 py-2 text-sm text-gray-400 dark:text-gray-500 italic bg-gray-50 dark:bg-gray-900 border-b border-gray-100 dark:border-gray-700">
          Состав заморожен — реестр подан на согласование
        </div>
      )}

      {items.length === 0 ? (
        <div className="px-5 py-8 text-center text-gray-400 dark:text-gray-500">
          <i className="bi bi-inbox text-3xl block mb-2" />
          <p className="text-sm">Позиций пока нет</p>
          {isDraft && (
            <button
              type="button"
              className="btn-primary text-sm mt-3"
              onClick={() => setPickerOpen(true)}
            >
              Добавить операции
            </button>
          )}
        </div>
      ) : (
        <>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900">
                  <th className="py-3 px-4 text-left font-medium text-gray-500 dark:text-gray-400">Операция</th>
                  <th className="py-3 px-4 text-left font-medium text-gray-500 dark:text-gray-400">Назначение</th>
                  <th className="py-3 px-4 text-right font-medium text-gray-500 dark:text-gray-400">Сумма</th>
                  <th className="py-3 px-4 text-left font-medium text-gray-500 dark:text-gray-400">Статус</th>
                  {isDraft && <th className="py-3 px-4 w-12" />}
                </tr>
              </thead>
              <tbody>
                {items.map((op) => (
                  <tr key={op.id} className="border-b border-gray-100 dark:border-gray-700">
                    <td className="py-3 px-4 text-gray-600 dark:text-gray-300">
                      {op.number ?? `#${op.id}`}
                    </td>
                    <td className="py-3 px-4 text-gray-700 dark:text-gray-200 max-w-[240px] truncate">
                      {op.purpose ?? "—"}
                    </td>
                    <td className="py-3 px-4 text-right">
                      <MoneyCell amount={op.amount} currency={op.currency} direction="out" />
                    </td>
                    <td className="py-3 px-4">
                      <OperationStatusBadge status={op.status} />
                    </td>
                    {isDraft && (
                      <td className="py-3 px-4 text-right">
                        <button
                          type="button"
                          className="btn-ghost text-sm text-danger"
                          disabled={deletingId === op.id}
                          onClick={() => handleRemove(op.id)}
                          title="Убрать из реестра"
                        >
                          <i className="bi bi-x-lg" />
                        </button>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Total footer */}
          <div className="px-5 py-3 bg-gray-50 dark:bg-gray-900 border-t border-gray-200 dark:border-gray-700 flex justify-end">
            <span className="text-sm font-semibold tabular-nums dark:text-gray-100">
              Итого:{" "}
              <MoneyCell
                amount={registry.total_amount}
                currency={
                  (items[0]?.currency) ?? ""
                }
                direction="out"
              />
            </span>
          </div>
        </>
      )}

      <OperationPickerModal
        open={pickerOpen}
        registryId={registryId}
        sourceAccountId={registry.source_account_id}
        existingIds={existingIds}
        onClose={() => setPickerOpen(false)}
      />
    </div>
  );
}
