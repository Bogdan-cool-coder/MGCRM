"use client";

import { useEffect, useRef, useState } from "react";

export type AutoSaveStatus = "idle" | "saving" | "saved" | "error";

/**
 * Auto-save через debounce. Дёргает saveFn(value) когда value меняется и не меняется N миллисекунд.
 * Возвращает status и lastError для UI индикатора.
 */
export function useAutoSave<T>(
  value: T,
  saveFn: (v: T) => Promise<void>,
  options: { debounceMs?: number; enabled?: boolean } = {},
) {
  const { debounceMs = 1500, enabled = true } = options;
  const [status, setStatus] = useState<AutoSaveStatus>("idle");
  const [lastError, setLastError] = useState<string | null>(null);
  const initialRef = useRef<string | null>(null);
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    if (!enabled) return;
    const serialized = JSON.stringify(value);
    if (initialRef.current === null) {
      initialRef.current = serialized;
      return;
    }
    if (serialized === initialRef.current) return;

    if (timerRef.current) clearTimeout(timerRef.current);
    timerRef.current = setTimeout(async () => {
      setStatus("saving");
      try {
        await saveFn(value);
        initialRef.current = serialized;
        setStatus("saved");
        setLastError(null);
        setTimeout(() => setStatus(s => s === "saved" ? "idle" : s), 2000);
      } catch (err) {
        setStatus("error");
        setLastError(err instanceof Error ? err.message : "Save failed");
      }
    }, debounceMs);

    return () => {
      if (timerRef.current) clearTimeout(timerRef.current);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [JSON.stringify(value), enabled]);

  return { status, lastError };
}
