"use client";

import React, { useEffect, useState } from "react";
import useSWR, { mutate as globalMutate } from "swr";
import { DndContext, closestCenter, type DragEndEvent } from "@dnd-kit/core";
import { SortableContext, verticalListSortingStrategy, arrayMove, useSortable } from "@dnd-kit/sortable";
import { PageHeader } from "@/components/PageHeader";
import { CustomFieldDefModal } from "@/components/CustomFields/CustomFieldDefModal";
import { fetcher, api } from "@/lib/api";
import { useToast } from "@/components/ui/Toast";
import {
  ENTITY_SCOPE_LABELS,
  CUSTOM_FIELD_KIND_LABELS,
  type CustomFieldDef,
  type EntityScope,
} from "@/lib/types";

const SCOPES = Object.keys(ENTITY_SCOPE_LABELS) as EntityScope[];

function swrKey(scope: EntityScope) {
  return `/custom-field-defs?entity_scope=${scope}`;
}

// Отдельный компонент строки таблицы с DnD-хуком
// (нельзя использовать SortableItem внутри tbody — нужен tr как корень)
function SortableTableRow({
  id,
  def,
  toggleLoading,
  onToggleActive,
  onEdit,
  onDelete,
}: {
  id: number;
  def: CustomFieldDef;
  toggleLoading: number | null;
  onToggleActive: (d: CustomFieldDef) => void;
  onEdit: (d: CustomFieldDef) => void;
  onDelete: (d: CustomFieldDef) => void;
}) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } =
    useSortable({ id });
  const CSS_transform = transform
    ? `translate3d(${transform.x}px, ${transform.y}px, 0) scaleX(${transform.scaleX}) scaleY(${transform.scaleY})`
    : undefined;

  const style: React.CSSProperties = {
    transform: CSS_transform,
    transition,
    opacity: isDragging ? 0.5 : 1,
  };

  return (
    <tr
      ref={setNodeRef}
      style={style}
      className="border-b border-gray-100 hover:bg-gray-50 transition-colors"
    >
      <td className="px-4 py-3">
        <button
          {...attributes}
          {...listeners}
          type="button"
          className="cursor-grab active:cursor-grabbing p-1 text-gray-300 hover:text-gray-500"
          title="Перетащить"
        >
          <i className="bi bi-grip-vertical" />
        </button>
      </td>
      <td className="px-4 py-3 font-medium text-gray-900">{def.label_ru}</td>
      <td className="px-4 py-3">
        <span className="text-xs font-mono bg-gray-100 text-gray-700 px-1.5 rounded">{def.code}</span>
      </td>
      <td className="px-4 py-3">
        <span className="text-xs font-mono bg-gray-100 text-gray-700 px-1.5 rounded">
          {CUSTOM_FIELD_KIND_LABELS[def.kind]}
        </span>
      </td>
      <td className="px-4 py-3">
        {def.is_required ? (
          <span className="text-xs text-danger font-medium">да</span>
        ) : (
          <span className="text-xs text-gray-400">нет</span>
        )}
      </td>
      <td className="px-4 py-3">
        <button
          onClick={() => onToggleActive(def)}
          disabled={toggleLoading === def.id}
          className={`relative inline-flex h-5 w-9 items-center rounded-full transition-colors ${
            def.is_active ? "bg-primary" : "bg-gray-300"
          } ${toggleLoading === def.id ? "opacity-50" : ""}`}
          title={def.is_active ? "Деактивировать" : "Активировать"}
        >
          <span
            className={`inline-block h-3.5 w-3.5 transform rounded-full bg-white transition-transform shadow ${
              def.is_active ? "translate-x-4" : "translate-x-0.5"
            }`}
          />
        </button>
      </td>
      <td className="px-4 py-3">
        <div className="flex items-center gap-1 justify-end">
          <button onClick={() => onEdit(def)} className="btn-ghost p-1 text-xs" title="Редактировать">
            <i className="bi bi-pencil" />
          </button>
          <button onClick={() => onDelete(def)} className="btn-ghost p-1 text-xs text-danger" title="Удалить">
            <i className="bi bi-trash" />
          </button>
        </div>
      </td>
    </tr>
  );
}

export default function CustomFieldsPage() {
  const { toast } = useToast();
  const [activeScope, setActiveScope] = useState<EntityScope>("lead");
  const [modalOpen, setModalOpen] = useState(false);
  const [editDef, setEditDef] = useState<CustomFieldDef | null>(null);
  const [search, setSearch] = useState("");
  const [toggleLoading, setToggleLoading] = useState<number | null>(null);
  const [localDefs, setLocalDefs] = useState<CustomFieldDef[]>([]);

  const key = swrKey(activeScope);
  const { data: defs, isLoading, error } = useSWR<CustomFieldDef[]>(key, fetcher);

  // Sync localDefs on SWR update
  useEffect(() => {
    setLocalDefs(defs ?? []);
  }, [defs]);

  function openCreate() {
    setEditDef(null);
    setModalOpen(true);
  }

  function openEdit(def: CustomFieldDef) {
    setEditDef(def);
    setModalOpen(true);
  }

  function handleSaved() {
    globalMutate(key);
    // Also invalidate all scope keys so CustomFieldsBlock stays fresh
    for (const s of SCOPES) {
      globalMutate(`/custom-field-defs?entity_scope=${s}&is_active=true`);
    }
  }

  async function handleDelete(def: CustomFieldDef) {
    if (!window.confirm(`Удалить поле «${def.label_ru}»? Данные в уже заполненных карточках останутся.`)) return;
    try {
      await api(`/custom-field-defs/${def.id}`, { method: "DELETE" });
      handleSaved();
      toast.success(`Поле «${def.label_ru}» удалено`);
    } catch {
      toast.error("Не удалось удалить поле");
    }
  }

  async function handleToggleActive(def: CustomFieldDef) {
    setToggleLoading(def.id);
    try {
      await api(`/custom-field-defs/${def.id}`, {
        method: "PATCH",
        body: { is_active: !def.is_active },
      });
      handleSaved();
    } catch {
      // silent — the toggle reverts on next fetch
    } finally {
      setToggleLoading(null);
    }
  }

  const filtered = localDefs.filter((d) =>
    d.label_ru.toLowerCase().includes(search.toLowerCase()) ||
    d.code.toLowerCase().includes(search.toLowerCase()),
  );

  function handleFieldsDragEnd(event: DragEndEvent) {
    const { active, over } = event;
    if (!over || active.id === over.id) return;
    const oldIdx = localDefs.findIndex((d) => d.id === active.id);
    const newIdx = localDefs.findIndex((d) => d.id === over.id);
    if (oldIdx < 0 || newIdx < 0) return;
    const reordered = arrayMove(localDefs, oldIdx, newIdx);
    setLocalDefs(reordered);

    void api(`/custom-field-defs/reorder?entity_scope=${activeScope}`, {
      method: "PATCH",
      body: reordered.map((d, idx) => ({ id: d.id, sort_order: idx })),
    }).catch(() => {
      setLocalDefs(defs ?? []);
    });
  }

  const scopeLabel = ENTITY_SCOPE_LABELS[activeScope];

  return (
    <div>
      <PageHeader
        title="Кастомные поля"
        description="Добавляй поля в карточки сущностей без изменения кода"
        actions={
          <button onClick={openCreate} className="btn-primary">
            <i className="bi bi-plus mr-1" />
            Добавить поле
          </button>
        }
      />

      <div className="p-8 space-y-4">
        {/* Tabs */}
        <div className="card p-0 overflow-hidden">
          <div className="flex border-b border-gray-200 overflow-x-auto">
            {SCOPES.map((scope) => (
              <button
                key={scope}
                onClick={() => { setActiveScope(scope); setSearch(""); }}
                className={`px-4 py-3 text-sm font-medium whitespace-nowrap border-b-2 transition-colors ${
                  activeScope === scope
                    ? "border-primary text-primary"
                    : "border-transparent text-gray-600 hover:text-gray-900 hover:bg-gray-50"
                }`}
              >
                {ENTITY_SCOPE_LABELS[scope]}
              </button>
            ))}
          </div>

          {/* Search bar */}
          <div className="px-4 py-3 border-b border-gray-100 flex items-center gap-3">
            <div className="relative flex-1 max-w-sm">
              <i className="bi bi-search absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm" />
              <input
                className="input w-full pl-8"
                placeholder="Поиск по названию или коду…"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
            </div>
            <button onClick={openCreate} className="btn-secondary text-sm">
              <i className="bi bi-plus mr-1" />
              Добавить
            </button>
          </div>

          {/* Error */}
          {error && (
            <div className="mx-4 my-3 text-sm text-danger bg-danger/10 px-3 py-2 rounded">
              Не удалось загрузить поля
            </div>
          )}

          {/* Loading skeleton */}
          {isLoading && (
            <div className="animate-pulse space-y-px">
              {[1, 2, 3].map((i) => (
                <div key={i} className="flex items-center gap-4 px-4 py-3 border-b border-gray-100">
                  <div className="h-4 bg-gray-200 rounded w-1/4" />
                  <div className="h-4 bg-gray-200 rounded w-1/6" />
                  <div className="h-4 bg-gray-200 rounded w-1/6" />
                  <div className="h-4 bg-gray-200 rounded w-12 ml-auto" />
                </div>
              ))}
            </div>
          )}

          {/* Empty state */}
          {!isLoading && !error && filtered.length === 0 && (
            <div className="py-16 text-center">
              <i className="bi bi-layout-text-window-reverse text-5xl text-gray-300 block mb-3" />
              <div className="text-base font-medium text-gray-700 mb-1">
                {search ? `Ничего не найдено` : `Нет полей для «${scopeLabel}»`}
              </div>
              <div className="text-sm text-gray-400 mb-4">
                {search
                  ? "Попробуй другой запрос"
                  : "Добавь первое кастомное поле — оно появится во всех карточках этого типа"}
              </div>
              {!search && (
                <button onClick={openCreate} className="btn-primary">
                  <i className="bi bi-plus mr-1" />
                  Добавить поле
                </button>
              )}
            </div>
          )}

          {/* Table */}
          {!isLoading && filtered.length > 0 && (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-gray-200 bg-gray-50">
                    <th className="text-left px-4 py-2 text-xs text-gray-500 font-medium w-8" />
                    <th className="text-left px-4 py-2 text-xs text-gray-500 font-medium">Название</th>
                    <th className="text-left px-4 py-2 text-xs text-gray-500 font-medium">Код</th>
                    <th className="text-left px-4 py-2 text-xs text-gray-500 font-medium">Тип</th>
                    <th className="text-left px-4 py-2 text-xs text-gray-500 font-medium">Обяз.</th>
                    <th className="text-left px-4 py-2 text-xs text-gray-500 font-medium">Активно</th>
                    <th className="px-4 py-2 w-16" />
                  </tr>
                </thead>
                <DndContext collisionDetection={closestCenter} onDragEnd={handleFieldsDragEnd}>
                  <SortableContext
                    items={filtered.map((d) => d.id)}
                    strategy={verticalListSortingStrategy}
                  >
                  <tbody>
                    {filtered.map((def) => (
                      <SortableTableRow
                        key={def.id}
                        id={def.id}
                        def={def}
                        toggleLoading={toggleLoading}
                        onToggleActive={handleToggleActive}
                        onEdit={openEdit}
                        onDelete={handleDelete}
                      />
                    ))}
                  </tbody>
                  </SortableContext>
                </DndContext>
              </table>
            </div>
          )}
        </div>
      </div>

      <CustomFieldDefModal
        open={modalOpen}
        def={editDef}
        defaultScope={activeScope}
        onClose={() => setModalOpen(false)}
        onSaved={handleSaved}
      />
    </div>
  );
}
