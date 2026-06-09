"use client";

import Link from "next/link";
import { api } from "@/lib/api";
import type { SavedFilter, PageKey } from "@/lib/types";

interface SavedFilterPinProps {
  filter: SavedFilter;
  isActive: boolean;
  onRemovePin: (id: number) => void;
}

const PAGE_KEY_PATHS: Record<PageKey, string> = {
  leads: "/leads",
  contacts: "/contacts",
  companies: "/companies",
  counterparties: "/counterparties",
  deals: "/deals",
  registry: "/registry",
};

export function resolveSegmentUrl(pageKey: PageKey, filterId: number): string {
  return `${PAGE_KEY_PATHS[pageKey]}?segment=${filterId}`;
}

export function SavedFilterPin({ filter, isActive, onRemovePin }: SavedFilterPinProps) {
  const href = resolveSegmentUrl(filter.page_key, filter.id);

  async function handleRemovePin(e: React.MouseEvent) {
    e.preventDefault();
    e.stopPropagation();
    try {
      await api(`/saved-filters/${filter.id}`, {
        method: "PATCH",
        body: { is_pinned: false },
      });
      onRemovePin(filter.id);
    } catch {
      // silent
    }
  }

  return (
    <Link
      href={href}
      className={`group flex items-center gap-2 rounded-md px-3 py-1.5 text-sm transition-colors relative ${
        isActive ? "bg-primary/10 text-primary" : "text-gray-600 hover:bg-gray-100"
      }`}
    >
      <i className="bi bi-bookmark-fill text-xs text-gray-400 shrink-0" />
      <span className="flex-1 truncate">{filter.name}</span>
      <button
        onClick={handleRemovePin}
        className="opacity-0 group-hover:opacity-100 transition-opacity p-0.5 rounded hover:bg-gray-200 text-gray-400 hover:text-gray-600"
        title="Открепить"
      >
        <i className="bi bi-x text-xs" />
      </button>
    </Link>
  );
}
