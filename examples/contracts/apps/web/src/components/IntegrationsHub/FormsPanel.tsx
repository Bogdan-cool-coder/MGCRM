"use client";

import { useState } from "react";
import useSWR from "swr";
import { FormsTable } from "@/components/Forms/FormsTable";
import { FormModal } from "@/components/Forms/FormModal";
import { fetcher } from "@/lib/api";
import { useMe } from "@/lib/auth";
import type { Channel, CrmForm } from "@/lib/types";

export function FormsPanel() {
  const { user } = useMe();
  const canMutate =
    user?.role === "admin" || user?.role === "lawyer" || user?.role === "director";

  const { data: forms, mutate, isLoading } = useSWR<CrmForm[]>("/forms", fetcher);
  const { data: channels } = useSWR<Channel[]>("/channels", fetcher);

  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState<CrmForm | null>(null);

  function openCreate() {
    setEditing(null);
    setModalOpen(true);
  }

  function openEdit(f: CrmForm) {
    setEditing(f);
    setModalOpen(true);
  }

  return (
    <>
      <div className="flex items-center justify-between mb-6">
        <div>
          <h2 className="text-base font-semibold text-gray-900 dark:text-gray-100">Формы</h2>
          <p className="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
            Публичные веб-формы для приёма заявок
          </p>
        </div>
        {canMutate && (
          <button className="btn-primary" onClick={openCreate}>
            <i className="bi bi-plus-lg" /> Форма
          </button>
        )}
      </div>

      {isLoading && (
        <div className="card rounded-2xl shadow-elev-1 overflow-hidden border border-gray-100 dark:border-gray-800 animate-pulse">
          {[1, 2, 3].map((i) => (
            <div key={i} className="h-14 border-b border-gray-100 dark:border-gray-800 last:border-0 bg-white dark:bg-gray-900" />
          ))}
        </div>
      )}
      {!isLoading && (
        <FormsTable
          forms={forms ?? []}
          channels={channels}
          canMutate={canMutate}
          onEdit={openEdit}
          onChanged={() => mutate()}
        />
      )}

      <FormModal
        open={modalOpen}
        form={editing}
        onClose={() => setModalOpen(false)}
        onSaved={() => mutate()}
      />
    </>
  );
}
