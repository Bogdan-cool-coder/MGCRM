"use client";

import { useState, useEffect } from "react";
import { Modal } from "@/components/Modal";
import { api, ApiError } from "@/lib/api";
import { type DuplicateGroup, type DuplicateRecord } from "@/lib/types";
import { MultiMergeFlow } from "./MultiMergeFlow";

interface MergeModalProps {
  open: boolean;
  group: DuplicateGroup;
  onClose: () => void;
  onMerged: () => void;
}

type Selection = "a" | "b";

function buildInitialSelections(
  fieldNames: string[],
  recordA: DuplicateRecord,
  recordB: DuplicateRecord,
): Record<string, Selection> {
  const sel: Record<string, Selection> = {};
  for (const f of fieldNames) {
    sel[f] = recordA.fields[f] != null ? "a" : "b";
  }
  return sel;
}

function collectFieldNames(records: DuplicateRecord[]): string[] {
  const keys = new Set<string>();
  for (const r of records) {
    for (const k of Object.keys(r.fields)) {
      keys.add(k);
    }
  }
  return Array.from(keys);
}

const FIELD_LABELS: Record<string, string> = {
  name: "Название",
  full_name: "Полное имя",
  email: "Email",
  phone: "Телефон",
  tax_id: "ИНН",
  address: "Адрес",
  notes: "Примечания",
  website: "Сайт",
  country: "Страна",
  city: "Город",
};

function fieldLabel(k: string): string {
  return FIELD_LABELS[k] ?? k;
}

export function MergeModal({ open, group, onClose, onMerged }: MergeModalProps) {
  const [recordA, recordB] = group.records;
  const fieldNames = collectFieldNames(group.records);

  const [selections, setSelections] = useState<Record<string, Selection>>({});
  const [merging, setMerging] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (open && recordA && recordB) {
      setSelections(buildInitialSelections(fieldNames, recordA, recordB));
      setError(null);
    }
  }, [open, group]);

  // Handle 3+ records — multi-merge flow
  if (group.records.length >= 3) {
    return (
      <Modal
        open={open}
        title={`Объединение ${group.records.length} дублей`}
        onClose={onClose}
        width="md"
      >
        <MultiMergeFlow group={group} onClose={onClose} onMerged={onMerged} />
      </Modal>
    );
  }

  if (!recordA || !recordB) return null;

  async function handleMerge() {
    setError(null);
    setMerging(true);

    // field_choices: {field: "primary"|"secondary"}
    // recordA is primary (index 0), recordB is secondary (index 1)
    const field_choices: Record<string, string> = {};
    for (const f of fieldNames) {
      field_choices[f] = selections[f] === "a" ? "primary" : "secondary";
    }

    try {
      await api("/duplicates/merge", {
        method: "POST",
        body: {
          entity_type: group.entity,
          primary_id: recordA.id,
          secondary_id: recordB.id,
          field_choices,
        },
      });
      onMerged();
      onClose();
    } catch (err) {
      if (err instanceof ApiError) {
        const d = (err.detail as { detail?: string })?.detail;
        setError(typeof d === "string" ? d : "Не удалось объединить. Попробуй снова.");
      } else {
        setError("Не удалось объединить. Попробуй снова.");
      }
    } finally {
      setMerging(false);
    }
  }

  // Compute preview (real-time based on selections)
  const preview: Record<string, string | null> = {};
  for (const f of fieldNames) {
    const src = selections[f] === "a" ? recordA : recordB;
    preview[f] = src.fields[f];
  }

  return (
    <Modal
      open={open}
      title="Объединить записи"
      description="Выбери, какие данные сохранить в итоговой записи"
      onClose={onClose}
      width="xl"
      footer={
        <>
          <button onClick={onClose} className="btn-ghost" disabled={merging}>Отмена</button>
          <button onClick={handleMerge} className="btn-primary" disabled={merging}>
            {merging ? "Объединяем…" : "Объединить →"}
          </button>
        </>
      }
    >
      <div className="space-y-6">
        {error && (
          <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">{error}</div>
        )}

        {/* Fields selection table */}
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-gray-200">
                <th className="text-left py-2 px-3 text-xs text-gray-500 font-medium w-1/4">Поле</th>
                <th className="py-2 px-3 text-xs text-gray-500 font-medium w-[37.5%] text-left">
                  Запись A <span className="text-gray-400 font-normal">(оригинал)</span>
                </th>
                <th className="py-2 px-3 text-xs text-gray-500 font-medium w-[37.5%] text-left">
                  Запись B <span className="text-gray-400 font-normal">(дубль)</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {fieldNames.map((f) => {
                const valA = recordA.fields[f];
                const valB = recordB.fields[f];
                const same = valA === valB;
                return (
                  <tr key={f} className="border-b border-gray-100">
                    <td className="py-2.5 px-3 text-gray-500">{fieldLabel(f)}</td>
                    <td className="py-2.5 px-3">
                      <label className="flex items-start gap-2 cursor-pointer">
                        <input
                          type="radio"
                          name={`field-${f}`}
                          checked={selections[f] === "a"}
                          onChange={() => setSelections((s) => ({ ...s, [f]: "a" }))}
                          className="mt-0.5 accent-primary"
                        />
                        <span className={valA ? "" : "text-gray-400 italic"}>
                          {valA ?? "—"}
                          {same && valA != null && (
                            <span className="ml-1 text-xs text-gray-400">(совпадают)</span>
                          )}
                        </span>
                      </label>
                    </td>
                    <td className="py-2.5 px-3">
                      <label className="flex items-start gap-2 cursor-pointer">
                        <input
                          type="radio"
                          name={`field-${f}`}
                          checked={selections[f] === "b"}
                          onChange={() => setSelections((s) => ({ ...s, [f]: "b" }))}
                          className="mt-0.5 accent-primary"
                        />
                        <span className={valB ? "" : "text-gray-400 italic"}>
                          {valB ?? "—"}
                        </span>
                      </label>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>

        {/* Preview */}
        <div className="card bg-gray-50 p-4">
          <div className="text-xs uppercase tracking-wide text-gray-500 font-semibold mb-2">
            Итоговая запись
          </div>
          <div className="space-y-1">
            {fieldNames.map((f) => (
              <div key={f} className="flex gap-2 text-sm">
                <span className="text-gray-400 min-w-[100px]">{fieldLabel(f)}:</span>
                <span className={preview[f] ? "text-gray-900" : "text-gray-400 italic"}>
                  {preview[f] ?? "—"}
                </span>
              </div>
            ))}
          </div>
        </div>
      </div>
    </Modal>
  );
}
