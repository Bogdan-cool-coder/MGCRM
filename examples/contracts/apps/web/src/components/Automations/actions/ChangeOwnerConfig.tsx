"use client";

import { CHANGE_OWNER_RULE_OPTIONS, USER_ROLE_OPTIONS } from "@/lib/automationConfig";

interface Props {
  config: Record<string, unknown>;
  onChange: (next: Record<string, unknown>) => void;
}

function getRoles(config: Record<string, unknown>): string[] {
  const v = config.roles;
  if (Array.isArray(v)) {
    return v.filter((r): r is string => typeof r === "string");
  }
  return [];
}

/**
 * Конфиг change_owner.
 * Правило распределения + фильтр pool'а пользователей (роли, отдел, только активные).
 */
export function ChangeOwnerConfig({ config, onChange }: Props) {
  const rule =
    typeof config.rule === "string" ? config.rule : "round_robin";
  const roles = getRoles(config);
  const department =
    typeof config.department === "string" ? config.department : "";
  const isActiveOnly =
    typeof config.is_active === "boolean" ? config.is_active : true;

  const selectedRuleMeta = CHANGE_OWNER_RULE_OPTIONS.find((r) => r.value === rule);

  function toggleRole(roleValue: string) {
    const next = roles.includes(roleValue)
      ? roles.filter((r) => r !== roleValue)
      : [...roles, roleValue];
    onChange({ ...config, roles: next });
  }

  return (
    <div className="space-y-4">
      <div>
        <label className="label">
          Правило распределения <span className="text-danger">*</span>
        </label>
        <div className="flex flex-col gap-2 mt-1">
          {CHANGE_OWNER_RULE_OPTIONS.map((opt) => (
            <label key={opt.value} className="flex items-start gap-2 cursor-pointer">
              <input
                type="radio"
                name="change_owner_rule"
                value={opt.value}
                checked={rule === opt.value}
                onChange={() => onChange({ ...config, rule: opt.value })}
                className="mt-0.5"
              />
              <span className="text-sm">
                <span className="font-medium">{opt.label}</span>
                {" — "}
                <span className="text-gray-500">{opt.hint}</span>
              </span>
            </label>
          ))}
        </div>
        {selectedRuleMeta && (
          <div className="text-xs text-gray-500 mt-1">{selectedRuleMeta.hint}</div>
        )}
      </div>

      <div className="border-t border-gray-200 pt-4 space-y-3">
        <div className="text-sm font-medium text-gray-700">Фильтр pool&apos;а пользователей</div>

        <div>
          <label className="label">Роли (пусто = все)</label>
          <div className="flex flex-wrap gap-3 mt-1">
            {USER_ROLE_OPTIONS.map((opt) => (
              <label key={opt.value} className="flex items-center gap-1.5 cursor-pointer">
                <input
                  type="checkbox"
                  checked={roles.includes(opt.value)}
                  onChange={() => toggleRole(opt.value)}
                />
                <span className="text-sm">{opt.label}</span>
              </label>
            ))}
          </div>
        </div>

        <div>
          <label className="label">Отдел</label>
          <input
            className="input"
            type="text"
            value={department}
            onChange={(e) => onChange({ ...config, department: e.target.value })}
            placeholder="Фильтр по отделу (опц.)"
          />
          <div className="text-xs text-gray-500 mt-1">
            Текстовое совпадение с полем department пользователя. Пусто = все отделы.
          </div>
        </div>

        <label className="flex items-center gap-2 cursor-pointer">
          <input
            type="checkbox"
            checked={isActiveOnly}
            onChange={(e) => onChange({ ...config, is_active: e.target.checked })}
          />
          <span className="text-sm">Только активные</span>
        </label>
      </div>
    </div>
  );
}
