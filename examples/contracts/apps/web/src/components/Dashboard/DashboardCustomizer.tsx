"use client";

import { useEffect, useState } from "react";
import {
  DndContext,
  closestCenter,
  PointerSensor,
  useSensor,
  useSensors,
  type DragEndEvent,
} from "@dnd-kit/core";
import {
  SortableContext,
  arrayMove,
  verticalListSortingStrategy,
} from "@dnd-kit/sortable";
import { Modal } from "@/components/Modal";
import { SortableItem } from "@/components/SortableItem";
import {
  DASHBOARD_WIDGETS,
  DEFAULT_LAYOUT,
  type DashboardWidgetConfig,
  type DashboardWidgetId,
} from "@/lib/dashboardLayout";

const WIDGET_META = new Map(DASHBOARD_WIDGETS.map((w) => [w.id, w]));

interface Props {
  open: boolean;
  layout: DashboardWidgetConfig[];
  saving: boolean;
  onClose: () => void;
  onSave: (next: DashboardWidgetConfig[]) => void;
}

/**
 * Модалка настройки дашборда (Wave 2b): reorder перетаскиванием + show/hide глазом.
 * Паттерн FinFamily: 1D-список, без free-grid.
 */
export function DashboardCustomizer({ open, layout, saving, onClose, onSave }: Props) {
  const [draft, setDraft] = useState<DashboardWidgetConfig[]>(layout);

  // Синхронизируем draft при открытии / смене внешнего layout
  useEffect(() => {
    if (open) setDraft(layout);
  }, [open, layout]);

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 5 } }),
  );

  function handleDragEnd(e: DragEndEvent) {
    const { active, over } = e;
    if (!over || active.id === over.id) return;
    setDraft((prev) => {
      const oldIndex = prev.findIndex((w) => w.id === active.id);
      const newIndex = prev.findIndex((w) => w.id === over.id);
      if (oldIndex < 0 || newIndex < 0) return prev;
      return arrayMove(prev, oldIndex, newIndex).map((w, i) => ({ ...w, order: i }));
    });
  }

  function toggleVisible(id: DashboardWidgetId) {
    setDraft((prev) => prev.map((w) => (w.id === id ? { ...w, visible: !w.visible } : w)));
  }

  function reset() {
    setDraft(DEFAULT_LAYOUT.map((w) => ({ ...w })));
  }

  function save() {
    // Нормализуем order по текущему порядку
    onSave(draft.map((w, i) => ({ ...w, order: i })));
  }

  return (
    <Modal
      open={open}
      title="Настроить дашборд"
      description="Перетащите за ручку, чтобы изменить порядок. Глазом — скрыть или показать."
      onClose={onClose}
      width="md"
      footer={
        <>
          <button type="button" className="btn-ghost text-sm mr-auto" onClick={reset}>
            <i className="bi bi-arrow-counterclockwise mr-1" /> Вернуть по умолчанию
          </button>
          <button type="button" className="btn-secondary text-sm" onClick={onClose}>
            Отмена
          </button>
          <button type="button" className="btn-primary text-sm" onClick={save} disabled={saving}>
            <i className="bi bi-check-lg mr-1" />
            {saving ? "Сохранение…" : "Сохранить"}
          </button>
        </>
      }
    >
      <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
        <SortableContext items={draft.map((w) => w.id)} strategy={verticalListSortingStrategy}>
          <div className="space-y-1.5">
            {draft.map((w) => {
              const meta = WIDGET_META.get(w.id);
              if (!meta) return null;
              return (
                <SortableItem
                  key={w.id}
                  id={w.id}
                  className="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-2 py-2"
                >
                  <div className="flex items-center gap-2">
                    <i className={`bi ${meta.icon} text-primary shrink-0`} />
                    <span
                      className={
                        "flex-1 text-sm truncate " +
                        (w.visible ? "text-gray-800 dark:text-gray-200" : "text-gray-400 dark:text-gray-500 line-through")
                      }
                    >
                      {meta.label}
                    </span>
                    <button
                      type="button"
                      onClick={() => toggleVisible(w.id)}
                      className="shrink-0 p-1.5 rounded hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-500 dark:text-gray-400"
                      title={w.visible ? "Скрыть виджет" : "Показать виджет"}
                      aria-label={w.visible ? "Скрыть виджет" : "Показать виджет"}
                    >
                      <i className={`bi ${w.visible ? "bi-eye" : "bi-eye-slash"}`} />
                    </button>
                  </div>
                </SortableItem>
              );
            })}
          </div>
        </SortableContext>
      </DndContext>
    </Modal>
  );
}
