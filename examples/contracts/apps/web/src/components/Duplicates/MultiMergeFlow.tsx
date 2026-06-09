"use client";

import { useState } from "react";
import { api, ApiError } from "@/lib/api";
import type { DuplicateGroup, DuplicateRecord } from "@/lib/types";

interface Props {
  group: DuplicateGroup;
  onClose: () => void;
  onMerged: () => void;
}

type MergeStep = "select_master" | "confirm";

export function MultiMergeFlow({ group, onClose, onMerged }: Props) {
  const [step, setStep] = useState<MergeStep>("select_master");
  const [masterId, setMasterId] = useState<number | null>(null);
  const [merging, setMerging] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const masterRecord = group.records.find((r) => r.id === masterId) ?? null;
  const toDelete = group.records.filter((r) => r.id !== masterId);

  async function handleMultiMerge() {
    if (masterId === null) return;
    setMerging(true);
    setError(null);

    try {
      await api("/duplicates/merge", {
        method: "POST",
        body: {
          entity_type: group.entity,
          master_id: masterId,
          duplicate_ids: toDelete.map((r) => r.id),
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

  if (step === "select_master") {
    return (
      <>
        <div className="mb-4">
          <div className="text-xs text-gray-500 mb-1">Шаг 1 из 2</div>
          <div className="font-semibold text-gray-800 mb-1">Выбери мастер-запись</div>
          <div className="text-sm text-gray-500">
            Остальные будут удалены, их данные перенесены в мастер
          </div>
        </div>

        <div className="space-y-2">
          {group.records.map((r) => (
            <RecordCard
              key={r.id}
              record={r}
              selected={masterId === r.id}
              onSelect={() => setMasterId(r.id)}
            />
          ))}
        </div>

        <div className="flex justify-between mt-6">
          <button className="btn-ghost" onClick={onClose}>
            Отмена
          </button>
          <button
            className="btn-primary"
            disabled={masterId === null}
            onClick={() => setStep("confirm")}
          >
            Далее <i className="bi bi-arrow-right ml-1" />
          </button>
        </div>
      </>
    );
  }

  // step === "confirm"
  return (
    <>
      <div className="mb-4">
        <div className="text-xs text-gray-500 mb-1">Шаг 2 из 2</div>
        <div className="font-semibold text-gray-800">Подтвердить объединение</div>
      </div>

      <div className="space-y-3">
        <div>
          <div className="text-xs text-gray-500 font-medium mb-1.5">Останется (мастер):</div>
          {masterRecord && (
            <div className="rounded-lg border border-success/30 bg-success/5 px-3 py-2">
              <div className="flex items-center gap-2">
                <i className="bi bi-check-circle text-success shrink-0" />
                <div>
                  <div className="font-medium text-gray-900">{masterRecord.display_name}</div>
                  <div className="text-xs text-gray-500 mt-0.5 space-x-3">
                    {masterRecord.fields.email && (
                      <span>Email: {masterRecord.fields.email}</span>
                    )}
                    {masterRecord.fields.tax_id && (
                      <span>БИН: {masterRecord.fields.tax_id}</span>
                    )}
                  </div>
                </div>
              </div>
            </div>
          )}
        </div>

        <div>
          <div className="text-xs text-gray-500 font-medium mb-1.5">
            Будут удалены ({toDelete.length} {toDelete.length === 1 ? "запись" : "записи"}):
          </div>
          <div className="rounded-lg border border-danger/20 bg-danger/5 px-3 py-2 space-y-1">
            {toDelete.map((r) => (
              <div key={r.id} className="flex items-center gap-2">
                <i className="bi bi-x-circle text-danger shrink-0 text-sm" />
                <span className="text-sm text-gray-700">{r.display_name}</span>
              </div>
            ))}
          </div>
        </div>

        <p className="text-xs text-gray-500 mt-1">
          Связанные сделки, договоры и активности будут перенесены на мастер-запись.
        </p>

        {error && (
          <div className="rounded-md bg-danger/10 text-danger px-3 py-2 text-sm">{error}</div>
        )}
      </div>

      <div className="flex justify-between mt-6">
        <div className="flex gap-2">
          <button className="btn-ghost" onClick={onClose}>
            Отмена
          </button>
          <button
            className="btn-secondary"
            onClick={() => { setStep("select_master"); setError(null); }}
            disabled={merging}
          >
            <i className="bi bi-arrow-left mr-1" /> Назад
          </button>
        </div>
        <button
          className="px-4 py-2 rounded-md text-sm font-medium text-white bg-danger hover:bg-danger/90 transition-colors disabled:opacity-50"
          onClick={handleMultiMerge}
          disabled={merging}
        >
          {merging ? (
            <>
              <i className="bi bi-arrow-clockwise animate-spin mr-2" />
              Объединяем…
            </>
          ) : (
            <>
              <i className="bi bi-union mr-2" />
              Объединить
            </>
          )}
        </button>
      </div>
    </>
  );
}

function RecordCard({
  record,
  selected,
  onSelect,
}: {
  record: DuplicateRecord;
  selected: boolean;
  onSelect: () => void;
}) {
  return (
    <label
      className={`flex gap-3 p-3 rounded-lg border cursor-pointer transition-colors ${
        selected
          ? "border-primary bg-primary/5"
          : "border-gray-200 hover:border-gray-300"
      }`}
    >
      <input
        type="radio"
        name="master"
        value={record.id}
        checked={selected}
        onChange={onSelect}
        className="mt-1 accent-primary shrink-0"
      />
      <div className="min-w-0">
        <div className="font-medium text-gray-900 truncate">{record.display_name}</div>
        <div className="text-xs text-gray-500 mt-0.5 flex flex-wrap gap-x-3">
          {record.fields.email && <span>Email: {record.fields.email}</span>}
          {record.fields.phone && <span>Тел: {record.fields.phone}</span>}
          {record.fields.tax_id && <span>БИН: {record.fields.tax_id}</span>}
        </div>
      </div>
    </label>
  );
}
