"use client";

import { createContext, useContext } from "react";

export interface SearchContextValue {
  openSearch: () => void;
}

export const SearchContext = createContext<SearchContextValue>({
  openSearch: () => {},
});

export function useSearch() {
  return useContext(SearchContext);
}
