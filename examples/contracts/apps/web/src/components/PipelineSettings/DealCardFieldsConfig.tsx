"use client";

import { useEffect, useMemo, useState } from "react";
import useSWR from "swr";
import { DndContext, closestCenter, type DragEndEvent } from "@dnd-kit/core";
import { SortableContext, verticalListSortingStrategy, arrayMove } from "@dnd-kit/sortable";
import { SortableItem } from "@/components/SortableItem";
import { api, ApiError, fetcher } from "@/lib/api";
import {
  STANDARD_DEAL_CARD_FIELDS,
  type DealCardConfig,
  type DealCardFieldConfig,
} from "@/lib/types";

interface DealCardFieldsConfigProps {
  pipelineId: number;
}

function labelFor(f: DealCardFieldConfig): string {
  if (f.label) return f.label;
  return STANDARD_DEAL_CARD_FIELDS.find((s) => s.field === f.field)?.label ?? f.field;
}

// Привести конфиг из API к полному набору стандартных полей (вдруг сервер вернул
// частичный список) с сохранением порядка/настроек.
function buildFields(config: DealCardConfig | undefined): DealCardFieldConfig[] {
  const existing = config?.deal_card_fields ?? [];
  const byField = new Map(existing.map((f) => [f.field, f]));
  const merged: DealCardFieldConfig[] = [];
  // сначала — поля из конфига (в их порядке)
  for (const f of [...existing].sort((a, b) => a.order - b.order)) {
    merged.push({ ...f });
  }
  // затем — недостающие стандартные
  for (const s of STANDARD_DEAL_CARD_FIELDS) {
    if (!byField.has(s.field)) {
      merged.push({ field: s.field, label: s.label, visible: true, order: merged.length, required: false });
    }
  }
  return merged.map((f, i) => ({ ...f, order: i }));
}

export function DealCardFieldsConfig({ pipelineId }: DealCardFieldsConfigProps) {
  const key = `/pipelines/${pipelineId}/deal-card-config`;
  const { data: config, mutate } = useSWR<DealCardConfig>(key, fetcher);

  const [fields, setFields] = useState<DealCardFieldConfig[]>([]);
  const [stageRequired, setStageRequired] = useState<Record<string, string[]>>({});
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    if (config) {
      setFields(buildFields(config));
      setStageRequired(config.stage_required_fields ?? {});
    }
  }, [config]);

  const fieldIds = useMemo(() => fields.map((f) => f.field), [fields]);

  function handleDragEnd(event: DragEndEvent) {
    const { active, over } = event;
    if (!over || active.id === over.id) return;
    const oldIdx = fields.findIndex((f) => f.field === active.id);
    const newIdx = fields.findIndex((f) => f.field === over.id);
    if (oldIdx === -1 || newIdx === -1) return;
    setFields(arrayMove(fields, oldIdx, newIdx).map((f, i) => ({ ...f, order: i })));
    setSaved(false);
  }

  function toggleVisible(field: string) {
    setFields((prev) => prev.map((f) => (f.field === field ? { ...f, visible: !f.visible } : f)));
    setSaved(false);
  }

  function toggleRequired(field: string) {
    setFields((prev) => prev.map((f) => (f.field === field ? { ...f, required: !f.required } : f)));
    setSaved(false);
  }

  async function handleSave() {
    setSaving(true);
    setError(null);
    try {
      await api(key, {
        method: "PUT",
        body: {
          deal_card_fields: fields,
          stage_required_fields: stageRequired,
        },
      });
      await mutate();
      setSaved(true);
    } catch (e) {
      setError(
        e instanceof ApiError
          ? String((e.detail as { detail?: string })?.detail ?? e.message)
          : "Не удалось сохранить"
      );
    } finally {
      setSaving(false);
    }
  }

  if (!config) {
    return <div className="p-6 text-sm text-gray-400">Загрузка…</div>;
  }

  return (
    <div className="p-6 max-w-2xl">
      <div className="flex items-center justify-between mb-4">
        <div>
          <h3 className="text-sm font-semibold text-gray-900 dark:text-gray-100">Поля карточки сделки</h3>
          <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
            Видимость, порядок и обязательность полей в карточке сделки этой воронки.
          </p>
        </div>
        <button className="btn-primary text-sm disabled:opacity-50" disabled={saving} onClick={() => void handleSave()}>
          {saving ? "Сохранение…" : "Сохранить"}
        </button>
      </div>

      {error && <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded mb-3">{error}</div>}
      {saved && !error && (
        <div className="text-sm text-success bg-success/10 px-3 py-2 rounded mb-3 flex items-center gap-2">
          <i className="bi bi-check-circle-fill" /> Сохранено
        </div>
      )}

      {/* Header row */}
      <div className="flex items-center gap-2 px-2 pb-2 text-xs text-gray-400 uppercase tracking-wide">
        <span className="w-7" />
        <span className="flex-1">Поле</span>
        <span className="w-16 text-center">Видно</span>
        <span className="w-20 text-center">Обязательно</span>
      </div>

      <DndContext collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
        <SortableContext items={fieldIds} strategy={verticalListSortingStrategy}>
          <div className="space-y-1">
            {fields.map((f) => (
              <SortableItem key={f.field} id={f.field}>
                <div className="flex items-center gap-2 py-2 px-2 rounded-lg border border-gray-100 dark:border-gray-800 bg-white dark:bg-gray-800">
                  <span className="flex-1 text-sm text-gray-900 dark:text-gray-100">{labelFor(f)}</span>
                  <button
                    type="button"
                    className="w-16 flex justify-center text-lg"
                    onClick={() => toggleVisible(f.field)}
                    title={f.visible ? "Скрыть" : "Показать"}
                  >
                    <i className={`bi ${f.visible ? "bi-eye text-primary" : "bi-eye-slash text-gray-300 dark:text-gray-600"}`} />
                  </button>
                  <label className="w-20 flex justify-center cursor-pointer">
                    <input
                      type="checkbox"
                      className="w-4 h-4"
                      checked={f.required}
                      onChange={() => toggleRequired(f.field)}
                    />
                  </label>
                </div>
              </SortableItem>
            ))}
          </div>
        </SortableContext>
      </DndContext>

      <p className="text-xs text-gray-400 dark:text-gray-500 mt-4">
        <i className="bi bi-info-circle mr-1" />
        Обязательные поля проверяются при переводе сделки на следующий этап.
      </p>
    </div>
  );
}
