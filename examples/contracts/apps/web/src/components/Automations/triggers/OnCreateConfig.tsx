"use client";

import { ON_CREATE_TARGET_TYPE_OPTIONS } from "@/lib/automationConfig";

interface Props {
  config: Record<string, unknown>;
  onChange: (next: Record<string, unknown>) => void;
}

/**
 * Конфиг триггера on_create.
 * Пользователь выбирает тип создаваемой сущности (lead / deal / inbound_message).
 * target_type сохраняется в trigger_config.target_type.
 */
export function OnCreateConfig({ config, onChange }: Props) {
  const targetType =
    typeof config.target_type === "string" ? config.target_type : "lead";

  return (
    <div className="space-y-3">
      <div className="text-xs text-gray-500 bg-gray-50 border border-gray-200 rounded-md p-3">
        <i className="bi bi-info-circle mr-1" />
        Триггер срабатывает в момент создания. Полезно для авто-распределения новых лидов из Inbox.
      </div>
      <div>
        <label className="label">
          Тип цели <span className="text-danger">*</span>
        </label>
        <div className="flex flex-wrap gap-4 mt-1">
          {ON_CREATE_TARGET_TYPE_OPTIONS.map((opt) => (
            <label key={opt.value} className="flex items-center gap-2 cursor-pointer">
              <input
                type="radio"
                name="on_create_target_type"
                value={opt.value}
                checked={targetType === opt.value}
                onChange={() => onChange({ ...config, target_type: opt.value })}
              />
              <span className="text-sm">{opt.label}</span>
            </label>
          ))}
        </div>
      </div>
    </div>
  );
}
