"use client";

import { useState } from "react";
import Link from "next/link";
import useSWR, { mutate } from "swr";
import { PageHeader } from "@/components/PageHeader";
import { RoleGate } from "@/components/RoleGate";
import { api, fetcher } from "@/lib/api";
import type { FinInvoiceDetail, User } from "@/lib/types";
import { formatDateTime } from "@/lib/dates";
import { MoneyCell } from "@/components/Finance/MoneyCell";
import { InvoiceStatusBadge } from "@/components/Finance/InvoiceStatusBadge";
import { InvoiceFormModal } from "@/components/Finance/InvoiceFormModal";
import { DocPayModal } from "@/components/Finance/DocPayModal";
import { useToast } from "@/components/ui/Toast";

const FINANCE_ROLES = ["accountant", "cfo", "admin"] as const;

interface Props {
  params: { id: string };
}

function DetailSkeleton() {
  return (
    <div className="animate-motion-safe:animate-pulse space-y-6">
      {/* Hero card */}
      <div className="card p-5 space-y-4">
        <div className="h-4 w-32 bg-gray-100 dark:bg-gray-800 rounded" />
        <div className="grid grid-cols-2 gap-x-6 gap-y-4">
          {Array.from({ length: 6 }).map((_, i) => (
            <div key={i} className="space-y-1.5">
              <div className="h-3 w-16 bg-gray-100 dark:bg-gray-800 rounded" />
              <div className="h-4 w-24 bg-gray-100 dark:bg-gray-800 rounded" />
            </div>
          ))}
        </div>
      </div>
      {/* Lines card */}
      <div className="card overflow-hidden">
        <div className="px-5 py-4 border-b border-gray-100 dark:border-gray-800">
          <div className="h-4 w-24 bg-gray-100 dark:bg-gray-800 rounded" />
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

export default function InvoicePage({ params }: Props) {
  const { id } = params;
  const { toast } = useToast();
  const swrKey = `/api/finance/invoices/${id}`;
  const { data: inv, error, isLoading, mutate: mutateInv } = useSWR<FinInvoiceDetail>(swrKey, fetcher);
  const { data: users } = useSWR<User[]>(
    inv?.signed_by_user_id != null ? "/api/users" : null,
    fetcher,
  );

  const signerName =
    inv?.signed_by_user_id != null
      ? users?.find((u) => u.id === inv.signed_by_user_id)?.full_name ??
        `Пользователь #${inv.signed_by_user_id}`
      : "—";

  const [showEdit, setShowEdit] = useState(false);
  const [showPay, setShowPay] = useState(false);
  const [actioning, setActioning] = useState(false);

  async function doAction(path: string, successMsg: string) {
    setActioning(true);
    try {
      await api(`/api/finance/invoices/${id}/${path}`, { method: "POST" });
      await mutateInv();
      toast.success(successMsg);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Ошибка операции");
    } finally {
      setActioning(false);
    }
  }

  const outstanding = inv
    ? parseFloat(String(inv.amount_gross)) - parseFloat(String(inv.paid_amount))
    : 0;

  return (
    <RoleGate allowed={[...FINANCE_ROLES]}>
      <div className="flex flex-col h-full">
        <PageHeader
          title={
            isLoading ? "Загрузка…" : inv ? `Инвойс ${inv.number ?? `#${inv.id}`}` : "Инвойс"
          }
          actions={
            inv ? (
              <div className="flex gap-2 flex-wrap">
                {inv.status === "draft" && (
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
                      onClick={() => doAction("issue", "Инвойс выставлен")}
                      disabled={actioning}
                    >
                      <i className="bi bi-send mr-1" />
                      Выставить
                    </button>
                  </>
                )}
                {(inv.status === "issued" || inv.status === "partially_paid") && outstanding > 0 && (
                  <button
                    className="btn-primary"
                    onClick={() => setShowPay(true)}
                    disabled={actioning}
                  >
                    <i className="bi bi-cash-coin mr-1" />
                    Провести оплату
                  </button>
                )}
                {inv.status !== "cancelled" && inv.status !== "paid" && (
                  <button
                    className="btn-secondary text-danger"
                    onClick={() => {
                      if (confirm("Отменить инвойс? Это действие нельзя отменить.")) {
                        doAction("cancel", "Инвойс отменён");
                      }
                    }}
                    disabled={actioning}
                  >
                    <i className="bi bi-x-circle mr-1" />
                    Отменить
                  </button>
                )}
                {inv.signed_at === null && (
                  <button
                    className="btn-secondary"
                    onClick={() => doAction("generate", "Документ сформирован")}
                    disabled={actioning}
                    title="Сформировать DOCX + PDF документа"
                  >
                    <i className="bi bi-file-earmark-pdf mr-1" />
                    {inv.document_file_id ? "Перегенерировать" : "Сгенерировать документ"}
                  </button>
                )}
                {inv.document_file_id !== null && (
                  <>
                    <a
                      className="btn-secondary"
                      href={`/api/finance/invoices/${id}/document?fmt=pdf`}
                      target="_blank"
                      rel="noreferrer"
                    >
                      <i className="bi bi-file-earmark-pdf mr-1" />
                      PDF
                    </a>
                    <a
                      className="btn-secondary"
                      href={`/api/finance/invoices/${id}/document?fmt=docx`}
                      target="_blank"
                      rel="noreferrer"
                    >
                      <i className="bi bi-file-earmark-word mr-1" />
                      DOCX
                    </a>
                  </>
                )}
                {(inv.status === "issued" ||
                  inv.status === "partially_paid" ||
                  inv.status === "paid") &&
                  inv.signed_at === null && (
                    <button
                      className="btn-primary"
                      onClick={() => {
                        if (confirm("Подписать счёт? После подписи документ нельзя перегенерировать.")) {
                          doAction("sign", "Инвойс подписан");
                        }
                      }}
                      disabled={actioning}
                      title="Подписать счёт (перегенерирует PDF с блоком подписи)"
                    >
                      <i className="bi bi-pen mr-1" />
                      Подписать
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
              Не удалось загрузить инвойс
            </p>
          )}

          {inv && (
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
              {/* Детали + позиции */}
              <div className="lg:col-span-2 space-y-6">
                <div className="card p-5">
                  <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-4">
                    Детали инвойса
                  </h2>
                  <div className="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div>
                      <span className="text-xs text-gray-400 dark:text-gray-500 uppercase tracking-wide">Статус</span>
                      <div className="mt-1">
                        <InvoiceStatusBadge status={inv.status} />
                      </div>
                    </div>
                    <div>
                      <span className="text-xs text-gray-400 dark:text-gray-500 uppercase tracking-wide">Контрагент</span>
                      <div className="mt-1">
                        <Link
                          href={`/contacts?company_id=${inv.counterparty_company_id}`}
                          className="text-primary hover:underline font-medium"
                        >
                          Контрагент #{inv.counterparty_company_id}
                        </Link>
                      </div>
                    </div>
                    <div>
                      <span className="text-xs text-gray-400 dark:text-gray-500 uppercase tracking-wide">Дата выставления</span>
                      <div className="mt-1 text-gray-800 dark:text-gray-200 tabular-nums">{inv.issue_date}</div>
                    </div>
                    <div>
                      <span className="text-xs text-gray-400 dark:text-gray-500 uppercase tracking-wide">Срок оплаты</span>
                      <div className="mt-1 text-gray-800 dark:text-gray-200 tabular-nums">
                        {inv.due_date ?? "—"}
                      </div>
                    </div>
                    <div>
                      <span className="text-xs text-gray-400 dark:text-gray-500 uppercase tracking-wide">Валюта</span>
                      <div className="mt-1 text-gray-800 dark:text-gray-200">{inv.currency}</div>
                    </div>
                    <div>
                      <span className="text-xs text-gray-400 dark:text-gray-500 uppercase tracking-wide">Назначение</span>
                      <div className="mt-1 text-gray-800 dark:text-gray-200">
                        {inv.purpose ?? "—"}
                      </div>
                    </div>
                  </div>
                </div>

                {/* Позиции */}
                <div className="card overflow-hidden">
                  <div className="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                    <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">
                      Позиции
                    </h2>
                  </div>
                  <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                      <thead>
                        <tr className="bg-gray-50 dark:bg-gray-800 border-b border-gray-100 dark:border-gray-700">
                          <th className="text-left px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Наименование</th>
                          <th className="text-right px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Кол-во</th>
                          <th className="text-right px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Цена</th>
                          <th className="text-right px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Без НДС</th>
                          <th className="text-right px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">НДС</th>
                          <th className="text-right px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Итого</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                        {inv.lines.map((line) => (
                          <tr key={line.id} className="hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                            <td className="px-4 py-2.5 text-gray-700 dark:text-gray-300">
                              {line.name}
                            </td>
                            <td className="px-4 py-2.5 text-right text-gray-600 dark:text-gray-400 tabular-nums">
                              {parseFloat(String(line.qty)).toLocaleString("ru-RU")}
                            </td>
                            <td className="px-4 py-2.5 text-right tabular-nums">
                              <MoneyCell amount={line.unit_price} currency={inv.currency} />
                            </td>
                            <td className="px-4 py-2.5 text-right tabular-nums text-gray-600 dark:text-gray-400">
                              <MoneyCell amount={line.amount_net} currency={inv.currency} />
                            </td>
                            <td className="px-4 py-2.5 text-right tabular-nums text-gray-500 text-xs">
                              <MoneyCell amount={line.vat_amount} currency={inv.currency} />
                            </td>
                            <td className="px-4 py-2.5 text-right tabular-nums font-medium">
                              <MoneyCell amount={line.amount_gross} currency={inv.currency} />
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>

                  {/* Итоги */}
                  <div className="px-4 py-3 border-t border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 flex flex-col items-end gap-1.5 text-sm">
                    <div className="flex gap-8">
                      <span className="text-gray-500 dark:text-gray-400">Без НДС:</span>
                      <span className="tabular-nums font-medium w-32 text-right">
                        <MoneyCell amount={inv.amount_net} currency={inv.currency} />
                      </span>
                    </div>
                    <div className="flex gap-8">
                      <span className="text-gray-500 dark:text-gray-400">НДС:</span>
                      <span className="tabular-nums font-medium w-32 text-right">
                        <MoneyCell amount={inv.vat_amount} currency={inv.currency} />
                      </span>
                    </div>
                    <div className="flex gap-8 pt-2 border-t border-gray-200 dark:border-gray-600 mt-1">
                      <span className="font-semibold text-gray-700 dark:text-gray-200">
                        Итого к оплате:
                      </span>
                      <span className="tabular-nums font-bold text-gray-900 dark:text-gray-100 w-32 text-right">
                        <MoneyCell amount={inv.amount_gross} currency={inv.currency} />
                      </span>
                    </div>
                    <div className="flex gap-8">
                      <span className="text-gray-500 dark:text-gray-400">Оплачено:</span>
                      <span className="tabular-nums font-medium w-32 text-right text-success">
                        <MoneyCell amount={inv.paid_amount} currency={inv.currency} positive />
                      </span>
                    </div>
                    {outstanding > 0 && (
                      <div className="flex gap-8">
                        <span className="text-danger font-medium">Остаток:</span>
                        <span className="tabular-nums font-bold w-32 text-right text-danger">
                          <MoneyCell amount={outstanding} currency={inv.currency} />
                        </span>
                      </div>
                    )}
                  </div>
                </div>
              </div>

              {/* Правая панель */}
              <div className="space-y-4">
                {/* Связи */}
                <div className="card p-4 space-y-2 text-sm">
                  <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500 mb-2">
                    Связи
                  </h3>
                  {inv.deal_id && (
                    <div className="flex items-center gap-2">
                      <i className="bi bi-kanban text-primary" />
                      <Link href={`/deals/${inv.deal_id}`} className="text-primary hover:underline">
                        Сделка #{inv.deal_id}
                      </Link>
                    </div>
                  )}
                  {inv.contract_id && (
                    <div className="flex items-center gap-2">
                      <i className="bi bi-file-earmark-text text-primary" />
                      <Link href={`/contracts/${inv.contract_id}`} className="text-primary hover:underline">
                        Договор #{inv.contract_id}
                      </Link>
                    </div>
                  )}
                  {!inv.deal_id && !inv.contract_id && (
                    <p className="text-gray-400 dark:text-gray-500 text-xs italic">Нет связанных объектов</p>
                  )}
                </div>

                {/* Документ */}
                <div className="card p-4 space-y-3 text-sm">
                  <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Документ</h3>
                  {inv.document_file_id === null ? (
                    <p className="text-gray-400 dark:text-gray-500 text-xs italic">
                      Документ ещё не сформирован
                    </p>
                  ) : (
                    <div className="space-y-2">
                      {inv.signed_at !== null ? (
                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-success/10 text-success">
                          <i className="bi bi-pen" />
                          Подписан, версия {inv.document_file_id}
                        </span>
                      ) : (
                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-info/10 text-info">
                          <i className="bi bi-file-earmark-check" />
                          Сформирован, версия {inv.document_file_id}
                        </span>
                      )}
                      {inv.signed_at !== null && (
                        <div className="text-xs text-gray-500 dark:text-gray-400">
                          Подписал: {signerName} ·{" "}
                          {formatDateTime(inv.signed_at)}
                        </div>
                      )}
                      <div className="flex gap-3 pt-1">
                        <a
                          className="text-primary hover:underline text-xs inline-flex items-center gap-1"
                          href={`/api/finance/invoices/${id}/document?fmt=pdf`}
                          target="_blank"
                          rel="noreferrer"
                        >
                          <i className="bi bi-file-earmark-pdf" /> PDF
                        </a>
                        <a
                          className="text-primary hover:underline text-xs inline-flex items-center gap-1"
                          href={`/api/finance/invoices/${id}/document?fmt=docx`}
                          target="_blank"
                          rel="noreferrer"
                        >
                          <i className="bi bi-file-earmark-word" /> DOCX
                        </a>
                      </div>
                    </div>
                  )}
                </div>

                {/* Технические данные */}
                <div className="card p-4 text-xs text-gray-500 dark:text-gray-400 space-y-1">
                  <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500 mb-2">
                    Технические данные
                  </h3>
                  <div>ID: {inv.id}</div>
                  <div>Создан: {formatDateTime(inv.created_at)}</div>
                  {inv.issued_at && (
                    <div>Выставлен: {formatDateTime(inv.issued_at)}</div>
                  )}
                  {inv.journal_entry_id && (
                    <div>Проводка: #{inv.journal_entry_id}</div>
                  )}
                </div>
              </div>
            </div>
          )}
        </div>
      </div>

      {showEdit && inv && (
        <InvoiceFormModal
          invoice={inv}
          onClose={() => setShowEdit(false)}
          onSuccess={() => { mutateInv(); toast.success("Инвойс обновлён"); }}
        />
      )}

      {showPay && inv && (
        <DocPayModal
          apiBase={`/api/finance/invoices/${id}`}
          currency={inv.currency}
          outstanding={outstanding}
          onClose={() => setShowPay(false)}
          onSuccess={() => { mutateInv(); toast.success("Оплата проведена"); }}
        />
      )}
    </RoleGate>
  );
}
