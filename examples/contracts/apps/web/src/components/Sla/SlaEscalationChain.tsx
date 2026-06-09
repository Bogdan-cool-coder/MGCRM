"use client";

import type { EscalationLevel } from "@/lib/types";

interface Props {
  levels: EscalationLevel[];
  onChange: (levels: EscalationLevel[]) => void;
}

const MAX_LEVELS = 5;

const NOTIFY_OPTIONS: { value: EscalationLevel["notify"]; label: string }[] = [
  { value: "owner", label: "Ответственный" },
  { value: "manager", label: "Менеджер" },
];

export function SlaEscalationChain({ levels, onChange }: Props) {
  function addLevel() {
    if (levels.length >= MAX_LEVELS) return;
    const prevDays = levels.length > 0 ? levels[levels.length - 1].after_days : 0;
    onChange([...levels, { after_days: prevDays + 1, notify: "owner" }]);
  }

  function removeLevel(idx: number) {
    onChange(levels.filter((_, i) => i !== idx));
  }

  function updateLevel(idx: number, patch: Partial<EscalationLevel>) {
    onChange(levels.map((l, i) => (i === idx ? { ...l, ...patch } : l)));
  }

  function getError(idx: number): string | null {
    if (idx === 0) return null;
    if (levels[idx].after_days <= levels[idx - 1].after_days) {
      return `Срок уровня ${idx + 1} должен быть больше уровня ${idx}`;
    }
    return null;
  }

  return (
    <div className="space-y-3">
      <div className="text-sm font-semibold text-gray-700">Эскалационная цепочка</div>

      {levels.length === 0 && (
        <div className="rounded-lg border border-dashed border-gray-200 dark:border-gray-700 px-4 py-3 text-sm text-gray-400 dark:text-gray-500 text-center">
          Нет уровней эскалации — правило сработает один раз.
        </div>
      )}

      {levels.map((level, idx) => {
        const err = getError(idx);
        return (
          <div key={idx} className="space-y-1">
            <div className="flex items-center gap-2 flex-wrap">
              <span className="text-sm text-gray-600 shrink-0">Уровень {idx + 1}:</span>
              <span className="text-sm text-gray-600 shrink-0">Через</span>
              <input
                type="number"
                min={1}
                className="input text-sm py-1 w-16"
                value={level.after_days}
                onChange={(e) =>
                  updateLevel(idx, { after_days: Math.max(1, Number(e.target.value) || 1) })
                }
              />
              <span className="text-sm text-gray-600 shrink-0">дн. уведомить</span>
              <select
                className="input text-sm py-1"
                value={level.notify}
                onChange={(e) =>
                  updateLevel(idx, { notify: e.target.value as EscalationLevel["notify"] })
                }
              >
                {NOTIFY_OPTIONS.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </select>
              <button
                type="button"
                className="btn-ghost text-danger p-1 ml-auto"
                onClick={() => removeLevel(idx)}
                title="Удалить уровень"
              >
                <i className="bi bi-x-lg text-sm" />
              </button>
            </div>
            {err && (
              <div className="text-xs text-danger ml-20">{err}</div>
            )}
          </div>
        );
      })}

      {levels.length < MAX_LEVELS && (
        <button
          type="button"
          className="btn-secondary text-sm"
          onClick={addLevel}
        >
          <i className="bi bi-plus-lg mr-1" />+ Добавить уровень эскалации
        </button>
      )}
    </div>
  );
}
