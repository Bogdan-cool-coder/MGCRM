"use client";

import {
  DATE_FIELD_SUPPORTED_TARGET_TYPES,
  DATE_FIELD_WHITELIST,
  TARGET_TYPE_LABELS,
} from "@/lib/automationConfig";
import type { AutomationTargetType } from "@/lib/types";

interface Props {
  config: Record<string, unknown>;
  onChange: (next: Record<string, unknown>) => void;
}

/** Конфиг date_field_approaching. field из whitelist + days + target_type. */
export function DateFieldApproachingConfig({ config, onChange }: Props) {
  const targetType = (
    typeof config.target_type === "string" ? config.target_type : "subscription"
  ) as AutomationTargetType;
  const days = typeof config.days === "number"
    ? config.days
    : (typeof config.days === "string" ? Number(config.days) || 7 : 7);
  const field = typeof config.field === "string" ? config.field : "";

  const fieldOptions = DATE_FIELD_WHITELIST[targetType] ?? [];

  return (
    <div className="space-y-3">
      <div>
        <label className="label">Тип цели</label>
        <select
          className="input"
          value={targetType}
          onChange={(e) => {
            const nextTarget = e.target.value;
            // При смене target_type сбрасываем field — whitelist может не совпасть
            onChange({ ...config, target_type: nextTarget, field: "" });
          }}
        >
          {DATE_FIELD_SUPPORTED_TARGET_TYPES.map((t) => (
            <option key={t} value={t}>{TARGET_TYPE_LABELS[t]}</option>
          ))}
        </select>
        <div className="text-xs text-gray-500 mt-1">
          В MVP date_field работает только с подписками.
        </div>
      </div>
      <div>
        <label className="label">Поле даты</label>
        <select
          className="input"
          value={field}
          onChange={(e) => onChange({ ...config, field: e.target.value })}
        >
          <option value="">— выберите поле —</option>
          {fieldOptions.map((f) => (
            <option key={f.value} value={f.value}>{f.label}</option>
          ))}
        </select>
        <div className="text-xs text-gray-500 mt-1">
          Whitelist полей синхронизирован с backend (`run_date_field_scanner`).
        </div>
      </div>
      <div>
        <label className="label">За сколько дней до даты</label>
        <input
          type="number"
          min={0}
          className="input"
          value={days}
          onChange={(e) => onChange({ ...config, days: Math.max(0, Number(e.target.value) || 0) })}
        />
        <div className="text-xs text-gray-500 mt-1">
          Окно срабатывания: <strong>±1 день</strong> к дате `сегодня + N`. Defaults: 7.
        </div>
      </div>
    </div>
  );
}
