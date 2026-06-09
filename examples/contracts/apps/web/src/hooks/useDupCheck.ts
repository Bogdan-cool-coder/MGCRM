"use client";

import { useEffect, useRef, useState } from "react";
import { api } from "@/lib/api";

export interface DupMatch {
  id: number;
  display_name: string;
  entity_type: string;
}

interface DupCheckResponse {
  matches: DupMatch[];
}

function isDupCheckResponse(v: unknown): v is DupCheckResponse {
  return (
    typeof v === "object" &&
    v !== null &&
    "matches" in v &&
    Array.isArray((v as DupCheckResponse).matches)
  );
}

export interface UseDupCheckResult {
  matches: DupMatch[];
  checking: boolean;
  dismissed: boolean;
  dismiss: () => void;
  reset: () => void;
  check: (value: string) => void;
}

export function useDupCheck(
  entityType: string,
  field: "email" | "phone" | "bin" | "tax_id"
): UseDupCheckResult {
  const [matches, setMatches] = useState<DupMatch[]>([]);
  const [checking, setChecking] = useState(false);
  const [dismissed, setDismissed] = useState(false);
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  // Монотонный счётчик для защиты от out-of-order ответов
  const seqRef = useRef(0);
  // Флаг размонтирования — guard от setState после unmount
  const mountedRef = useRef(true);

  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
    };
  }, []);

  function check(value: string) {
    if (timerRef.current) {
      clearTimeout(timerRef.current);
    }

    if (value.trim().length < 3 || dismissed) {
      return;
    }

    timerRef.current = setTimeout(async () => {
      const seq = ++seqRef.current;
      if (mountedRef.current) setChecking(true);
      try {
        // map "bin" -> "tax_id" for backend compat
        const backendField = field === "bin" ? "tax_id" : field;
        const result = await api<unknown>(
          `/duplicates/check?entity_type=${encodeURIComponent(entityType)}&field=${encodeURIComponent(backendField)}&value=${encodeURIComponent(value)}`
        );
        // Игнорируем устаревший ответ
        if (seq !== seqRef.current || !mountedRef.current) return;
        if (isDupCheckResponse(result)) {
          setMatches(result.matches);
        }
      } catch {
        // silent — don't block form on network error
      } finally {
        if (seq === seqRef.current && mountedRef.current) {
          setChecking(false);
        }
      }
    }, 500);
  }

  function dismiss() {
    setDismissed(true);
    setMatches([]);
    if (timerRef.current) {
      clearTimeout(timerRef.current);
    }
  }

  function reset() {
    setDismissed(false);
    setMatches([]);
    setChecking(false);
    if (timerRef.current) {
      clearTimeout(timerRef.current);
    }
  }

  return { matches, checking, dismissed, dismiss, reset, check };
}
