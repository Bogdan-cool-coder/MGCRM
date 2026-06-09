"use client";

import { useState } from "react";
import useSWR from "swr";
import { PageHeader } from "@/components/PageHeader";
import { RoleGate } from "@/components/RoleGate";
import { api, fetcher } from "@/lib/api";
import type { FinVendorBillDetail } from "@/lib/types";
import { formatDateTime } from "@/lib/dates";
import { MoneyCell } from "@/components/Finance/MoneyCell";
import { VendorBillStatusBadge } from "@/components/Finance/VendorBillStatusBadge";
import { VendorBillFormModal } from "@/components/Finance/VendorBillFormModal";
import { DocPayModal } from "@/components/Finance/DocPayModal";
import { useToast } from "@/components/ui/Toast";

const FINANCE_ROLES = ["accountant", "cfo", "admin"] as const;

interface Props {
  params: { id: string };
}

function DetailSkeleton() {
  return (
    <div className="animate-motion-safe:animate-pulse space-y-6">
      <div className="card p-5 space-y-4">
        <div className="h-4 w-28 bg-gray-100 dark:bg-gray-800 rounded" />
        <div className="grid grid-cols-2 gap-x-6 gap-y-4">
          {Array.from({ length: 6 }).map((_, i) => (
            <div key={i} className="space-y-1.5">
              <div className="h-3 w-16 bg-gray-100 dark:bg-gray-800 rounded" />
              <div className="h-4 w-24 bg-gray-100 dark:bg-gray-800 rounded" />
            </div>
          ))}
        </div>
      </div>
      <div className="card overflow-hidden">
        <div className="px-5 py-4 border-b border-gray-100 dark:border-gray-800">
          <div className="h-4 w-20 bg-gray-100 dark:bg-gray-800 rounded" />
        </div>
        <div className="p-4 space-y-3">
          {Array.from({ length: 3 }).map((_, i) => (
            <div key={i} className="h-8 bg-gray-100 dark:bg-gray-800 rounded" />
          ))}
        </div>
      </div>
    </div>
  );
}

export default function VendorBillPage({ params }: Props) {
  const { id } = params;
  const { toast } = useToast();
  const swrKey = `/api/finance/vendor-bills/${id}`;
  const { data: bill, error, isLoading, mutate: mutateBill } =
    useSWR<FinVendorBillDetail>(swrKey, fetcher);

  const [showEdit, setShowEdit] = useState(false);
  const [showPay, setShowPay] = useState(false);
  const [actioning, setActioning] = useState(false);

  async function doAction(path: string, successMsg: string) {
    setActioning(true);
    try {
      await api(`/api/finance/vendor-bills/${id}/${path}`, { method: "POST" });
      await mutateBill();
      toast.success(successMsg);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Ошибка операции");
    } finally {
      setActioning(false);
    }
  }

  const outstanding = bill
    ? parseFloat(String(bill.amount_gross)) - parseFloat(String(bill.paid_amount))
    : 0;

  return (
    <RoleGate allowed={[...FINANCE_ROLES]}>
      <div className="flex flex-col h-full">
        <PageHeader
          title={
            isLoading
              ? "Загрузка…"
              : bill
              ? `Счёт поставщика ${bill.number ?? `#${bill.id}`}`
              : "Счёт поставщика"
          }
          actions={
            bill ? (
              <div className="flex gap-2 flex-wrap">
                {bill.status === "draft" && (
                  <>
                    <button
                      className="btn-secondary"
                      onClick={() => setShowEdit(true)}
                      disabled={actioning}
                    >
                      <i className="bi bi-pencil mr-1" />
                      Редактировать
                    </button>
                    <button
                      className="btn-primary"
                      onClick={() => {
                        if (confirm("Провести счёт? Будет создана расходная проводка и НДС-вход.")) {
                          doAction("confirm", "Счёт проведён");
                        }
                      }}
                      disabled={actioning}
                    >
                      <i className="bi bi-check2 mr-1" />
                      Провести
                    </button>
                  </>
                )}
                {(bill.status === "confirmed" || bill.status === "partially_paid") &&
                  outstanding > 0 && (
                    <button
                      className="btn-primary"
                      onClick={() => setShowPay(true)}
                      disabled={actioning}
                    >
                      <i className="bi bi-cash-coin mr-1" />
                      Провести оплату
                    </button>
                  )}
                {bill.status !== "cancelled" && bill.status !== "paid" && (
                  <button
                    className="btn-secondary text-danger"
                    onClick={() => {
                      if (confirm("Отменить счёт поставщика?")) doAction("cancel", "Счёт отменён");
                    }}
                    disabled={actioning}
                  >
                    <i className="bi bi-x-circle mr-1" />
                    Отменить
                  </button>
                )}
              </div>
            ) : null
          }
        />

        <div className="p-6 flex-1 overflow-auto">
          {isLoading && <DetailSkeleton />}

          {error && (
            <p className="text-sm text-danger p-4 bg-red-50 dark:bg-red-900/20 rounded">
              Не удалось загрузить счёт поставщика
            </p>
          )}

          {bill && (
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
              <div className="lg:col-span-2 space-y-6">
                {/* Детали */}
                <div className="card p-5">
                  <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-4">
                    Детали счёта
                  </h2>
                  <div className="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div>
                      <span className="text-xs text-gray-400 dark:text-gray-500 uppercase tracking-wide">Статус</span>
                      <div className="mt-1"><VendorBillStatusBadge status={bill.status} /></div>
                    </div>
                    <div>
                      <span className="text-xs text-gray-400 dark:text-gray-500 uppercase tracking-wide">Поставщик</span>
                      <div className="mt-1 font-medium text-gray-800 dark:text-gray-200">
                        Поставщик #{bill.supplier_company_id}
                      </div>
                    </div>
                    <div>
                      <span className="text-xs text-gray-400 dark:text-gray-500 uppercase tracking-wide">№ счёта поставщика</span>
                      <div className="mt-1 text-gray-800 dark:text-gray-200">{bill.bill_no ?? "—"}</div>
                    </div>
                    <div>
                      <span className="text-xs text-gray-400 dark:text-gray-500 uppercase tracking-wide">Дата</span>
                      <div className="mt-1 text-gray-800 dark:text-gray-200 tabular-nums">{bill.bill_date}</div>
                    </div>
                    <div>
                      <span className="text-xs text-gray-400 dark:text-gray-500 uppercase tracking-wide">Срок оплаты</span>
                      <div className="mt-1 text-gray-800 dark:text-gray-200 tabular-nums">{bill.due_date ?? "—"}</div>
                    </div>
                    <div>
                      <span className="text-xs text-gray-400 dark:text-gray-500 uppercase tracking-wide">Валюта</span>
                      <div className="mt-1 text-gray-800 dark:text-gray-200">{bill.currency}</div>
                    </div>
                    {bill.purpose && (
                      <div className="col-span-2">
                        <span className="text-xs text-gray-400 dark:text-gray-500 uppercase tracking-wide">Назначение</span>
                        <div className="mt-1 text-gray-800 dark:text-gray-200">{bill.purpose}</div>
                      </div>
                    )}
                  </div>
                </div>

                {/* Позиции */}
                <div className="card overflow-hidden">
                  <div className="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                    <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">Позиции</h2>
                  </div>
                  <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                      <thead>
                        <tr className="bg-gray-50 dark:bg-gray-800 border-b border-gray-100 dark:border-gray-700">
                          <th className="text-left px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Наименование</th>
                          <th className="text-right px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Кол-во</th>
                          <th className="text-right px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Цена</th>
                          <th className="text-right px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Без НДС</th>
                          <th className="text-right px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">НДС вход</th>
                          <th className="text-right px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Итого</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                        {bill.lines.map((line) => (
                          <tr key={line.id} className="hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                            <td className="px-4 py-2.5 text-gray-700 dark:text-gray-300">{line.name}</td>
                            <td className="px-4 py-2.5 text-right tabular-nums text-gray-600 dark:text-gray-400">
                              {parseFloat(String(line.qty)).toLocaleString("ru-RU")}
                            </td>
                            <td className="px-4 py-2.5 text-right tabular-nums">
                              <MoneyCell amount={line.unit_price} currency={bill.currency} />
                            </td>
                            <td className="px-4 py-2.5 text-right tabular-nums text-gray-600 dark:text-gray-400">
                              <MoneyCell amount={line.amount_net} currency={bill.currency} />
                            </td>
                            <td className="px-4 py-2.5 text-right tabular-nums text-xs text-gray-500">
                              <MoneyCell amount={line.vat_amount} currency={bill.currency} />
                            </td>
                            <td className="px-4 py-2.5 text-right tabular-nums font-medium">
                              <MoneyCell amount={line.amount_gross} currency={bill.currency} />
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>

                  <div className="px-4 py-3 border-t border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 flex flex-col items-end gap-1.5 text-sm">
                    <div className="flex gap-8">
                      <span className="text-gray-500 dark:text-gray-400">Без НДС:</span>
                      <span className="tabular-nums font-medium w-32 text-right">
                        <MoneyCell amount={bill.amount_net} currency={bill.currency} />
                      </span>
                    </div>
                    <div className="flex gap-8">
                      <span className="text-gray-500 dark:text-gray-400">НДС вход:</span>
                      <span className="tabular-nums font-medium w-32 text-right">
                        <MoneyCell amount={bill.vat_amount} currency={bill.currency} />
                      </span>
                    </div>
                    <div className="flex gap-8 pt-2 border-t border-gray-200 dark:border-gray-600 mt-1">
                      <span className="font-semibold text-gray-700 dark:text-gray-200">Итого:</span>
                      <span className="tabular-nums font-bold w-32 text-right">
                        <MoneyCell amount={bill.amount_gross} currency={bill.currency} />
                      </span>
                    </div>
                    {outstanding > 0 && (
                      <div className="flex gap-8">
                        <span className="text-danger font-medium">Остаток к оплате:</span>
                        <span className="tabular-nums font-bold w-32 text-right text-danger">
                          <MoneyCell amount={outstanding} currency={bill.currency} />
                        </span>
                      </div>
                    )}
                  </div>
                </div>
              </div>

              {/* Правая панель */}
              <div className="space-y-4">
                <div className="card p-4 text-xs text-gray-500 dark:text-gray-400 space-y-1">
                  <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500 mb-2">
                    Технические данные
                  </h3>
                  <div>ID: {bill.id}</div>
                  <div>Создан: {formatDateTime(bill.created_at)}</div>
                  {bill.confirmed_at && (
                    <div>Проведён: {formatDateTime(bill.confirmed_at)}</div>
                  )}
                  {bill.journal_entry_id && <div>Проводка: #{bill.journal_entry_id}</div>}
                </div>
              </div>
            </div>
          )}
        </div>
      </div>

      {showEdit && bill && (
        <VendorBillFormModal
          bill={bill}
          onClose={() => setShowEdit(false)}
          onSuccess={() => { mutateBill(); toast.success("Счёт обновлён"); }}
        />
      )}

      {showPay && bill && (
        <DocPayModal
          apiBase={`/api/finance/vendor-bills/${id}`}
          currency={bill.currency}
          outstanding={outstanding}
          onClose={() => setShowPay(false)}
          onSuccess={() => { mutateBill(); toast.success("Оплата проведена"); }}
        />
      )}
    </RoleGate>
  );
}
