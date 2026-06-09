"use client";

import { useState } from "react";
import useSWR from "swr";
import { ChannelsTable } from "@/components/Channels/ChannelsTable";
import { ChannelModal } from "@/components/Channels/ChannelModal";
import { fetcher } from "@/lib/api";
import { useMe } from "@/lib/auth";
import type { Channel } from "@/lib/types";

export function ChannelsPanel() {
  const { user } = useMe();
  const canMutate = user?.role === "admin" || user?.role === "director";

  const { data: channels, mutate, isLoading } = useSWR<Channel[]>("/channels", fetcher);

  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState<Channel | null>(null);

  function openCreate() {
    setEditing(null);
    setModalOpen(true);
  }

  function openEdit(ch: Channel) {
    setEditing(ch);
    setModalOpen(true);
  }

  return (
    <>
      <div className="flex items-center justify-between mb-6">
        <div>
          <h2 className="text-base font-semibold text-gray-900 dark:text-gray-100">Каналы</h2>
          <p className="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
            Точки приёма входящих сообщений: TG, WhatsApp, Email, веб-формы, API
          </p>
        </div>
        {canMutate && (
          <button className="btn-primary" onClick={openCreate}>
            <i className="bi bi-plus-lg mr-1" /> Добавить канал
          </button>
        )}
      </div>

      {isLoading && (
        <div className="card rounded-2xl shadow-elev-1 overflow-hidden border border-gray-100 dark:border-gray-800">
          <div className="space-y-0 animate-pulse">
            {[1, 2, 3].map((i) => (
              <div key={i} className="h-14 border-b border-gray-100 dark:border-gray-800 last:border-0 bg-white dark:bg-gray-900" />
            ))}
          </div>
        </div>
      )}
      {!isLoading && (
        <ChannelsTable
          channels={channels ?? []}
          isAdmin={canMutate}
          onEdit={openEdit}
          onChanged={() => mutate()}
        />
      )}

      <ChannelModal
        open={modalOpen}
        channel={editing}
        onClose={() => setModalOpen(false)}
        onSaved={() => mutate()}
      />
    </>
  );
}
