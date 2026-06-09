"use client";

import useSWR, { mutate } from "swr";
import { fetcher } from "@/lib/api";
import type { PageKey, SavedFilter } from "@/lib/types";

export function useSavedFilters(pageKey?: PageKey, isPinned?: boolean) {
  const params = new URLSearchParams();
  if (pageKey) params.set("page_key", pageKey);
  if (isPinned !== undefined) params.set("is_pinned", String(isPinned));

  const key = `/saved-filters?${params.toString()}`;
  const { data, isLoading, error } = useSWR<SavedFilter[]>(key, fetcher);

  return {
    filters: data ?? [],
    isLoading,
    error,
    mutate: () => mutate(key),
    swrKey: key,
  };
}

export function usePinnedFilters() {
  const key = `/saved-filters?is_pinned=true`;
  const { data, isLoading } = useSWR<SavedFilter[]>(key, fetcher);
  return {
    filters: data ?? [],
    isLoading,
    mutate: () => mutate(key),
  };
}
