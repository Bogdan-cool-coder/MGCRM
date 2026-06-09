"use client";

import { IDLE_SUPPORTED_TARGET_TYPES, TARGET_TYPE_LABELS } from "@/lib/automationConfig";
import type { AutomationTargetType } from "@/lib/types";

interface Props {
  config: Record<string, unknown>;
  onChange: (next: Record<string, unknown>) => void;
}

/** Конфиг idle_in_stage_days. days: int (>=1, default 7), target_type: deal|lead. */
export function IdleInStageDaysConfig({ config, onChange }: Props) {
  const days = typeof config.days === "number" ? config.days : (typeof config.days === "string" ? Number(config.days) || 7 : 7);
  const targetType = (typeof config.target_type === "string" ? config.target_type : "deal") as AutomationTargetType;

  return (
    <div className="space-y-3">
      <div>
        <label className="label">Тип цели</label>
        <select
          className="input"
          value={targetType}
          onChange={(e) => onChange({ ...config, target_type: e.target.value })}
        >
          {IDLE_SUPPORTED_TARGET_TYPES.map((t) => (
            <option key={t} value={t}>{TARGET_TYPE_LABELS[t]}</option>
          ))}
        </select>
        <div className="text-xs text-gray-500 mt-1">Подписки в MVP не поддерживаются для этого триггера.</div>
      </div>
      <div>
        <label className="label">Сколько дней висит на этапе</label>
        <input
          type="number"
          min={1}
          className="input"
          value={days}
          onChange={(e) => onChange({ ...config, days: Math.max(1, Number(e.target.value) || 1) })}
        />
        <div className="text-xs text-gray-500 mt-1">
          Cron каждый час. Защита от повтора: за окно <strong>N дней</strong> сработает только один раз.
        </div>
      </div>
    </div>
  );
}
