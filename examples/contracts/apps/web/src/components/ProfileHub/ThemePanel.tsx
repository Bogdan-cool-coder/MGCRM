"use client";

import { useState } from "react";
import { useTheme } from "@/contexts/ThemeContext";
import type { ThemePreference } from "@/lib/theme";

interface ThemeOption {
  value: ThemePreference;
  label: string;
  description: string;
  icon: string;
}

const OPTIONS: ThemeOption[] = [
  {
    value: "light",
    label: "Светлая",
    description: "Всегда светлый интерфейс",
    icon: "bi-sun-fill",
  },
  {
    value: "dark",
    label: "Тёмная",
    description: "Комфортно при слабом освещении",
    icon: "bi-moon-stars-fill",
  },
  {
    value: "system",
    label: "Системная",
    description: "Следует настройке ОС",
    icon: "bi-circle-half",
  },
];

export function ThemePanel() {
  const { theme, setTheme } = useTheme();
  const [selected, setSelected] = useState<ThemePreference>(theme);
  const [saved, setSaved] = useState(false);

  function handleSelect(value: ThemePreference) {
    setSelected(value);
    setTheme(value);
    setSaved(false);
  }

  function handleSave() {
    setTheme(selected);
    setSaved(true);
  }

  return (
    <div className="p-6 max-w-lg">
      <div className="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-elev-1 p-6 space-y-6">
        <div>
          <h2 className="text-h4 dark:text-gray-100 mb-1">Тема интерфейса</h2>
          <p className="text-sm text-gray-500 dark:text-gray-400">
            Выбери, как выглядит MACRO CRM на твоём устройстве.
          </p>
        </div>

        <div className="flex flex-col gap-3">
          {OPTIONS.map((opt) => {
            const active = selected === opt.value;
            return (
              <label
                key={opt.value}
                className={
                  "flex items-center gap-4 p-4 rounded-lg border-2 cursor-pointer transition-colors " +
                  (active
                    ? "border-primary bg-primary/5 dark:bg-primary/10 dark:border-primary-light"
                    : "border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600")
                }
              >
                <input
                  type="radio"
                  name="theme"
                  value={opt.value}
                  checked={active}
                  onChange={() => handleSelect(opt.value)}
                  className="accent-primary"
                />
                <i className={`bi ${opt.icon} text-xl text-gray-600 dark:text-gray-400`} />
                <div className="flex-1 min-w-0">
                  <div className="text-sm font-medium text-primary dark:text-gray-100">
                    {opt.label}
                  </div>
                  <div className="text-xs text-gray-500 dark:text-gray-400">{opt.description}</div>
                </div>
              </label>
            );
          })}
        </div>

        {saved && (
          <div className="text-sm rounded-md px-3 py-2 bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-500">
            Тема сохранена
          </div>
        )}

        <div className="flex justify-end">
          <button type="button" className="btn-primary" onClick={handleSave}>
            Сохранить
          </button>
        </div>
      </div>
    </div>
  );
}
