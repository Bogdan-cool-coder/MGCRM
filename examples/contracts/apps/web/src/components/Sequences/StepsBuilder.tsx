"use client";

import { useEffect, useState } from "react";
import useSWR from "swr";
import { DndContext, closestCenter, type DragEndEvent } from "@dnd-kit/core";
import { SortableContext, verticalListSortingStrategy, arrayMove } from "@dnd-kit/sortable";
import { SortableItem } from "@/components/SortableItem";
import { BranchStepBlock } from "./BranchStepBlock";
import { fetcher } from "@/lib/api";
import type { SequenceStep, SequenceStepKind, BranchConfig } from "@/lib/types";

interface Props {
  steps: SequenceStep[];
  onChange: (steps: SequenceStep[]) => void;
}

/** Fallback для step-kinds если API недоступен */
const STEP_KIND_FALLBACK: { value: SequenceStepKind; label: string }[] = [
  { value: "wait", label: "Задержка" },
  { value: "tg_notify", label: "Telegram-уведомление" },
  { value: "email", label: "Email" },
  { value: "create_task", label: "Создать задачу" },
  { value: "if_else", label: "If/Else (ветвление)" },
];

interface StepKindOption {
  value: string;
  label: string;
}

function isSequenceStepKind(v: string): v is SequenceStepKind {
  return ["wait", "tg_notify", "email", "create_task", "if_else"].includes(v);
}

function parseStepKind(v: string): SequenceStepKind {
  return isSequenceStepKind(v) ? v : "wait";
}

function isBranchConfig(v: unknown): v is BranchConfig {
  return (
    typeof v === "object" &&
    v !== null &&
    "condition" in v &&
    "true_steps" in v &&
    "false_steps" in v
  );
}

function defaultConfig(kind: SequenceStepKind): Record<string, unknown> {
  if (kind === "tg_notify") return { recipient: "owner", message: "" };
  if (kind === "email") return { subject: "", body: "" };
  if (kind === "create_task") return { title: "", responsible: "owner" };
  if (kind === "if_else") {
    const branchDefault: BranchConfig = {
      condition: { field: "deal_amount", operator: "gt", value: "" },
      true_steps: [],
      false_steps: [],
    };
    return branchDefault as unknown as Record<string, unknown>;
  }
  return {};
}

/** Блок конфига шага по kind */
function StepConfigBlock({
  kind,
  config,
  onChange,
}: {
  kind: SequenceStepKind;
  config: Record<string, unknown>;
  onChange: (next: Record<string, unknown>) => void;
}) {
  if (kind === "wait") return null;

  if (kind === "tg_notify") {
    const recipient = typeof config.recipient === "string" ? config.recipient : "owner";
    const message = typeof config.message === "string" ? config.message : "";
    return (
      <div className="space-y-2 mt-2">
        <div>
          <label className="label text-xs">Кому</label>
          <select
            className="input text-sm"
            value={recipient}
            onChange={(e) => onChange({ ...config, recipient: e.target.value })}
          >
            <option value="owner">Ответственному</option>
            <option value="specific">Конкретному (не поддерживается в MVP)</option>
          </select>
        </div>
        <div>
          <label className="label text-xs">Сообщение</label>
          <textarea
            className="input text-sm"
            rows={2}
            value={message}
            onChange={(e) => onChange({ ...config, message: e.target.value })}
            placeholder="Текст уведомления..."
          />
        </div>
      </div>
    );
  }

  if (kind === "email") {
    const subject = typeof config.subject === "string" ? config.subject : "";
    const body = typeof config.body === "string" ? config.body : "";
    return (
      <div className="space-y-2 mt-2">
        <div>
          <label className="label text-xs">Тема</label>
          <input
            className="input text-sm"
            type="text"
            value={subject}
            onChange={(e) => onChange({ ...config, subject: e.target.value })}
            placeholder="Тема письма"
          />
        </div>
        <div>
          <label className="label text-xs">Текст</label>
          <textarea
            className="input text-sm"
            rows={3}
            value={body}
            onChange={(e) => onChange({ ...config, body: e.target.value })}
            placeholder="Поддерживается Jinja-синтаксис"
          />
        </div>
      </div>
    );
  }

  if (kind === "create_task") {
    const title = typeof config.title === "string" ? config.title : "";
    const responsible = typeof config.responsible === "string" ? config.responsible : "owner";
    return (
      <div className="space-y-2 mt-2">
        <div>
          <label className="label text-xs">Название задачи</label>
          <input
            className="input text-sm"
            type="text"
            value={title}
            onChange={(e) => onChange({ ...config, title: e.target.value })}
            placeholder="Заголовок задачи"
          />
        </div>
        <div>
          <label className="label text-xs">Ответственный</label>
          <select
            className="input text-sm"
            value={responsible}
            onChange={(e) => onChange({ ...config, responsible: e.target.value })}
          >
            <option value="owner">Ответственный за цель</option>
            <option value="specific">Конкретный (не поддерживается в MVP)</option>
          </select>
        </div>
      </div>
    );
  }

  return null;
}

/**
 * StepsBuilder — редактор шагов последовательности.
 * Поддерживает добавление/удаление/reorder шагов и динамические config-блоки по kind.
 */
export function StepsBuilder({ steps, onChange }: Props) {
  const { data: kindData } = useSWR<StepKindOption[]>(
    "/sequence-runs/step-kinds",
    fetcher,
  );

  const [kinds, setKinds] = useState<{ value: SequenceStepKind; label: string }[]>(
    STEP_KIND_FALLBACK,
  );

  useEffect(() => {
    if (kindData && kindData.length > 0) {
      const mapped = kindData
        .filter((k) => isSequenceStepKind(k.value))
        .map((k) => ({ value: parseStepKind(k.value), label: k.label }));
      if (mapped.length > 0) setKinds(mapped);
    }
  }, [kindData]);

  function addStep() {
    const nextOrder = steps.length > 0 ? Math.max(...steps.map((s) => s.order)) + 1 : 1;
    const newStep: SequenceStep = {
      order: nextOrder,
      kind: "wait",
      delay_days: 1,
      config: {},
    };
    onChange([...steps, newStep]);
  }

  function removeStep(idx: number) {
    const next = steps.filter((_, i) => i !== idx).map((s, i) => ({ ...s, order: i + 1 }));
    onChange(next);
  }

  function handleDragEnd(event: DragEndEvent) {
    const { active, over } = event;
    if (!over || active.id === over.id) return;
    const oldIdx = steps.findIndex((_, i) => i === active.id);
    const newIdx = steps.findIndex((_, i) => i === over.id);
    if (oldIdx < 0 || newIdx < 0) return;
    const reordered = arrayMove(steps, oldIdx, newIdx).map((s, i) => ({ ...s, order: i + 1 }));
    onChange(reordered);
  }

  function updateStep(idx: number, patch: Partial<SequenceStep>) {
    const next = steps.map((s, i) => (i === idx ? { ...s, ...patch } : s));
    onChange(next);
  }

  function changeStepKind(idx: number, rawKind: string) {
    const kind = parseStepKind(rawKind);
    updateStep(idx, { kind, config: defaultConfig(kind) });
  }

  return (
    <DndContext collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
      <SortableContext items={steps.map((_, i) => i)} strategy={verticalListSortingStrategy}>
    <div className="space-y-3">
      {steps.length === 0 && (
        <div className="text-sm text-gray-500 text-center py-4 border border-dashed border-gray-300 rounded-md">
          Шагов пока нет. Добавьте первый шаг.
        </div>
      )}

      {steps.map((step, idx) => (
        <SortableItem key={idx} id={idx}>
        <div className="border border-gray-200 rounded-md p-3 bg-gray-50 overflow-x-auto flex-1">
          <div className="flex items-center gap-2 flex-wrap min-w-[420px]">
            <span className="text-xs text-gray-500 shrink-0">Шаг {idx + 1}</span>

            {/* Kind */}
            <div className="shrink-0">
              <select
                className="input text-sm py-1"
                value={step.kind}
                onChange={(e) => changeStepKind(idx, e.target.value)}
              >
                {kinds.map((k) => (
                  <option key={k.value} value={k.value}>
                    {k.label}
                  </option>
                ))}
              </select>
            </div>

            {/* Delay days — скрыт для if_else */}
            {step.kind !== "if_else" && (
              <div className="flex items-center gap-1 shrink-0">
                <label className="text-xs text-gray-600 whitespace-nowrap">Задержка:</label>
                <input
                  className="input text-sm py-1 w-16"
                  type="number"
                  min={0}
                  value={step.delay_days}
                  onChange={(e) =>
                    updateStep(idx, { delay_days: Math.max(0, Number(e.target.value) || 0) })
                  }
                />
                <span className="text-xs text-gray-600">дн.</span>
              </div>
            )}

            {/* Remove */}
            <button
              type="button"
              className="btn-ghost text-danger p-1 ml-auto shrink-0"
              onClick={() => removeStep(idx)}
              title="Удалить шаг"
            >
              <i className="bi bi-x-lg" />
            </button>
          </div>

          {/* Dynamic config block */}
          {step.kind === "if_else" && isBranchConfig(step.config) ? (
            <BranchStepBlock
              config={step.config}
              onChange={(next) => {
                const asRecord: Record<string, unknown> = next as unknown as Record<string, unknown>;
                updateStep(idx, { config: asRecord });
              }}
              depth={0}
            />
          ) : step.kind !== "if_else" ? (
            <StepConfigBlock
              kind={step.kind}
              config={step.config}
              onChange={(next) => updateStep(idx, { config: next })}
            />
          ) : null}
        </div>
        </SortableItem>
      ))}

      <button
        type="button"
        className="btn-secondary w-full"
        onClick={addStep}
      >
        <i className="bi bi-plus-lg mr-1" />
        Добавить шаг
      </button>
    </div>
      </SortableContext>
    </DndContext>
  );
}
