"use client";

import { useState, useCallback } from "react";
import type { SearchHistoryItem } from "@/lib/types";

const STORAGE_KEY = "crm_search_history";
const MAX_ITEMS = 5;

function readHistory(): SearchHistoryItem[] {
  if (typeof window === "undefined") return [];
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return [];
    const parsed: unknown = JSON.parse(raw);
    if (!Array.isArray(parsed)) return [];
    return parsed as SearchHistoryItem[];
  } catch {
    return [];
  }
}

function writeHistory(items: SearchHistoryItem[]) {
  if (typeof window === "undefined") return;
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(items));
  } catch {
    // storage full or unavailable
  }
}

export function useSearchHistory() {
  const [history, setHistory] = useState<SearchHistoryItem[]>(() => readHistory());

  const push = useCallback((item: SearchHistoryItem) => {
    setHistory((prev) => {
      // Remove existing entry with same entity_type + id
      const filtered = prev.filter(
        (h) => !(h.entity_type === item.entity_type && h.id === item.id),
      );
      const next = [item, ...filtered].slice(0, MAX_ITEMS);
      writeHistory(next);
      return next;
    });
  }, []);

  const clear = useCallback(() => {
    setHistory([]);
    writeHistory([]);
  }, []);

  return { history, push, clear };
}
