"use client";

import { DatePicker } from "@/components/ui/DatePicker";

type RecurrenceRuleValue = "none" | "daily" | "weekly" | "monthly";

interface RecurrenceSelectorProps {
  rule: RecurrenceRuleValue;
  until: string | null;
  onChange: (rule: RecurrenceRuleValue, until: string | null) => void;
}

function endOfNextYear(): string {
  const year = new Date().getFullYear() + 1;
  return `${year}-12-31`;
}

export function RecurrenceSelector({ rule, until, onChange }: RecurrenceSelectorProps) {
  const maxDate = endOfNextYear();
  const isOverMax = rule !== "none" && until != null && until > maxDate;

  return (
    <div className="space-y-3">
      <div>
        <label className="label">Повторение</label>
        <select
          className="input"
          value={rule}
          onChange={(e) => onChange(e.target.value as RecurrenceRuleValue, until)}
        >
          <option value="none">Нет повторений</option>
          <option value="daily">Ежедневно</option>
          <option value="weekly">Еженедельно</option>
          <option value="monthly">Ежемесячно</option>
        </select>
      </div>

      {rule !== "none" && (
        <div className="flex items-center gap-3">
          <span className="text-sm text-gray-600 dark:text-gray-400">до</span>
          <DatePicker
            value={until}
            onChange={(v) => onChange(rule, v)}
            maxDate={maxDate}
            className="w-auto"
          />
        </div>
      )}

      {rule !== "none" && (
        <p className="text-xs text-gray-500 dark:text-gray-400">
          При повторении создаются отдельные независимые копии задачи.
          Изменение одной не затрагивает другие.
        </p>
      )}

      {isOverMax && (
        <p className="text-xs text-warning">
          <i className="bi bi-exclamation-triangle mr-1" />
          Максимальный период — до конца следующего года.
        </p>
      )}
    </div>
  );
}
