"use client";

import { SET_FIELD_WHITELIST, TARGET_TYPE_LABELS } from "@/lib/automationConfig";
import type { AutomationTargetType } from "@/lib/types";

interface Props {
  config: Record<string, unknown>;
  onChange: (next: Record<string, unknown>) => void;
  /**
   * Target type для подбора whitelist'а. Определяется по триггеру:
   * - on_enter_stage → по pipeline.kind (передаётся снаружи)
   * - idle_in_stage_days → trigger_config.target_type
   * - date_field_approaching → trigger_config.target_type
   */
  targetType: AutomationTargetType;
}

/** Конфиг set_field. field из whitelist + value (текст). */
export function SetFieldConfig({ config, onChange, targetType }: Props) {
  const field = typeof config.field === "string" ? config.field : "";
  const value = typeof config.value === "string" ? config.value : (config.value == null ? "" : String(config.value));

  const fields = SET_FIELD_WHITELIST[targetType] ?? [];

  return (
    <div className="space-y-3">
      <div>
        <label className="label">Поле для изменения</label>
        <select
          className="input"
          value={field}
          onChange={(e) => onChange({ ...config, field: e.target.value })}
        >
          <option value="">— выберите поле —</option>
          {fields.map((f) => (
            <option key={f.value} value={f.value}>{f.label}</option>
          ))}
        </select>
        <div className="text-xs text-gray-500 mt-1">
          Whitelist для <strong>{TARGET_TYPE_LABELS[targetType]}</strong>. Sync с backend
          (`SET_FIELD_WHITELIST` в `automation_executor.py`). Чувствительные поля
          (stage, owner, amount) не разрешены — для них есть отдельные endpoint'ы.
        </div>
      </div>

      <div>
        <label className="label">Новое значение</label>
        <input
          className="input"
          type="text"
          value={value}
          onChange={(e) => onChange({ ...config, value: e.target.value })}
          placeholder="Например: «Помечено автоматизацией»"
        />
        <div className="text-xs text-gray-500 mt-1">
          Передаётся как строка. Для health_tier — A1..A6 / C0; для status (lead) — active/converted/archived/lost.
        </div>
      </div>
    </div>
  );
}
