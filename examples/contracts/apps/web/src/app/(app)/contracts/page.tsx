"use client";

import Link from "next/link";
import useSWR from "swr";
import { useState } from "react";
import { useSearchParams } from "next/navigation";
import { PageHeader } from "@/components/PageHeader";
import { StatusBadge } from "@/components/StatusBadge";
import { StatusSelect, type StatusSelectValue } from "@/components/StatusSelect";
import { fetcher } from "@/lib/api";
import type { ContractList } from "@/lib/types";
import { ALL_COUNTRIES, ALL_PRODUCTS } from "@/lib/types";

const PRODUCT_OPTS = [{ value: "", label: "Все продукты" }, ...ALL_PRODUCTS];
const COUNTRY_OPTS = [{ value: "", label: "Все страны" }, ...ALL_COUNTRIES];

export default function ContractsPage() {
  const searchParams = useSearchParams();
  const [statusFilter, setStatusFilter] = useState<StatusSelectValue>(() => {
    const group = searchParams.get("status_group");
    const status = searchParams.get("status");
    if (group) return { group };
    if (status) return { status };
    return {};
  });
  const [productCode, setProductCode] = useState("");
  const [countryCode, setCountryCode] = useState("");
  const [q, setQ] = useState("");
  const [showArchived, setShowArchived] = useState(false);

  const params = new URLSearchParams();
  if (statusFilter.group) params.set("status_group", statusFilter.group);
  if (statusFilter.status) params.set("status", statusFilter.status);
  if (productCode) params.set("product_code", productCode);
  if (countryCode) params.set("country_code", countryCode);
  if (q) params.set("q", q);
  if (showArchived) params.set("include_archived", "true");

  const { data, isLoading } = useSWR<ContractList>(`/contracts?${params.toString()}`, fetcher);

  return (
    <>
      <PageHeader
        title="Документы"
        description="Реестр сублицензионных документов"
        actions={
          <Link href="/contracts/new" className="btn-primary">
            <i className="bi bi-plus-lg" /> Новый документ
          </Link>
        }
      />

      <div className="p-8">
        <div className="card p-4 mb-4">
          <div className="grid grid-cols-1 md:grid-cols-4 gap-3">
            <StatusSelect value={statusFilter} onChange={setStatusFilter} />
            <select className="input" value={productCode} onChange={(e) => setProductCode(e.target.value)}>
              {PRODUCT_OPTS.map((p) => (
                <option key={p.value} value={p.value}>{p.label}</option>
              ))}
            </select>
            <select className="input" value={countryCode} onChange={(e) => setCountryCode(e.target.value)}>
              {COUNTRY_OPTS.map((c) => (
                <option key={c.value} value={c.value}>{c.label}</option>
              ))}
            </select>
            <input
              className="input"
              placeholder="Поиск по №, контрагенту…"
              value={q}
              onChange={(e) => setQ(e.target.value)}
            />
          </div>
          <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 cursor-pointer mt-3 select-none">
            <input
              type="checkbox"
              className="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
              checked={showArchived}
              onChange={(e) => setShowArchived(e.target.checked)}
            />
            Показывать архивные
          </label>
        </div>

        <div className="card overflow-hidden">
          <table className="w-full text-sm">
            <thead className="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
              <tr>
                <th className="text-left px-4 py-3 font-semibold">№</th>
                <th className="text-left px-4 py-3 font-semibold">Контрагент</th>
                <th className="text-left px-4 py-3 font-semibold">Продукт</th>
                <th className="text-left px-4 py-3 font-semibold">Страна</th>
                <th className="text-left px-4 py-3 font-semibold">Статус</th>
                <th className="text-left px-4 py-3 font-semibold">Создан</th>
              </tr>
            </thead>
            <tbody>
              {isLoading && (
                <tr>
                  <td colSpan={6} className="text-center py-10 text-gray-500">Загрузка…</td>
                </tr>
              )}
              {!isLoading && data && data.items.length === 0 && (
                <tr>
                  <td colSpan={6} className="text-center py-10 text-gray-500">
                    Документы не найдены.{" "}
                    <Link className="underline text-primary" href="/contracts/new">Создать первый</Link>
                  </td>
                </tr>
              )}
              {data?.items.map((c) => (
                <tr key={c.id} className="border-t border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/50">
                  <td className="px-4 py-3 font-medium">
                    <Link href={`/contracts/${c.id}`} className="link-table">
                      {c.number ?? `Черновик #${c.id}`}
                    </Link>
                  </td>
                  <td className="px-4 py-3">{c.title ?? "—"}</td>
                  <td className="px-4 py-3 uppercase">{c.product_code}</td>
                  <td className="px-4 py-3 uppercase">{c.country_code}</td>
                  <td className="px-4 py-3">
                    <StatusBadge status={c.status} />
                    {c.archived_at && <span className="ml-2 text-xs text-gray-500"><i className="bi bi-archive" /> в архиве</span>}
                  </td>
                  <td className="px-4 py-3 text-gray-600 dark:text-gray-400">
                    {new Date(c.created_at).toLocaleDateString("ru-RU")}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {data && <div className="px-4 py-3 text-xs text-gray-500 border-t border-gray-200 dark:border-gray-700">Всего: {data.total}</div>}
        </div>
      </div>
    </>
  );
}
