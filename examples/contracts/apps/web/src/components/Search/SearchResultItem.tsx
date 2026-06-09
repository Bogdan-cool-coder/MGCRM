"use client";

import {
  SEARCH_ENTITY_LABELS,
  SEARCH_ENTITY_ICONS,
  type SearchResultItem as SearchResultItemType,
} from "@/lib/types";

interface SearchResultItemProps {
  item: SearchResultItemType;
  isSelected: boolean;
  onClick: () => void;
}

export function SearchResultItem({ item, isSelected, onClick }: SearchResultItemProps) {
  const icon = SEARCH_ENTITY_ICONS[item.entity_type];
  const typeLabel = SEARCH_ENTITY_LABELS[item.entity_type];

  return (
    <button
      type="button"
      onClick={onClick}
      className={`w-full text-left px-4 py-2.5 flex items-center gap-3 rounded-md transition-colors ${
        isSelected
          ? "bg-primary/5 border-l-2 border-primary"
          : "hover:bg-gray-50 border-l-2 border-transparent"
      }`}
    >
      <i className={`bi ${icon} text-gray-400 text-base shrink-0`} />
      <div className="flex-1 min-w-0">
        <div className="text-sm font-medium text-gray-900 truncate">{item.display_name}</div>
        {item.secondary && (
          <div className="text-xs text-gray-500 truncate">{item.secondary}</div>
        )}
      </div>
      <span className="text-xs text-gray-400 shrink-0">{typeLabel}</span>
    </button>
  );
}
