"use client";

import {
  SEARCH_ENTITY_LABELS,
  type SearchEntityType,
  type SearchResultItem as SearchResultItemType,
} from "@/lib/types";
import { SearchResultItem } from "./SearchResultItem";

interface SearchResultGroupProps {
  entityType: SearchEntityType;
  items: SearchResultItemType[];
  selectedIndex: number;
  globalOffset: number;
  onSelect: (item: SearchResultItemType) => void;
}

export function SearchResultGroup({
  entityType,
  items,
  selectedIndex,
  globalOffset,
  onSelect,
}: SearchResultGroupProps) {
  return (
    <div>
      <div className="px-4 py-1.5 text-xs uppercase tracking-wide text-gray-500 font-medium">
        {SEARCH_ENTITY_LABELS[entityType]}
      </div>
      {items.map((item, i) => (
        <SearchResultItem
          key={`${item.entity_type}-${item.id}`}
          item={item}
          isSelected={selectedIndex === globalOffset + i}
          onClick={() => onSelect(item)}
        />
      ))}
    </div>
  );
}
