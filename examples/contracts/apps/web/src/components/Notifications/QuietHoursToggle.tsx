"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { api, errorMessage } from "@/lib/api";
import type { QuietHours } from "@/lib/types";

interface Props {
  quietHours: QuietHours;
  onUpdate: (updated: Partial<QuietHours>) => void;
}

export function QuietHoursToggle({ quietHours, onUpdate }: Props) {
  const [enabled, setEnabled] = useState(quietHours.enabled);
  const [start, setStart] = useState(quietHours.start ?? "08:00");
  const [end, setEnd] = useState(quietHours.end ?? "23:00");
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  // Sync from parent when quietHours prop changes
  useEffect(() => {
    setEnabled(quietHours.enabled);
    setStart(quietHours.start ?? "08:00");
    setEnd(quietHours.end ?? "23:00");
  }, [quietHours.enabled, quietHours.start, quietHours.end]);

  const saveToServer = useCallback(
    async (patch: Partial<QuietHours>) => {
      setSaving(true);
      setSaveError(null);
      try {
        await api("/me/notifications/quiet-hours", { method: "PATCH", body: patch });
        onUpdate(patch);
      } catch (err: unknown) {
        // Откатываем оптимистичный toggle, если меняли enabled, и показываем ошибку.
        if (typeof patch.enabled === "boolean") setEnabled(!patch.enabled);
        setSaveError(errorMessage(err, "Не удалось сохранить тихие часы"));
      } finally {
        setSaving(false);
      }
    },
    [onUpdate],
  );

  function handleToggle() {
    const next = !enabled;
    setEnabled(next);
    void saveToServer({ enabled: next });
  }

  function scheduleTimeUpdate(newStart: string, newEnd: string) {
    if (debounceRef.current) clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(() => {
      void saveToServer({ start: newStart, end: newEnd });
    }, 600);
  }

  function handleStartChange(value: string) {
    setStart(value);
    scheduleTimeUpdate(value, end);
  }

  function handleEndChange(value: string) {
    setEnd(value);
    scheduleTimeUpdate(start, value);
  }

  return (
    <div>
      <div className="flex items-center justify-between">
        <span className="text-sm font-medium text-gray-700 dark:text-gray-300">
          Тихие часы для Telegram
          {saving && (
            <i className="bi-arrow-clockwise animate-spin ml-2 text-gray-400 text-xs" />
          )}
        </span>
        {/* Toggle switch */}
        <label className="relative inline-flex items-center cursor-pointer">
          <input
            type="checkbox"
            className="sr-only peer"
            checked={enabled}
            onChange={handleToggle}
          />
          <div
            className="w-10 h-5 bg-gray-300 dark:bg-gray-600 rounded-full
                       peer-checked:bg-primary peer-focus:outline-none
                       transition-colors duration-200
                       after:content-[''] after:absolute after:top-0.5 after:left-0.5
                       after:bg-white after:rounded-full after:h-4 after:w-4
                       after:transition-all after:duration-200
                       peer-checked:after:translate-x-5"
          />
        </label>
      </div>

      {saveError && (
        <p className="text-danger text-xs mt-2">{saveError}</p>
      )}

      {enabled && (
        <div className="mt-3">
          <div className="flex items-center gap-3">
            <div className="flex items-center gap-2">
              <span className="text-sm text-gray-600 dark:text-gray-400">с</span>
              <input
                type="time"
                className="input w-28"
                value={start}
                onChange={(e) => handleStartChange(e.target.value)}
              />
            </div>
            <div className="flex items-center gap-2">
              <span className="text-sm text-gray-600 dark:text-gray-400">до</span>
              <input
                type="time"
                className="input w-28"
                value={end}
                onChange={(e) => handleEndChange(e.target.value)}
              />
            </div>
          </div>
          <p className="text-xs text-gray-400 dark:text-gray-500 mt-2">
            В это время уведомления не придут в Telegram. Они появятся утром в дайджесте.
          </p>
        </div>
      )}
    </div>
  );
}
