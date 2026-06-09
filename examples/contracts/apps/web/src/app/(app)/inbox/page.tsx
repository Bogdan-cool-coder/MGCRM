"use client";

import { useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import useSWR from "swr";
import { PageHeader } from "@/components/PageHeader";
import { InboxTable } from "@/components/Inbox/InboxTable";
import { InboxFilters, type InboxFiltersState } from "@/components/Inbox/InboxFilters";
import { InboxDetailModal } from "@/components/Inbox/InboxDetailModal";
import { fetcher } from "@/lib/api";
import type { Channel, InboundMessage } from "@/lib/types";

const PAGE_SIZE = 50;

export default function InboxPage() {
  const router = useRouter();

  const [filters, setFilters] = useState<InboxFiltersState>({
    channel_id: "",
    has_deal: "",
    q: "",
  });
  // Накопительный массив страниц (offset += PAGE_SIZE), сбрасывается при изменении фильтров.
  const [pages, setPages] = useState<InboundMessage[][]>([]);
  const [offset, setOffset] = useState(0);
  const [selectedId, setSelectedId] = useState<number | null>(null);

  const queryString = useMemo(() => {
    const params = new URLSearchParams();
    if (filters.channel_id) params.set("channel_id", filters.channel_id);
    if (filters.has_deal) params.set("has_deal", filters.has_deal);
    if (filters.q.trim()) params.set("q", filters.q.trim());
    params.set("limit", String(PAGE_SIZE));
    params.set("offset", String(offset));
    return `?${params.toString()}`;
  }, [filters, offset]);

  const { data: messages, isLoading, mutate } = useSWR<InboundMessage[]>(
    `/inbox${queryString}`,
    fetcher,
    {
      onSuccess: (data) => {
        // Если offset===0 — это новая выборка (фильтры изменились), сбрасываем
        // накопленный массив и кладём первую страницу.
        if (offset === 0) {
          setPages([data]);
        } else {
          // Идемпотентность: одна и та же страница не должна дублироваться при
          // повторном render'е. Сравниваем по offset через индекс страницы.
          const pageIndex = offset / PAGE_SIZE;
          setPages((prev) => {
            const next = prev.slice(0, pageIndex);
            next[pageIndex] = data;
            return next;
          });
        }
      },
    },
  );

  const { data: channels } = useSWR<Channel[]>("/channels", fetcher);

  function applyFilters(next: InboxFiltersState) {
    setFilters(next);
    setOffset(0);
    setPages([]);
  }

  // Плоский список накопленных страниц
  const allMessages = pages.flat();
  const lastPage = messages ?? [];
  const hasMore = lastPage.length === PAGE_SIZE;

  return (
    <>
      <PageHeader
        title="Входящие"
        description="Сообщения из каналов (TG, WhatsApp, Email, веб-формы, API)."
        actions={
          <button
            className="btn-secondary text-sm"
            onClick={() => {
              setOffset(0);
              setPages([]);
              void mutate();
            }}
            disabled={isLoading}
          >
            <i className="bi bi-arrow-clockwise" /> Обновить
          </button>
        }
      />

      <div className="p-8 space-y-4">
        <InboxFilters channels={channels} filters={filters} onChange={applyFilters} />

        <InboxTable
          messages={allMessages}
          channels={channels}
          onRowClick={(m) => setSelectedId(m.id)}
          onDealClick={(dealId) => router.push(`/deals/${dealId}`)}
          isLoading={isLoading && offset === 0}
        />

        <div className="flex items-center justify-between gap-2 text-sm">
          <div className="text-gray-600">
            Показано {allMessages.length}
            {allMessages.length > 0 && hasMore && " (есть ещё)"}
          </div>
          {hasMore && (
            <button
              className="btn-secondary"
              disabled={isLoading}
              onClick={() => setOffset((o) => o + PAGE_SIZE)}
            >
              {isLoading ? "Загрузка…" : "Показать ещё"} <i className="bi bi-chevron-down" />
            </button>
          )}
        </div>
      </div>

      <InboxDetailModal
        messageId={selectedId}
        open={selectedId !== null}
        onClose={() => setSelectedId(null)}
      />
    </>
  );
}
