"use client";

import { useState, useRef, useEffect, useCallback } from "react";
import { useSortable } from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import { api, ApiError } from "@/lib/api";
import { AutomationInlineCard } from "./AutomationInlineCard";
import { AddTriggerModal } from "./AddTriggerModal";
import { StageEditModal } from "./StageEditModal";
import type { Automation, PipelineStage } from "@/lib/types";

interface StageColumnProps {
  stage: PipelineStage;
  automations: Automation[];
  pipelineId: number;
  onAutomationCreated: () => void;
  onAutomationDeleted: () => void;
  onStageRenamed: (id: number, name: string) => void;
  onStageSaved: () => void;
  /** Если передан — шестерня вызывает внешний коллбэк вместо StageEditModal */
  onSettingsClick?: (stage: PipelineStage) => void;
}

export function StageColumn({
  stage,
  automations,
  pipelineId,
  onAutomationCreated,
  onAutomationDeleted,
  onStageRenamed,
  onStageSaved,
  onSettingsClick,
}: StageColumnProps) {
  const [isEditingName, setIsEditingName] = useState(false);
  const [nameValue, setNameValue] = useState(stage.name);
  const [nameError, setNameError] = useState<string | null>(null);
  const [showGear, setShowGear] = useState(false);
  const [showEditModal, setShowEditModal] = useState(false);
  const [showTriggerModal, setShowTriggerModal] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);

  const {
    attributes,
    listeners,
    setNodeRef,
    transform,
    transition,
    isDragging,
  } = useSortable({ id: stage.id });

  const style: React.CSSProperties = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.5 : 1,
  };

  useEffect(() => {
    setNameValue(stage.name);
  }, [stage.name]);

  useEffect(() => {
    if (isEditingName && inputRef.current) {
      inputRef.current.focus();
      inputRef.current.select();
    }
  }, [isEditingName]);

  const saveName = useCallback(async () => {
    const trimmed = nameValue.trim();
    if (!trimmed || trimmed === stage.name) {
      setIsEditingName(false);
      setNameValue(stage.name);
      return;
    }
    setNameError(null);
    try {
      await api(`/pipelines/${pipelineId}/stages/${stage.id}`, {
        method: "PATCH",
        body: { name: trimmed },
      });
      onStageRenamed(stage.id, trimmed);
      setIsEditingName(false);
    } catch (err) {
      const msg = err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Не удалось сохранить";
      setNameError(msg);
      setNameValue(stage.name);
      setIsEditingName(false);
      // Автосброс ошибки через 3 секунды
      setTimeout(() => setNameError(null), 3000);
    }
  }, [nameValue, stage.name, stage.id, pipelineId, onStageRenamed]);

  async function deleteAutomation(id: number) {
    await api(`/automations/${id}`, { method: "DELETE" });
    onAutomationDeleted();
  }

  return (
    <div
      ref={setNodeRef}
      style={style}
      className="w-80 shrink-0 flex flex-col bg-gray-50 rounded-lg border border-gray-200"
    >
      {/* Header */}
      <div
        className="flex items-center gap-2 px-3 py-3 border-b border-gray-200 bg-white rounded-t-lg"
        onMouseEnter={() => setShowGear(true)}
        onMouseLeave={() => setShowGear(false)}
      >
        {/* Drag handle */}
        <button
          {...attributes}
          {...listeners}
          type="button"
          className="cursor-grab active:cursor-grabbing p-1 text-gray-300 hover:text-gray-500 shrink-0"
          tabIndex={-1}
          title="Перетащить"
        >
          <i className="bi bi-grip-vertical text-sm" />
        </button>

        {/* Цветная точка */}
        <span
          className="w-3 h-3 rounded-full shrink-0"
          style={{ backgroundColor: stage.color || "#6B7A99" }}
        />

        {/* Название — inline edit */}
        {isEditingName ? (
          <input
            ref={inputRef}
            className="input text-sm font-medium flex-1 py-0.5"
            value={nameValue}
            onChange={(e) => setNameValue(e.target.value)}
            onBlur={() => void saveName()}
            onKeyDown={(e) => {
              if (e.key === "Enter") void saveName();
              if (e.key === "Escape") {
                setNameValue(stage.name);
                setIsEditingName(false);
              }
            }}
          />
        ) : (
          <button
            className="flex-1 text-left text-sm font-medium text-gray-800 hover:text-primary truncate"
            onClick={() => setIsEditingName(true)}
            title="Нажмите для редактирования"
          >
            {stage.name}
          </button>
        )}

        {/* Бейджи is_won / is_lost */}
        {stage.is_won && <i className="bi bi-trophy text-success text-xs shrink-0" />}
        {stage.is_lost && <i className="bi bi-x-circle text-danger text-xs shrink-0" />}

        {/* Шестерня (настройки этапа) */}
        <button
          onClick={() => {
            if (onSettingsClick) {
              onSettingsClick(stage);
            } else {
              setShowEditModal(true);
            }
          }}
          className={`btn-ghost text-xs p-1 shrink-0 transition-opacity ${showGear || showEditModal ? "opacity-100" : "opacity-0"}`}
          title="Настройки этапа"
        >
          <i className="bi bi-gear" />
        </button>
      </div>

      {/* Счётчик автоматизаций */}
      <div className="px-3 py-1.5 text-xs text-gray-400 border-b border-gray-100">
        {automations.length > 0 ? `${automations.length} авт.` : "нет автоматизаций"}
      </div>

      {/* Список автоматизаций */}
      <div className="flex-1 px-3 py-2 space-y-2 overflow-y-auto max-h-96">
        {automations.length === 0 ? (
          <div className="px-3 py-4 text-center">
            <i className="bi bi-lightning text-2xl text-gray-300" />
            <p className="text-xs text-gray-400 mt-1">Нет автоматизаций</p>
          </div>
        ) : (
          automations.map((a) => (
            <AutomationInlineCard
              key={a.id}
              automation={a}
              onEdit={() => {
                // Переход на детальную страницу редактирования
                window.location.href = `/admin/automations/${a.id}`;
              }}
              onDelete={() => deleteAutomation(a.id)}
            />
          ))
        )}
      </div>

      {/* Ошибка переименования */}
      {nameError && (
        <div className="px-3 py-1 text-xs text-danger">{nameError}</div>
      )}

      {/* Кнопка Добавить триггер */}
      <div className="px-3 py-2 border-t border-gray-200">
        <button
          onClick={() => setShowTriggerModal(true)}
          className="btn-ghost w-full text-xs justify-center"
        >
          <i className="bi bi-plus-lg mr-1" />+ Добавить триггер
        </button>
      </div>

      {/* Модалы */}
      {showEditModal && (
        <StageEditModal
          stage={stage}
          pipelineId={pipelineId}
          onClose={() => setShowEditModal(false)}
          onSaved={() => { setShowEditModal(false); onStageSaved(); }}
        />
      )}

      {showTriggerModal && (
        <AddTriggerModal
          pipelineId={pipelineId}
          stage={stage}
          onClose={() => setShowTriggerModal(false)}
          onCreated={() => { setShowTriggerModal(false); onAutomationCreated(); }}
        />
      )}
    </div>
  );
}
