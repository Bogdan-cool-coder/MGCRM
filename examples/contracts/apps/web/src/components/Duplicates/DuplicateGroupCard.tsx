"use client";

import { useState } from "react";
import { api } from "@/lib/api";
import { type DuplicateGroup } from "@/lib/types";
import { DuplicateSimilarityBadge } from "./DuplicateSimilarityBadge";
import { MergeModal } from "./MergeModal";

interface DuplicateGroupCardProps {
  group: DuplicateGroup;
  index: number;
  total: number;
  onMerged: () => void;
  onNotDuplicate: () => void;
}

const FIELD_LABELS: Record<string, string> = {
  name: "Название",
  full_name: "Полное имя",
  email: "Email",
  phone: "Телефон",
  tax_id: "ИНН",
  address: "Адрес",
};

const SHOWN_FIELDS = ["name", "full_name", "email", "phone", "tax_id", "address"];

export function DuplicateGroupCard({
  group, index, total, onMerged, onNotDuplicate,
}: DuplicateGroupCardProps) {
  const [mergeOpen, setMergeOpen] = useState(false);
  const [dismissing, setDismissing] = useState(false);

  async function handleDismiss() {
    if (group.records.length < 2) return;
    setDismissing(true);
    try {
      await api("/duplicates/dismiss", {
        method: "POST",
        body: {
          entity_type: group.entity,
          entity_a_id: group.records[0].id,
          entity_b_id: group.records[1].id,
        },
      });
      onNotDuplicate();
    } catch {
      // silent
    } finally {
      setDismissing(false);
    }
  }

  const shownFields = SHOWN_FIELDS.filter((f) =>
    group.records.some((r) => r.fields[f] != null),
  );

  return (
    <div className="card p-4">
      <div className="flex items-center justify-between mb-3">
        <span className="text-sm text-gray-500">
          Группа {index} из {total}
        </span>
        <DuplicateSimilarityBadge score={group.similarity_score} />
      </div>

      {/* Records side by side */}
      <div className="flex flex-col sm:flex-row gap-3 mb-4">
        {group.records.map((record, i) => (
          <div key={record.id} className="flex-1 bg-gray-50 rounded-lg p-3 min-w-0">
            <div className="text-sm font-semibold text-gray-900 mb-2 truncate">
              {record.display_name}
              <span className="ml-2 text-xs text-gray-400 font-normal">#{record.id}</span>
              {i === 0 && (
                <span className="ml-1 text-xs bg-primary/10 text-primary px-1.5 rounded font-normal">
                  оригинал
                </span>
              )}
            </div>
            <div className="space-y-0.5">
              {shownFields.map((f) => {
                const val = record.fields[f];
                if (val == null) return null;
                return (
                  <div key={f} className="text-xs text-gray-600">
                    <span className="text-gray-400">{FIELD_LABELS[f] ?? f}: </span>
                    {val}
                  </div>
                );
              })}
            </div>
          </div>
        ))}
      </div>

      {/* Actions */}
      <div className="flex items-center gap-2">
        <button
          onClick={() => setMergeOpen(true)}
          className="btn-primary text-sm"
        >
          <i className="bi bi-merge mr-1" />
          Объединить
        </button>
        <button
          onClick={handleDismiss}
          className="btn-ghost text-sm"
          disabled={dismissing}
        >
          {dismissing ? "…" : "Не дубль"}
        </button>
      </div>

      <MergeModal
        open={mergeOpen}
        group={group}
        onClose={() => setMergeOpen(false)}
        onMerged={() => { setMergeOpen(false); onMerged(); }}
      />
    </div>
  );
}
