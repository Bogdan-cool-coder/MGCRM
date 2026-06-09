"use client";

import { useTheme } from "@/contexts/ThemeContext";
import type { ThemePreference } from "@/lib/theme";

/** Cycles: system → light → dark → system */
function nextTheme(current: ThemePreference): ThemePreference {
  if (current === "system") return "light";
  if (current === "light") return "dark";
  return "system";
}

function themeIcon(theme: ThemePreference): string {
  if (theme === "dark") return "bi-moon-stars-fill";
  if (theme === "light") return "bi-sun-fill";
  return "bi-circle-half";
}

function themeLabel(theme: ThemePreference): string {
  if (theme === "dark") return "Тёмная тема";
  if (theme === "light") return "Светлая тема";
  return "Системная тема";
}

export function ThemeToggle() {
  const { theme, setTheme } = useTheme();

  return (
    <button
      type="button"
      className="relative p-2 rounded-md hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-600 dark:text-gray-400 transition-colors"
      title="Переключить тему"
      aria-label={themeLabel(theme)}
      onClick={() => setTheme(nextTheme(theme))}
    >
      <i className={`bi ${themeIcon(theme)} text-lg`} />
    </button>
  );
}
