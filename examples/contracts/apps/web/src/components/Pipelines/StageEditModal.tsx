"use client";

import { useState } from "react";
import { Modal } from "@/components/Modal";
import { api, ApiError } from "@/lib/api";
import { TASK_TYPES } from "@/lib/types";
import type { PipelineStage } from "@/lib/types";

interface StageEditModalProps {
  stage: PipelineStage;
  pipelineId: number;
  onClose: () => void;
  onSaved: () => void;
}

function toggle<T>(arr: T[], v: T): T[] {
  return arr.includes(v) ? arr.filter((x) => x !== v) : [...arr, v];
}

export function StageEditModal({ stage, pipelineId, onClose, onSaved }: StageEditModalProps) {
  const [name, setName] = useState(stage.name);
  const [color, setColor] = useState(stage.color ?? "#2B4987");
  const [code, setCode] = useState(stage.code ?? "");
  const [description, setDescription] = useState(stage.description ?? "");
  const [isWon, setIsWon] = useState(stage.is_won);
  const [isLost, setIsLost] = useState(stage.is_lost);
  const [isActive, setIsActive] = useState(stage.is_active);
  const [slaHours, setSlaHours] = useState(stage.sla_hours ?? 0);
  const [taskTypes, setTaskTypes] = useState<string[]>(stage.task_types ?? []);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSave() {
    if (!name.trim()) {
      setError("Название обязательно");
      return;
    }
    setSaving(true);
    setError(null);
    try {
      await api(`/pipelines/${pipelineId}/stages/${stage.id}`, {
        method: "PATCH",
        body: {
          name: name.trim(),
          color: color || null,
          code: code.trim() || null,
          description: description.trim() || null,
          is_won: isWon,
          is_lost: isLost,
          is_active: isActive,
          sla_hours: slaHours || null,
          task_types: taskTypes,
        },
      });
      onSaved();
      onClose();
    } catch (err) {
      setError(
        err instanceof ApiError
          ? String((err.detail as { detail?: string })?.detail ?? err.message)
          : "Не удалось сохранить",
      );
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      open
      title={`Настройки этапа: ${stage.name}`}
      onClose={onClose}
      footer={
        <>
          <button onClick={onClose} className="btn-ghost">Отмена</button>
          <button onClick={handleSave} disabled={saving || !name.trim()} className="btn-primary disabled:opacity-50">
            {saving ? "Сохранение…" : "Сохранить"}
          </button>
        </>
      }
    >
      <div className="space-y-4">
        {/* Название */}
        <div>
          <label className="label">Название <span className="text-danger">*</span></label>
          <input
            className="input"
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder="Название этапа"
          />
        </div>

        {/* Цвет и Код */}
        <div className="grid grid-cols-2 gap-3">
          <div>
            <label className="label">Цвет</label>
            <div className="flex gap-2 items-center">
              <input
                type="color"
                className="h-9 w-12 rounded border border-gray-300 cursor-pointer"
                value={color}
                onChange={(e) => setColor(e.target.value)}
              />
              <input
                className="input flex-1"
                value={color}
                onChange={(e) => setColor(e.target.value)}
                placeholder="#2B4987"
              />
            </div>
          </div>
          <div>
            <label className="label">Код этапа (B0/A1/C0 — воронка ЖЦ)</label>
            <input
              className="input"
              value={code}
              onChange={(e) => setCode(e.target.value)}
              placeholder="B0 / A1 / C0"
            />
          </div>
        </div>

        {/* Описание */}
        <div>
          <label className="label">Описание этапа</label>
          <textarea
            className="input"
            rows={3}
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            placeholder="Что должно произойти на этом этапе, чтобы сделка продвинулась дальше"
          />
        </div>

        {/* Флаги */}
        <div>
          <label className="label">Флаги</label>
          <div className="flex flex-col gap-1.5 text-sm">
            <label className="flex items-center gap-2 cursor-pointer">
              <input type="checkbox" checked={isWon} onChange={(e) => setIsWon(e.target.checked)} />
              <span>Финальный успешный этап</span>
              {isWon && <i className="bi bi-trophy text-success text-xs" />}
            </label>
            <label className="flex items-center gap-2 cursor-pointer">
              <input type="checkbox" checked={isLost} onChange={(e) => setIsLost(e.target.checked)} />
              <span>Финальный проигрышный этап</span>
              {isLost && <i className="bi bi-x-circle text-danger text-xs" />}
            </label>
            <label className="flex items-center gap-2 cursor-pointer">
              <input type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />
              <span>Этап активен</span>
            </label>
          </div>
        </div>

        {/* SLA */}
        <div>
          <label className="label">Предельное время на этапе (часов)</label>
          <div className="flex items-center gap-2">
            <input
              className="input w-32"
              type="number"
              min={0}
              value={slaHours}
              onChange={(e) => setSlaHours(Math.max(0, Number(e.target.value) || 0))}
            />
            <span className="text-sm text-gray-500">часов</span>
          </div>
          <div className="text-xs text-gray-400 mt-1">0 = без SLA</div>
        </div>

        {/* Типы задач */}
        <div>
          <label className="label">Типы задач на этапе</label>
          <div className="flex flex-wrap gap-2">
            {TASK_TYPES.map((t) => (
              <label
                key={t.value}
                className={`px-2 py-1 rounded border text-xs cursor-pointer ${
                  taskTypes.includes(t.value)
                    ? "bg-primary text-white border-primary"
                    : "border-gray-300"
                }`}
              >
                <input
                  type="checkbox"
                  className="hidden"
                  checked={taskTypes.includes(t.value)}
                  onChange={() => setTaskTypes(toggle(taskTypes, t.value))}
                />
                {t.label}
              </label>
            ))}
          </div>
        </div>

        {/* Категории задач — заглушка */}
        <div className="p-3 bg-gray-50 rounded-lg border border-gray-200">
          <div className="text-xs text-gray-500">
            <i className="bi bi-info-circle mr-1" />
            Категории задач на этапе появятся в Эпике 24.
          </div>
        </div>

        {error && (
          <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">{error}</div>
        )}
      </div>
    </Modal>
  );
}
