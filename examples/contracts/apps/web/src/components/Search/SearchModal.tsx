"use client";

import { useState, useEffect, useRef, useCallback } from "react";
import { createPortal } from "react-dom";
import { useRouter } from "next/navigation";
import { api } from "@/lib/api";
import {
  SEARCH_ENTITY_PATHS,
  SEARCH_ENTITY_ICONS,
  SEARCH_ENTITY_LABELS,
  type SearchResponse,
  type SearchResultItem as SearchResultItemType,
} from "@/lib/types";
import { SearchResultGroup } from "./SearchResultGroup";
import { useSearchHistory } from "./useSearchHistory";

interface SearchModalProps {
  open: boolean;
  onClose: () => void;
}

const DEBOUNCE_MS = 250;
const MIN_QUERY_LEN = 2;
const MAX_QUERY_LEN = 100;

export function SearchModal({ open, onClose }: SearchModalProps) {
  const router = useRouter();
  const inputRef = useRef<HTMLInputElement>(null);
  const [mounted, setMounted] = useState(false);

  const [query, setQuery] = useState("");
  const [results, setResults] = useState<SearchResponse | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [searchError, setSearchError] = useState<string | null>(null);
  const [selectedIndex, setSelectedIndex] = useState(0);

  const { history, push: pushHistory } = useSearchHistory();

  useEffect(() => { setMounted(true); }, []);

  // Focus input on open
  useEffect(() => {
    if (open) {
      setQuery("");
      setResults(null);
      setSearchError(null);
      setSelectedIndex(0);
      setIsLoading(false);
      setTimeout(() => inputRef.current?.focus(), 50);
    }
  }, [open]);

  // Debounced search
  useEffect(() => {
    if (query.length < MIN_QUERY_LEN) {
      setResults(null);
      setSearchError(null);
      setIsLoading(false);
      return;
    }

    const trimmedQuery = query.slice(0, MAX_QUERY_LEN);
    setIsLoading(true);
    setSearchError(null);

    const timer = setTimeout(async () => {
      try {
        const res = await api<SearchResponse>(`/search?q=${encodeURIComponent(trimmedQuery)}&limit=20`);
        setResults(res);
        setSelectedIndex(0);
      } catch {
        setSearchError("Не удалось выполнить поиск. Попробуй снова");
        setResults(null);
      } finally {
        setIsLoading(false);
      }
    }, DEBOUNCE_MS);

    return () => clearTimeout(timer);
  }, [query]);

  // Build flat list of all items for keyboard navigation
  const allItems: SearchResultItemType[] = results
    ? results.groups.flatMap((g) => g.items)
    : [];

  // Also history items for navigation when empty query
  const historyItems: SearchResultItemType[] = history.map((h) => ({
    entity_type: h.entity_type,
    id: h.id,
    display_name: h.display_name,
    secondary: null,
  }));

  const navItems = query.length >= MIN_QUERY_LEN ? allItems : historyItems;

  function navigateToItem(item: SearchResultItemType) {
    const basePath = SEARCH_ENTITY_PATHS[item.entity_type];
    // deals don't have a detail page yet — stay on list
    if (item.entity_type === "deal") {
      router.push(basePath);
    } else {
      router.push(`${basePath}/${item.id}`);
    }
    pushHistory({ ...item, visited_at: new Date().toISOString() });
    onClose();
  }

  const handleKeyDown = useCallback((e: React.KeyboardEvent) => {
    if (e.key === "Escape") {
      onClose();
      return;
    }
    if (e.key === "ArrowDown") {
      e.preventDefault();
      setSelectedIndex((i) => (i + 1) % Math.max(navItems.length, 1));
    } else if (e.key === "ArrowUp") {
      e.preventDefault();
      setSelectedIndex((i) => (i - 1 + Math.max(navItems.length, 1)) % Math.max(navItems.length, 1));
    } else if (e.key === "Enter") {
      const item = navItems[selectedIndex];
      if (item) navigateToItem(item);
    }
  }, [navItems, selectedIndex, onClose]);

  function handleBackdropClick(e: React.MouseEvent) {
    if (e.target === e.currentTarget) onClose();
  }

  if (!mounted || !open) return null;

  // Compute group offsets for selectedIndex mapping
  let groupOffset = 0;
  const groupedResults = results?.groups.filter((g) => g.items.length > 0) ?? [];

  const showHistory = query.length < MIN_QUERY_LEN;
  const showEmpty = !isLoading && !searchError && query.length >= MIN_QUERY_LEN && allItems.length === 0;
  const showSkeleton = isLoading;

  const isMac = typeof navigator !== "undefined" && /Mac/.test(navigator.platform);
  const shortcutLabel = isMac ? "⌘K" : "Ctrl+K";

  return createPortal(
    <div
      className="fixed inset-0 z-50 bg-black/40 backdrop-blur-[2px] flex justify-center px-4"
      style={{ paddingTop: "15vh" }}
      onClick={handleBackdropClick}
    >
      <div
        className="bg-white rounded-xl shadow-2xl w-full max-w-2xl flex flex-col"
        style={{ maxHeight: "70vh" }}
      >
        {/* Search input */}
        <div className="flex items-center gap-3 px-4 py-3 border-b border-gray-200">
          <i className="bi bi-search text-gray-400 text-lg shrink-0" />
          <input
            ref={inputRef}
            type="text"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            onKeyDown={handleKeyDown}
            placeholder="Поиск по всей CRM…"
            className="flex-1 text-base outline-none bg-transparent placeholder-gray-400"
          />
          <button
            onClick={onClose}
            className="text-xs text-gray-400 hover:text-gray-600 border border-gray-200 rounded px-1.5 py-0.5 shrink-0"
          >
            Esc
          </button>
        </div>

        {/* Results area */}
        <div className="overflow-y-auto flex-1 py-2">
          {/* Loading skeleton */}
          {showSkeleton && (
            <div className="animate-pulse px-4 space-y-4 py-2">
              {[1, 2].map((g) => (
                <div key={g} className="space-y-2">
                  <div className="h-3 bg-gray-200 rounded w-20" />
                  <div className="h-8 bg-gray-200 rounded" />
                  <div className="h-8 bg-gray-200 rounded" />
                </div>
              ))}
            </div>
          )}

          {/* Error */}
          {searchError && (
            <div className="px-4 py-3 text-sm text-danger">{searchError}</div>
          )}

          {/* Empty result */}
          {showEmpty && (
            <div className="py-12 text-center">
              <i className="bi bi-search text-4xl text-gray-300 block mb-2" />
              <div className="text-sm font-medium text-gray-700">Ничего не найдено</div>
              <div className="text-xs text-gray-400 mt-1">Попробуй имя, email, телефон или ИНН</div>
            </div>
          )}

          {/* History (empty query) */}
          {showHistory && !showSkeleton && (
            <div>
              {historyItems.length > 0 ? (
                <>
                  <div className="px-4 py-1.5 text-xs uppercase tracking-wide text-gray-500 font-medium">
                    Недавние
                  </div>
                  {historyItems.map((item, i) => {
                    const icon = SEARCH_ENTITY_ICONS[item.entity_type];
                    const typeLabel = SEARCH_ENTITY_LABELS[item.entity_type];
                    return (
                      <button
                        key={`hist-${item.entity_type}-${item.id}`}
                        type="button"
                        onClick={() => navigateToItem(item)}
                        className={`w-full text-left px-4 py-2.5 flex items-center gap-3 rounded-md transition-colors ${
                          selectedIndex === i
                            ? "bg-primary/5 border-l-2 border-primary"
                            : "hover:bg-gray-50 border-l-2 border-transparent"
                        }`}
                      >
                        <i className={`bi ${icon} text-gray-400 text-base shrink-0`} />
                        <span className="flex-1 text-sm text-gray-800 truncate">{item.display_name}</span>
                        <span className="text-xs text-gray-400 shrink-0">{typeLabel}</span>
                      </button>
                    );
                  })}
                </>
              ) : (
                <div className="py-12 text-center text-sm text-gray-400">
                  Начни вводить для поиска
                </div>
              )}
            </div>
          )}

          {/* Search results */}
          {!showSkeleton && !showEmpty && query.length >= MIN_QUERY_LEN && groupedResults.length > 0 && (
            <div>
              {groupedResults.map((group) => {
                const offset = groupOffset;
                groupOffset += group.items.length;
                return (
                  <SearchResultGroup
                    key={group.entity_type}
                    entityType={group.entity_type}
                    items={group.items}
                    selectedIndex={selectedIndex}
                    globalOffset={offset}
                    onSelect={navigateToItem}
                  />
                );
              })}
            </div>
          )}
        </div>

        {/* Footer hint */}
        <div className="px-4 py-2 border-t border-gray-100 text-xs text-gray-400 flex items-center gap-3">
          <span>↑↓ навигация</span>
          <span>·</span>
          <span>Enter открыть</span>
          <span>·</span>
          <span>Esc закрыть</span>
          <span className="ml-auto">{shortcutLabel}</span>
        </div>
      </div>
    </div>,
    document.body,
  );
}
