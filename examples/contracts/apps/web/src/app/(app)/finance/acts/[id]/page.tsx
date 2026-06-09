"use client";

import { useState } from "react";
import Link from "next/link";
import useSWR from "swr";
import { PageHeader } from "@/components/PageHeader";
import { RoleGate } from "@/components/RoleGate";
import { api, fetcher } from "@/lib/api";
import type { FinActDetail, User } from "@/lib/types";
import { formatDateTime } from "@/lib/dates";
import { MoneyCell } from "@/components/Finance/MoneyCell";
import { ActStatusBadge } from "@/components/Finance/ActStatusBadge";
import { ActFormModal } from "@/components/Finance/ActFormModal";
import { useToast } from "@/components/ui/Toast";

const FINANCE_ROLES = ["accountant", "cfo", "admin"] as const;

interface Props {
  params: { id: string };
}

function DetailSkeleton() {
  return (
    <div className="animate-motion-safe:animate-pulse space-y-6">
      <div className="card p-5 space-y-4">
        <div className="h-4 w-24 bg-gray-100 dark:bg-gray-800 rounded" />
        <div className="grid grid-cols-2 gap-x-6 gap-y-4">
          {Array.from({ length: 4 }).map((_, i) => (
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

export default function ActPage({ params }: Props) {
  const { id } = params;
  const { toast } = useToast();
  const swrKey = `/api/finance/acts/${id}`;
  const { data: act, error, isLoading, mutate: mutateAct } =
    useSWR<FinActDetail>(swrKey, fetcher);
  const { data: users } = useSWR<User[]>(
    act?.signed_by_user_id != null ? "/api/users" : null,
    fetcher,
  );

  const signerName =
    act?.signed_by_user_id != null
      ? users?.find((u) => u.id === act.signed_by_user_id)?.full_name ??
        `Пользователь #${act.signed_by_user_id}`
      : "—";

  const [showEdit, setShowEdit] = useState(false);
  const [actioning, setActioning] = useState(false);

  async function doAction(path: string, successMsg: string) {
    setActioning(true);
    try {
      await api(`/api/finance/acts/${id}/${path}`, { method: "POST" });
      await mutateAct();
      toast.success(successMsg);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Ошибка операции");
    } finally {
      setActioning(false);
    }
  }

  return (
    <RoleGate allowed={[...FINANCE_ROLES]}>
      <div className="flex flex-col h-full">
        <PageHeader
          title={
            isLoading ? "Загрузка…" : act ? `Акт ${act.number ?? `#${act.id}`}` : "Акт"
          }
          actions={
            act ? (
              <div className="flex gap-2 flex-wrap">
                {act.status === "draft" && (
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
                      onClick={() => doAction("issue", "Акт выставлен")}
                      disabled={actioning}
                    >
                      <i className="bi bi-send mr-1" />
                      Выставить
                    </button>
                  </>
                )}
                {act.status === "issued" && (
                  <button
                    className="btn-primary"
                    onClick={() => {
                      if (confirm("Подписать акт? После подписи документ нельзя перегенерировать.")) {
                        doAction("sign", "Акт подписан");
                      }
                    }}
                    disabled={actioning}
                  >
                    <i className="bi bi-pen mr-1" />
                    Подписать
                  </button>
                )}
                {act.status !== "cancelled" && act.status !== "signed" && (
                  <button
                    className="btn-secondary text-danger"
                    onClick={() => {
                      if (confirm("Отменить акт?")) doAction("cancel", "Акт отменён");
                    }}
                    disabled={actioning}
                  >
                    <i className="bi bi-x-circle mr-1" />
                    Отменить
                  </button>
                )}
                {act.signed_at === null && (
                  <button
                    className="btn-secondary"
                    onClick={() => doAction("generate", "Документ сформирован")}
                    disabled={actioning}
                    title="Сформировать DOCX + PDF документа"
                  >
                    <i className="bi bi-file-earmark-pdf mr-1" />
                    {act.document_file_id ? "Перегенерировать" : "Сгенерировать документ"}
                  </button>
                )}
                {act.document_file_id !== null && (
                  <>
                    <a
                      className="btn-secondary"
                      href={`/api/finance/acts/${id}/document?fmt=pdf`}
                      target="_blank"
                      rel="noreferrer"
                    >
                      <i className="bi bi-file-earmark-pdf mr-1" />
                      PDF
                    </a>
                    <a
                      className="btn-secondary"
                      href={`/api/finance/acts/${id}/document?fmt=docx`}
                      target="_blank"
                      rel="noreferrer"
                    >
                      <i className="bi bi-file-earmark-word mr-1" />
                      DOCX
                    </a>
                  </>
                )}
              </div>
            ) : null
          }
        />

        <div className="p-6 flex-1 overflow-auto">
          {isLoading && <DetailSkeleton />}

          {error && (
            <p className="text-sm text-danger p-4 bg-red-50 dark:bg-red-900/20 rounded">
              Не удалось загрузить акт
            </p>
          )}

          {act && (
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
              <div className="lg:col-span-2 space-y-6">
                {/* Инфо-баннер */}
                <div className="flex items-start gap-2 px-3 py-2.5 rounded-lg bg-blue-50 dark:bg-blue-900/15 border border-blue-100 dark:border-blue-800/40 text-sm">
                  <i className="bi bi-info-circle text-info mt-0.5 shrink-0" />
                  <span className="text-gray-700 dark:text-gray-300">
                    Акт — документ подтверждения. Финансовую проводку не создаёт.
                  </span>
                </div>

                {/* Детали */}
                <div className="card p-5">
                  <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-4">
                    Детали акта
                  </h2>
                  <div className="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div>
                      <span className="text-xs text-gray-400 dark:text-gray-500 uppercase tracking-wide">Статус</span>
                      <div className="mt-1"><ActStatusBadge status={act.status} /></div>
                    </div>
                    <div>
                      <span className="text-xs text-gray-400 dark:text-gray-500 uppercase tracking-wide">Контрагент</span>
                      <div className="mt-1">
                        <Link
                          href={`/contacts?company_id=${act.counterparty_company_id}`}
                          className="text-primary hover:underline font-medium"
                        >
                          Контрагент #{act.counterparty_company_id}
                        </Link>
                      </div>
                    </div>
                    <div>
                      <span className="text-xs text-gray-400 dark:text-gray-500 uppercase tracking-wide">Дата акта</span>
                      <div className="mt-1 text-gray-800 dark:text-gray-200 tabular-nums">{act.act_date}</div>
                    </div>
                    <div>
                      <span className="text-xs text-gray-400 dark:text-gray-500 uppercase tracking-wide">Валюта</span>
                      <div className="mt-1 text-gray-800 dark:text-gray-200">{act.currency}</div>
                    </div>
                    {act.invoice_id && (
                      <div>
                        <span className="text-xs text-gray-400 dark:text-gray-500 uppercase tracking-wide">Инвойс</span>
                        <div className="mt-1">
                          <Link href={`/finance/invoices/${act.invoice_id}`} className="text-primary hover:underline font-mono text-xs">
                            #{act.invoice_id}
                          </Link>
                        </div>
                      </div>
                    )}
                    {act.purpose && (
                      <div className="col-span-2">
                        <span className="text-xs text-gray-400 dark:text-gray-500 uppercase tracking-wide">Назначение</span>
                        <div className="mt-1 text-gray-800 dark:text-gray-200">{act.purpose}</div>
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
                          <th className="text-right px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">НДС</th>
                          <th className="text-right px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Итого</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                        {act.lines.map((line) => (
                          <tr key={line.id} className="hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                            <td className="px-4 py-2.5 text-gray-700 dark:text-gray-300">{line.name}</td>
                            <td className="px-4 py-2.5 text-right tabular-nums text-gray-600 dark:text-gray-400">
                              {parseFloat(String(line.qty)).toLocaleString("ru-RU")}
                            </td>
                            <td className="px-4 py-2.5 text-right tabular-nums">
                              <MoneyCell amount={line.unit_price} currency={act.currency} />
                            </td>
                            <td className="px-4 py-2.5 text-right tabular-nums text-gray-600 dark:text-gray-400">
                              <MoneyCell amount={line.amount_net} currency={act.currency} />
                            </td>
                            <td className="px-4 py-2.5 text-right tabular-nums text-xs text-gray-500">
                              <MoneyCell amount={line.vat_amount} currency={act.currency} />
                            </td>
                            <td className="px-4 py-2.5 text-right tabular-nums font-medium">
                              <MoneyCell amount={line.amount_gross} currency={act.currency} />
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
                        <MoneyCell amount={act.amount_net} currency={act.currency} />
                      </span>
                    </div>
                    <div className="flex gap-8">
                      <span className="text-gray-500 dark:text-gray-400">НДС:</span>
                      <span className="tabular-nums font-medium w-32 text-right">
                        <MoneyCell amount={act.vat_amount} currency={act.currency} />
                      </span>
                    </div>
                    <div className="flex gap-8 pt-2 border-t border-gray-200 dark:border-gray-600 mt-1">
                      <span className="font-semibold text-gray-700 dark:text-gray-200">Итого:</span>
                      <span className="tabular-nums font-bold w-32 text-right">
                        <MoneyCell amount={act.amount_gross} currency={act.currency} />
                      </span>
                    </div>
                  </div>
                </div>
              </div>

              {/* Правая панель */}
              <div className="space-y-4">
                {/* Документ */}
                <div className="card p-4 space-y-3 text-sm">
                  <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Документ</h3>
                  {act.document_file_id === null ? (
                    <p className="text-gray-400 dark:text-gray-500 text-xs italic">Документ ещё не сформирован</p>
                  ) : (
                    <div className="space-y-2">
                      {act.signed_at !== null ? (
                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-success/10 text-success">
                          <i className="bi bi-pen" />
                          Подписан, версия {act.document_file_id}
                        </span>
                      ) : (
                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-info/10 text-info">
                          <i className="bi bi-file-earmark-check" />
                          Сформирован, версия {act.document_file_id}
                        </span>
                      )}
                      {act.signed_at !== null && (
                        <div className="text-xs text-gray-500 dark:text-gray-400">
                          Подписал: {signerName} · {formatDateTime(act.signed_at)}
                        </div>
                      )}
                      <div className="flex gap-3 pt-1">
                        <a
                          className="text-primary hover:underline text-xs inline-flex items-center gap-1"
                          href={`/api/finance/acts/${id}/document?fmt=pdf`}
                          target="_blank"
                          rel="noreferrer"
                        >
                          <i className="bi bi-file-earmark-pdf" /> PDF
                        </a>
                        <a
                          className="text-primary hover:underline text-xs inline-flex items-center gap-1"
                          href={`/api/finance/acts/${id}/document?fmt=docx`}
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
                  <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500 mb-2">Технические данные</h3>
                  <div>ID: {act.id}</div>
                  <div>Создан: {formatDateTime(act.created_at)}</div>
                  {act.signed_at && (
                    <div>Подписан: {formatDateTime(act.signed_at)}</div>
                  )}
                </div>
              </div>
            </div>
          )}
        </div>
      </div>

      {showEdit && act && (
        <ActFormModal
          act={act}
          onClose={() => setShowEdit(false)}
          onSuccess={() => { mutateAct(); toast.success("Акт обновлён"); }}
        />
      )}
    </RoleGate>
  );
}
