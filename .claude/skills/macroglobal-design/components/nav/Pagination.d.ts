import * as React from 'react';

export interface PaginationProps {
  page: number;
  pageCount: number;
  onChange?: (page: number) => void;
  /** Pages shown on each side of the current page. @default 1 */
  siblings?: number;
  style?: React.CSSProperties;
}

/** First/prev/next/last + windowed page list. RTL-safe chevrons. */
export function Pagination(props: PaginationProps): React.ReactElement;
