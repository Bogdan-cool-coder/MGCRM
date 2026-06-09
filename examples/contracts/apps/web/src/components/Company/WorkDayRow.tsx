"use client";

import type { WorkSchedule } from "@/lib/types";

const DAY_NAMES: Record<number, string> = {
  1: "Понедельник",
  2: "Вторник",
  3: "Среда",
  4: "Четверг",
  5: "Пятница",
  6: "Суббота",
  7: "Воскресенье",
};

interface Props {
  schedule: WorkSchedule;
  onChange: (updated: WorkSchedule) => void;
}

export function WorkDayRow({ schedule, onChange }: Props) {
  function update(partial: Partial<WorkSchedule>) {
    onChange({ ...schedule, ...partial });
  }

  const disabled = !schedule.is_working;

  return (
    <tr className="border-t border-gray-100 dark:border-gray-700">
      <td className="px-4 py-2 w-32 text-sm text-gray-700 dark:text-gray-300 font-medium">
        {DAY_NAMES[schedule.day_of_week]}
      </td>
      <td className="px-4 py-2 text-center">
        <input
          type="checkbox"
          checked={schedule.is_working}
          onChange={(e) => {
            if (!e.target.checked) {
              update({ is_working: false, start_time: null, end_time: null, meeting_slot_min: null });
            } else {
              update({ is_working: true, start_time: "09:00", end_time: "18:00", meeting_slot_min: 30 });
            }
          }}
          className="cursor-pointer"
        />
      </td>
      <td className="px-4 py-2">
        <input
          type="time"
          className={`input w-28 ${disabled ? "opacity-40 cursor-not-allowed" : ""}`}
          value={schedule.start_time ?? ""}
          onChange={(e) => update({ start_time: e.target.value })}
          disabled={disabled}
        />
      </td>
      <td className="px-4 py-2">
        <input
          type="time"
          className={`input w-28 ${disabled ? "opacity-40 cursor-not-allowed" : ""}`}
          value={schedule.end_time ?? ""}
          onChange={(e) => update({ end_time: e.target.value })}
          disabled={disabled}
        />
      </td>
      <td className="px-4 py-2">
        <input
          type="number"
          min={15}
          max={120}
          step={15}
          className={`input w-20 ${disabled ? "opacity-40 cursor-not-allowed" : ""}`}
          value={schedule.meeting_slot_min ?? ""}
          onChange={(e) => update({ meeting_slot_min: e.target.value ? Number(e.target.value) : null })}
          disabled={disabled}
        />
      </td>
    </tr>
  );
}
