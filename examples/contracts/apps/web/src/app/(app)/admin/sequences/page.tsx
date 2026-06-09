"use client";

import { useState } from "react";
import { PageHeader } from "@/components/PageHeader";
import { SequenceList } from "@/components/Sequences/SequenceList";
import { SequenceFormModal } from "@/components/Sequences/SequenceFormModal";

export default function SequencesPage() {
  const [createOpen, setCreateOpen] = useState(false);

  return (
    <>
      <PageHeader
        title="Последовательности"
        description="Цепочки шагов (email, Telegram, задачи) с задержками. Запускаются вручную или автоматизацией."
        actions={
          <button className="btn-primary" onClick={() => setCreateOpen(true)}>
            <i className="bi bi-plus-lg mr-1" /> Создать
          </button>
        }
      />

      <div className="px-8 py-6">
        <SequenceList />
      </div>

      <SequenceFormModal
        open={createOpen}
        onClose={() => setCreateOpen(false)}
      />
    </>
  );
}
