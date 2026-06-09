"use client";

import { useState, useCallback } from "react";
import useSWR from "swr";
import {
  DndContext,
  closestCenter,
  type DragEndEvent,
  PointerSensor,
  useSensor,
  useSensors,
} from "@dnd-kit/core";
import {
  SortableContext,
  horizontalListSortingStrategy,
  arrayMove,
} from "@dnd-kit/sortable";
import { fetcher, api } from "@/lib/api";
import { StageColumn } from "./StageColumn";
import { getStageAutomations } from "@/lib/pipelineVisual";
import type { Automation, Channel, PipelineStage } from "@/lib/types";

interface VisualCanvasProps {
  pipelineId: number;
  /** Если передан — клик на шестерню этапа вызывает этот коллбэк вместо StageEditModal */
  onStageSettingsClick?: (stage: PipelineStage) => void;
  /** Скрыть внутренний SourcesSidebar (когда внешняя SourcesPanel уже показывает источники) */
  hideSourcesSidebar?: boolean;
}

/** Inline AddStageCard */
function AddStageCard({
  pipelineId,
  stagesCount,
  onCreated,
}: {
  pipelineId: number;
  stagesCount: number;
  onCreated: (stage: PipelineStage) => void;
}) {
  const [isAdding, setIsAdding] = useState(false);
  const [value, setValue] = useState("");
  const [loading, setLoading] = useState(false);

  async function handleCreate() {
    const trimmed = value.trim();
    if (!trimmed) return;
    setLoading(true);
    try {
      const newStage = await api<PipelineStage>(`/pipelines/${pipelineId}/stages`, {
        method: "POST",
        body: { name: trimmed, sort_order: stagesCount },
      });
      onCreated(newStage);
      setValue("");
      setIsAdding(false);
    } catch {
      // ignore — покажем просто reset
    } finally {
      setLoading(false);
    }
  }

  if (!isAdding) {
    return (
      <div
        onClick={() => setIsAdding(true)}
        className="w-48 shrink-0 min-h-[120px] border-2 border-dashed border-gray-300 rounded-lg flex flex-col items-center justify-center cursor-pointer text-gray-400 hover:border-primary hover:text-primary transition-colors gap-1"
      >
        <i className="bi bi-plus-lg text-xl" />
        <span className="text-sm">+ Добавить этап</span>
      </div>
    );
  }

  return (
    <div className="w-64 shrink-0 border-2 border-primary rounded-lg p-3 bg-white flex flex-col gap-2">
      <input
        autoFocus
        className="input text-sm"
        placeholder="Название нового этапа…"
        value={value}
        disabled={loading}
        onChange={(e) => setValue(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === "Enter") void handleCreate();
          if (e.key === "Escape") {
            setValue("");
            setIsAdding(false);
          }
        }}
        onBlur={() => {
          if (!value.trim()) {
            setValue("");
            setIsAdding(false);
          }
        }}
      />
      <div className="text-xs text-gray-400">
        {loading ? "Создаём…" : "Enter = сохранить · Esc = отмена"}
      </div>
    </div>
  );
}

/** Sidebar — источники сделок */
function SourcesSidebar() {
  const { data: channels } = useSWR<Channel[]>("/channels", fetcher);

  return (
    <aside className="w-[200px] shrink-0 border-r border-gray-200 bg-gray-50 flex flex-col overflow-y-auto">
      <div className="px-3 py-3 border-b border-gray-100">
        <h3 className="text-xs font-semibold text-gray-500 uppercase tracking-wide">
          Источники сделок
        </h3>
      </div>

      {channels === undefined ? (
        <div className="px-3 py-3 space-y-2">
          {[1, 2].map((i) => (
            <div key={i} className="h-6 bg-gray-200 rounded animate-pulse" />
          ))}
        </div>
      ) : channels.length === 0 ? (
        <div className="px-3 py-4 text-xs text-gray-400">
          <p>Нет подключённых каналов.</p>
          <a href="/admin/channels" className="text-primary hover:underline">
            Настроить в Каналы →
          </a>
        </div>
      ) : (
        <div className="px-3 py-2 space-y-1">
          {channels.map((c) => (
            <div key={c.id} className="flex items-center gap-1.5 text-xs text-gray-600 py-1">
              <i className="bi bi-circle-fill text-primary text-[6px]" />
              <span className="truncate">{c.name}</span>
            </div>
          ))}
        </div>
      )}
    </aside>
  );
}

export function VisualCanvas({ pipelineId, onStageSettingsClick, hideSourcesSidebar }: VisualCanvasProps) {
  const {
    data: stages,
    mutate: mutateStages,
    error: stagesError,
  } = useSWR<PipelineStage[]>(`/pipelines/${pipelineId}/stages`, fetcher);

  const {
    data: automations,
    mutate: mutateAutomations,
  } = useSWR<Automation[]>(`/automations?pipeline_id=${pipelineId}`, fetcher);

  const [localStages, setLocalStages] = useState<PipelineStage[] | null>(null);

  // Работаем с локальными стейджами если они есть (optimistic update при DnD), иначе с данными SWR
  const effectiveStages = localStages ?? (stages ?? []);

  const sensors = useSensors(
    useSensor(PointerSensor, {
      activationConstraint: { distance: 5 },
    }),
  );

  const handleDragEnd = useCallback(
    async (event: DragEndEvent) => {
      const { active, over } = event;
      if (!over || active.id === over.id) return;

      const current = localStages ?? (stages ?? []);
      const oldIdx = current.findIndex((s) => s.id === active.id);
      const newIdx = current.findIndex((s) => s.id === over.id);
      if (oldIdx < 0 || newIdx < 0) return;

      const reordered = arrayMove(current, oldIdx, newIdx);
      setLocalStages(reordered);

      try {
        await api(`/pipelines/${pipelineId}/stages/reorder`, {
          method: "PATCH",
          body: reordered.map((s, idx) => ({ id: s.id, sort_order: idx })),
        });
        await mutateStages();
        setLocalStages(null);
      } catch {
        // Откат
        setLocalStages(null);
      }
    },
    [localStages, stages, pipelineId, mutateStages],
  );

  function handleStageRenamed(id: number, name: string) {
    const current = localStages ?? (stages ?? []);
    setLocalStages(current.map((s) => (s.id === id ? { ...s, name } : s)));
  }

  function handleStageCreated(newStage: PipelineStage) {
    const current = localStages ?? (stages ?? []);
    setLocalStages([...current, newStage]);
    void mutateStages();
  }

  if (stagesError) {
    return (
      <div className="flex-1 flex items-center justify-center">
        <div className="text-center">
          <i className="bi bi-exclamation-triangle text-3xl text-danger" />
          <p className="text-sm text-danger mt-2">Не удалось загрузить этапы</p>
          <button
            onClick={() => void mutateStages()}
            className="btn-secondary mt-3 text-sm"
          >
            <i className="bi bi-arrow-repeat mr-1" />Повторить
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="flex flex-1 overflow-hidden">
      {!hideSourcesSidebar && <SourcesSidebar />}

      {/* Canvas */}
      <div className="flex-1 overflow-x-auto overflow-y-auto">
        {stages === undefined ? (
          // Skeleton loading
          <div className="flex gap-4 p-6">
            {[1, 2, 3].map((i) => (
              <div
                key={i}
                className="w-80 shrink-0 h-48 bg-gray-100 animate-pulse rounded-lg"
              />
            ))}
          </div>
        ) : (
          <DndContext
            sensors={sensors}
            collisionDetection={closestCenter}
            onDragEnd={handleDragEnd}
          >
            <SortableContext
              items={effectiveStages.map((s) => s.id)}
              strategy={horizontalListSortingStrategy}
            >
              <div
                className="flex gap-4 p-6 min-h-full items-start"
                style={{ minWidth: "max-content" }}
              >
                {effectiveStages.map((stage) => (
                  <StageColumn
                    key={stage.id}
                    stage={stage}
                    automations={getStageAutomations(automations ?? [], stage.id)}
                    pipelineId={pipelineId}
                    onAutomationCreated={() => void mutateAutomations()}
                    onAutomationDeleted={() => void mutateAutomations()}
                    onStageRenamed={handleStageRenamed}
                    onStageSaved={() => void mutateStages()}
                    onSettingsClick={onStageSettingsClick}
                  />
                ))}

                <AddStageCard
                  pipelineId={pipelineId}
                  stagesCount={effectiveStages.length}
                  onCreated={handleStageCreated}
                />
              </div>
            </SortableContext>
          </DndContext>
        )}
      </div>
    </div>
  );
}
