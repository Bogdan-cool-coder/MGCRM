"use client";

import { useState } from "react";
import Link from "next/link";
import useSWR from "swr";
import { PageHeader } from "@/components/PageHeader";
import { CallsTable } from "@/components/Integrations/Calldown/CallsTable";
import { CallDetailModal } from "@/components/Integrations/Calldown/CallDetailModal";
import { DatePicker } from "@/components/ui/DatePicker";
import { fetcher } from "@/lib/api";
import type { CalldownCall, CalldownCallsResponse, User } from "@/lib/types";

export const dynamic = "force-dynamic";

const PAGE_SIZE = 20;

export default function CalldownCallsPage() {
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");
  const [direction, setDirection] = useState("");
  const [userId, setUserId] = useState("");
  const [page, setPage] = useState(1);
  const [selectedCall, setSelectedCall] = useState<CalldownCall | null>(null);

  const { data: users } = useSWR<User[]>("/users", fetcher);

  const params = new URLSearchParams({
    limit: String(PAGE_SIZE),
    offset: String((page - 1) * PAGE_SIZE),
  });
  if (dateFrom) params.set("from", dateFrom);
  if (dateTo) params.set("to", dateTo);
  if (direction) params.set("direction", direction);
  if (userId) params.set("owner_id", userId);

  const swrKey = `/integrations/calldown/calls?${params.toString()}`;
  const { data, error, isLoading, mutate } = useSWR<CalldownCallsResponse>(swrKey, fetcher);

  const calls = data?.items ?? [];
  const total = data?.total ?? 0;
  const totalPages = Math.ceil(total / PAGE_SIZE);

  function handleFilterChange() {
    setPage(1);
  }

  return (
    <>
      <PageHeader
        title="Журнал звонков"
        description=""
        actions={
          <Link href="/admin/integrations/calldown" className="btn-secondary text-sm">
            <i className="bi bi-gear mr-1" />
            Настройка
          </Link>
        }
      />
      <div className="p-8 space-y-5">
        {/* Filters */}
        <div className="flex flex-wrap gap-3 items-end">
          <div>
            <label className="label text-xs mb-1">Дата от</label>
            <DatePicker
              value={dateFrom || null}
              onChange={(v) => { setDateFrom(v ?? ""); handleFilterChange(); }}
              placeholder="Дата от"
            />
          </div>
          <div>
            <label className="label text-xs mb-1">Дата до</label>
            <DatePicker
              value={dateTo || null}
              onChange={(v) => { setDateTo(v ?? ""); handleFilterChange(); }}
              placeholder="Дата до"
            />
          </div>
          <div>
            <label className="label text-xs mb-1">Направление</label>
            <select
              className="input w-36"
              value={direction}
              onChange={(e) => { setDirection(e.target.value); handleFilterChange(); }}
            >
              <option value="">Все</option>
              <option value="in">Входящие</option>
              <option value="out">Исходящие</option>
            </select>
          </div>
          <div>
            <label className="label text-xs mb-1">Менеджер</label>
            <select
              className="input w-44"
              value={userId}
              onChange={(e) => { setUserId(e.target.value); handleFilterChange(); }}
            >
              <option value="">Все менеджеры</option>
              {users?.map((u) => (
                <option key={u.id} value={String(u.id)}>{u.full_name}</option>
              ))}
            </select>
          </div>
          {(dateFrom || dateTo || direction || userId) && (
            <button
              className="btn-ghost text-sm"
              onClick={() => {
                setDateFrom("");
                setDateTo("");
                setDirection("");
                setUserId("");
                setPage(1);
              }}
            >
              <i className="bi bi-x-circle mr-1" />
              Сбросить
            </button>
          )}
        </div>

        {/* Error */}
        {error && (
          <div className="rounded-md bg-danger/10 text-danger px-4 py-3 text-sm">
            Не удалось загрузить журнал звонков
          </div>
        )}

        {/* Table */}
        <CallsTable
          calls={calls}
          isLoading={isLoading}
          onCallClick={(c) => setSelectedCall(c)}
        />

        {/* Pagination */}
        {totalPages > 1 && (
          <div className="flex items-center justify-center gap-1 mt-4">
            <button
              className="btn-ghost text-sm"
              disabled={page <= 1}
              onClick={() => setPage((p) => p - 1)}
            >
              <i className="bi bi-chevron-left" /> Предыдущая
            </button>
            {Array.from({ length: Math.min(totalPages, 7) }, (_, i) => i + 1).map((p) => (
              <button
                key={p}
                onClick={() => setPage(p)}
                className={`w-9 h-9 rounded text-sm ${p === page ? "bg-primary text-white" : "btn-ghost"}`}
              >
                {p}
              </button>
            ))}
            <button
              className="btn-ghost text-sm"
              disabled={page >= totalPages}
              onClick={() => setPage((p) => p + 1)}
            >
              Следующая <i className="bi bi-chevron-right" />
            </button>
          </div>
        )}
      </div>

      <CallDetailModal
        open={selectedCall !== null}
        call={selectedCall}
        onClose={() => setSelectedCall(null)}
        onChanged={() => { setSelectedCall(null); void mutate(); }}
      />
    </>
  );
}
