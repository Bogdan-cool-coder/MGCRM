"use client";

import { useState, useEffect } from "react";
import useSWR, { mutate } from "swr";
import { Modal } from "@/components/Modal";
import { api, fetcher } from "@/lib/api";
import type {
  FinApprovalScenario,
  FinScenarioStage,
  FinLegalEntity,
  FinOpType,
  User,
} from "@/lib/types";

interface LocalStage {
  name: string;
  user_ids: number[];
  min_required: number;
  mode: "any" | "all";
}

interface Props {
  open: boolean;
  scenario: FinApprovalScenario | null; // null = create mode
  onClose: () => void;
}

const APPLIES_TO_OPTIONS = [
  { value: "operation", label: "Операция" },
  { value: "registry", label: "Реестр" },
  { value: "request", label: "Заявка" },
  { value: "invoice", label: "Счёт" },
];

function emptyStage(): LocalStage {
  return { name: "", user_ids: [], min_required: 1, mode: "any" };
}

export function ApprovalScenarioEditor({ open, scenario, onClose }: Props) {
  const [name, setName] = useState("");
  const [appliesTo, setAppliesTo] = useState<string>("request");
  const [opTypeId, setOpTypeId] = useState("");
  const [legalEntityId, setLegalEntityId] = useState("");
  const [minAmount, setMinAmount] = useState("");
  const [maxAmount, setMaxAmount] = useState("");
  const [priority, setPriority] = useState("0");
  const [isActive, setIsActive] = useState(true);
  const [stages, setStages] = useState<LocalStage[]>([emptyStage()]);
  const [error, setError] = useState("");
  const [submitting, setSubmitting] = useState(false);

  const { data: legalEntities } = useSWR<FinLegalEntity[]>("/api/finance/legal-entities", fetcher);
  const { data: opTypes } = useSWR<FinOpType[]>("/api/finance/op-types", fetcher);
  const { data: users } = useSWR<User[]>("/api/users", fetcher);

  const usersList = users ?? [];

  useEffect(() => {
    if (!open) return;
    if (scenario) {
      setName(scenario.name);
      setAppliesTo(scenario.applies_to);
      setOpTypeId(scenario.op_type_id ? String(scenario.op_type_id) : "");
      setLegalEntityId(scenario.legal_entity_id ? String(scenario.legal_entity_id) : "");
      setMinAmount(scenario.min_amount ?? "");
      setMaxAmount(scenario.max_amount ?? "");
      setPriority(String(scenario.priority));
      setIsActive(scenario.is_active);
      if (scenario.stages.length > 0) {
        setStages(
          scenario.stages.map((s) => ({
            name: s.name,
            user_ids: s.user_ids,
            min_required: s.min_required,
            mode: s.mode,
          }))
        );
      } else {
        setStages([emptyStage()]);
      }
    } else {
      setName("");
      setAppliesTo("request");
      setOpTypeId("");
      setLegalEntityId("");
      setMinAmount("");
      setMaxAmount("");
      setPriority("0");
      setIsActive(true);
      setStages([emptyStage()]);
    }
    setError("");
  }, [open, scenario]);

  function updateStage(idx: number, patch: Partial<LocalStage>) {
    setStages((prev) =>
      prev.map((s, i) => {
        if (i !== idx) return s;
        const next = { ...s, ...patch };
        // Auto-clamp min_required
        if (next.user_ids.length > 0 && next.min_required > next.user_ids.length) {
          next.min_required = next.user_ids.length;
        }
        if (next.min_required < 1) next.min_required = 1;
        return next;
      })
    );
  }

  function addStage() {
    setStages((prev) => [...prev, emptyStage()]);
  }

  function removeStage(idx: number) {
    setStages((prev) => prev.filter((_, i) => i !== idx));
  }

  function moveStage(idx: number, dir: -1 | 1) {
    const next = [...stages];
    const target = idx + dir;
    if (target < 0 || target >= next.length) return;
    [next[idx], next[target]] = [next[target], next[idx]];
    setStages(next);
  }

  function toggleUser(stageIdx: number, userId: number) {
    const s = stages[stageIdx];
    const exists = s.user_ids.includes(userId);
    const newIds = exists
      ? s.user_ids.filter((id) => id !== userId)
      : [...s.user_ids, userId];
    updateStage(stageIdx, { user_ids: newIds });
  }

  function validate(): string | null {
    if (!name.trim()) return "Укажи название сценария";
    if (!appliesTo) return "Укажи тип объекта";
    if (stages.length === 0) return "Добавь хотя бы один этап";
    for (let i = 0; i < stages.length; i++) {
      const s = stages[i];
      if (!s.name.trim()) return `Этап #${i + 1}: укажи название`;
      if (s.user_ids.length === 0) return `Этап #${i + 1}: нужен хотя бы один согласант`;
      if (s.min_required < 1 || s.min_required > s.user_ids.length)
        return `Этап #${i + 1}: минимум голосов должен быть от 1 до ${s.user_ids.length}`;
    }
    if (minAmount && maxAmount && parseFloat(minAmount) > parseFloat(maxAmount)) {
      return "Сумма «от» не может быть больше суммы «до»";
    }
    return null;
  }

  async function handleSubmit() {
    const validationError = validate();
    if (validationError) {
      setError(validationError);
      return;
    }
    setError("");
    setSubmitting(true);

    const body = {
      name: name.trim(),
      applies_to: appliesTo,
      op_type_id: opTypeId ? parseInt(opTypeId) : null,
      legal_entity_id: legalEntityId ? parseInt(legalEntityId) : null,
      min_amount: minAmount ? parseFloat(minAmount) : null,
      max_amount: maxAmount ? parseFloat(maxAmount) : null,
      priority: parseInt(priority) || 0,
      is_active: isActive,
      stages: stages.map((s, idx) => ({
        order: idx,
        name: s.name,
        user_ids: s.user_ids,
        min_required: s.min_required,
        mode: s.mode,
      })) satisfies FinScenarioStage[],
    };

    try {
      if (scenario) {
        await api(`/api/finance/approval-scenarios/${scenario.id}`, {
          method: "PATCH",
          body,
        });
      } else {
        await api("/api/finance/approval-scenarios", { method: "POST", body });
      }
      await mutate(
        (key) =>
          typeof key === "string" && key.startsWith("/api/finance/approval-scenarios"),
        undefined,
        { revalidate: true }
      );
      onClose();
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message || "Не удалось сохранить сценарий");
      } else {
        setError("Не удалось сохранить сценарий");
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal
      open={open}
      title={scenario ? "Редактировать сценарий" : "Новый сценарий"}
      onClose={onClose}
      width="xl"
      footer={
        <>
          <button type="button" className="btn-ghost" onClick={onClose}>
            Отмена
          </button>
          <button
            type="button"
            className="btn-primary"
            disabled={submitting}
            onClick={handleSubmit}
          >
            {submitting ? "Сохранение..." : "Сохранить сценарий"}
          </button>
        </>
      }
    >
      <div className="space-y-6">
        {error && (
          <div className="text-danger text-sm p-3 bg-red-50 dark:bg-red-900/20 rounded">
            {error}
          </div>
        )}

        {/* Block 1: params */}
        <div className="border rounded-lg p-4 space-y-4 dark:border-gray-700">
          <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-300">Параметры применения</h3>

          <div>
            <label className="label">Название *</label>
            <input
              type="text"
              className="input"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="Например: Согласование CFO для заявок"
            />
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="label">Тип объекта *</label>
              <select className="input" value={appliesTo} onChange={(e) => setAppliesTo(e.target.value)}>
                {APPLIES_TO_OPTIONS.map((o) => (
                  <option key={o.value} value={o.value}>{o.label}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="label">Тип операции (необязательно)</label>
              <select className="input" value={opTypeId} onChange={(e) => setOpTypeId(e.target.value)}>
                <option value="">Все типы операций</option>
                {(opTypes ?? []).map((t) => (
                  <option key={t.id} value={String(t.id)}>{t.name}</option>
                ))}
              </select>
            </div>
          </div>

          <div>
            <label className="label">Юрлицо (необязательно)</label>
            <select
              className="input"
              value={legalEntityId}
              onChange={(e) => setLegalEntityId(e.target.value)}
            >
              <option value="">Все юрлица</option>
              {(legalEntities ?? []).map((le) => (
                <option key={le.id} value={String(le.id)}>{le.name}</option>
              ))}
            </select>
          </div>

          <div className="grid grid-cols-3 gap-3">
            <div>
              <label className="label">Сумма от</label>
              <input
                type="number"
                className="input"
                min={0}
                value={minAmount}
                onChange={(e) => setMinAmount(e.target.value)}
                placeholder="0"
              />
            </div>
            <div>
              <label className="label">Сумма до</label>
              <input
                type="number"
                className="input"
                min={0}
                value={maxAmount}
                onChange={(e) => setMaxAmount(e.target.value)}
                placeholder="∞"
              />
            </div>
            <div>
              <label className="label">Приоритет (выше = важнее)</label>
              <input
                type="number"
                className="input"
                value={priority}
                onChange={(e) => setPriority(e.target.value)}
              />
            </div>
          </div>

          <label className="flex items-center gap-2 cursor-pointer">
            <input
              type="checkbox"
              checked={isActive}
              onChange={(e) => setIsActive(e.target.checked)}
              className="w-4 h-4 rounded border-gray-300"
            />
            <span className="text-sm dark:text-gray-200">Сценарий активен</span>
          </label>
        </div>

        {/* Block 2: stages */}
        <div className="border rounded-lg p-4 space-y-4 dark:border-gray-700">
          <div className="flex items-center justify-between">
            <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-300">Этапы согласования</h3>
            <button type="button" className="btn-secondary text-sm" onClick={addStage}>
              <i className="bi bi-plus-lg mr-1" />
              Добавить этап
            </button>
          </div>

          {stages.map((stage, idx) => (
            <div key={idx} className="border rounded p-3 space-y-3 dark:border-gray-700">
              <div className="flex items-center gap-2 mb-1">
                <span className="text-xs font-semibold text-gray-400 dark:text-gray-500">Этап {idx + 1}</span>
                <div className="flex gap-1 ml-auto">
                  {idx > 0 && (
                    <button
                      type="button"
                      className="btn-ghost text-xs p-1"
                      onClick={() => moveStage(idx, -1)}
                      title="Переместить выше"
                    >
                      <i className="bi bi-arrow-up" />
                    </button>
                  )}
                  {idx < stages.length - 1 && (
                    <button
                      type="button"
                      className="btn-ghost text-xs p-1"
                      onClick={() => moveStage(idx, 1)}
                      title="Переместить ниже"
                    >
                      <i className="bi bi-arrow-down" />
                    </button>
                  )}
                  {stages.length > 1 && (
                    <button
                      type="button"
                      className="btn-ghost text-xs p-1 text-danger"
                      onClick={() => removeStage(idx)}
                      title="Удалить этап"
                    >
                      <i className="bi bi-trash" />
                    </button>
                  )}
                </div>
              </div>

              <div>
                <label className="label">Название этапа *</label>
                <input
                  type="text"
                  className="input"
                  value={stage.name}
                  onChange={(e) => updateStage(idx, { name: e.target.value })}
                  placeholder="Например: Согласование CFO"
                />
              </div>

              <div>
                <label className="label">Согласанты *</label>
                <div className="border rounded p-2 max-h-40 overflow-y-auto space-y-1 dark:border-gray-700 bg-white dark:bg-gray-800">
                  {usersList.length === 0 && (
                    <p className="text-xs text-gray-400 dark:text-gray-500">Загрузка…</p>
                  )}
                  {usersList.map((u) => (
                    <label key={u.id} className="flex items-center gap-2 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 px-1 rounded">
                      <input
                        type="checkbox"
                        checked={stage.user_ids.includes(u.id)}
                        onChange={() => toggleUser(idx, u.id)}
                        className="w-4 h-4 rounded"
                      />
                      <span className="text-sm dark:text-gray-200">{u.full_name}</span>
                      <span className="text-xs text-gray-400 dark:text-gray-500 ml-auto">{u.role}</span>
                    </label>
                  ))}
                </div>
                {stage.user_ids.length > 0 && (
                  <p className="text-xs text-gray-400 dark:text-gray-500 mt-1">
                    Выбрано: {stage.user_ids.length}
                  </p>
                )}
              </div>

              <div>
                <label className="label">Режим</label>
                <div className="flex gap-4">
                  <label className="flex items-center gap-2 cursor-pointer">
                    <input
                      type="radio"
                      name={`mode-${idx}`}
                      checked={stage.mode === "any"}
                      onChange={() => updateStage(idx, { mode: "any" })}
                    />
                    <span className="text-sm dark:text-gray-200">Достаточно одного</span>
                  </label>
                  <label className="flex items-center gap-2 cursor-pointer">
                    <input
                      type="radio"
                      name={`mode-${idx}`}
                      checked={stage.mode === "all"}
                      onChange={() => updateStage(idx, { mode: "all" })}
                    />
                    <span className="text-sm dark:text-gray-200">Нужны все</span>
                  </label>
                </div>
              </div>

              {stage.mode === "any" && stage.user_ids.length > 1 && (
                <div>
                  <label className="label">Минимальное число голосов</label>
                  <input
                    type="number"
                    className="input w-24"
                    min={1}
                    max={stage.user_ids.length}
                    value={stage.min_required}
                    onChange={(e) => {
                      const v = parseInt(e.target.value) || 1;
                      updateStage(idx, {
                        min_required: Math.min(Math.max(v, 1), stage.user_ids.length),
                      });
                    }}
                  />
                </div>
              )}
            </div>
          ))}
        </div>
      </div>
    </Modal>
  );
}
