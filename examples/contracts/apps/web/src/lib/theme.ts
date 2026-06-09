export type ThemePreference = "light" | "dark" | "system";

const STORAGE_KEY = "crm_theme";

/** Resolves effective theme (light or dark) based on preference and system setting */
export function resolveTheme(
  pref: ThemePreference,
  systemDark: boolean,
): "light" | "dark" {
  if (pref === "system") return systemDark ? "dark" : "light";
  return pref;
}

/** Applies (or removes) the 'dark' class on <html> */
export function applyTheme(pref: ThemePreference): void {
  if (typeof document === "undefined") return;
  const systemDark = window.matchMedia("(prefers-color-scheme: dark)").matches;
  const effective = resolveTheme(pref, systemDark);
  if (effective === "dark") {
    document.documentElement.classList.add("dark");
  } else {
    document.documentElement.classList.remove("dark");
  }
}

export function saveThemeToStorage(pref: ThemePreference): void {
  try {
    localStorage.setItem(STORAGE_KEY, pref);
  } catch {
    /* ignore storage errors */
  }
}

export function loadThemeFromStorage(): ThemePreference | null {
  try {
    const v = localStorage.getItem(STORAGE_KEY);
    if (v === "light" || v === "dark" || v === "system") return v;
  } catch {
    /* ignore */
  }
  return null;
}
