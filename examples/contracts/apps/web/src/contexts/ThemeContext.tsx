"use client";

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useRef,
  useState,
} from "react";
import { useMe } from "@/lib/auth";
import { api } from "@/lib/api";
import {
  applyTheme,
  loadThemeFromStorage,
  saveThemeToStorage,
  type ThemePreference,
} from "@/lib/theme";

interface ThemeContextValue {
  theme: ThemePreference;
  setTheme: (pref: ThemePreference) => void;
}

const ThemeContext = createContext<ThemeContextValue>({
  theme: "system",
  setTheme: () => {},
});

export function useTheme(): ThemeContextValue {
  return useContext(ThemeContext);
}

/** Debounces PATCH /users/me by 1 second — fire-and-forget */
function useDebouncedPatch() {
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  // Чистим таймер при размонтировании компонента
  useEffect(() => {
    return () => {
      if (timerRef.current) clearTimeout(timerRef.current);
    };
  }, []);

  return useCallback((pref: ThemePreference) => {
    if (timerRef.current) clearTimeout(timerRef.current);
    timerRef.current = setTimeout(() => {
      void api("/users/me", {
        method: "PATCH",
        body: { theme_preference: pref },
      }).catch(() => {
        /* fire-and-forget: ignore errors */
      });
    }, 1000);
  }, []);
}

export function ThemeProvider({ children }: { children: React.ReactNode }) {
  const { user } = useMe();
  const patchTheme = useDebouncedPatch();

  // Derive initial theme: localStorage > user.theme_preference > 'system'
  const [theme, setThemeState] = useState<ThemePreference>(() => {
    if (typeof window === "undefined") return "system";
    return loadThemeFromStorage() ?? "system";
  });

  // On mount: sync from user preference if localStorage is empty
  useEffect(() => {
    if (!user) return;
    const stored = loadThemeFromStorage();
    if (!stored && user.theme_preference) {
      const pref = user.theme_preference ?? "system";
      setThemeState(pref);
      applyTheme(pref);
      saveThemeToStorage(pref);
    }
  }, [user?.id]); // eslint-disable-line react-hooks/exhaustive-deps

  // Apply theme on mount (anti-flash script covers first paint, this covers SWR-loaded data)
  useEffect(() => {
    applyTheme(theme);
  }, [theme]);

  // System preference live listener
  useEffect(() => {
    if (theme !== "system") return;
    const mq = window.matchMedia("(prefers-color-scheme: dark)");
    function handleChange() {
      applyTheme("system");
    }
    mq.addEventListener("change", handleChange);
    return () => mq.removeEventListener("change", handleChange);
  }, [theme]);

  const setTheme = useCallback(
    (pref: ThemePreference) => {
      setThemeState(pref);
      saveThemeToStorage(pref);
      applyTheme(pref);
      patchTheme(pref);
    },
    [patchTheme],
  );

  return (
    <ThemeContext.Provider value={{ theme, setTheme }}>
      {children}
    </ThemeContext.Provider>
  );
}
