import * as React from 'react';

export interface DataTableColumn<Row = any> {
  key: string;
  label: string;
  width?: number | string;
  align?: 'start' | 'center' | 'end';
  sortable?: boolean;
  render?: (row: Row, index: number) => React.ReactNode;
}

export interface DataTableProps<Row = any> {
  columns: DataTableColumn<Row>[];
  rows: Row[];
  /** Field on each row used as the React key + selection id. @default "id" */
  rowKey?: string;
  selectable?: boolean;
  selected?: Array<string | number>;
  onToggle?: (id: string | number) => void;
  onToggleAll?: () => void;
  sortKey?: string;
  sortDir?: 'asc' | 'desc';
  onSort?: (key: string) => void;
  /** @default true */
  zebra?: boolean;
  onRowClick?: (row: Row) => void;
  /** Custom empty-state node */
  empty?: React.ReactNode;
  style?: React.CSSProperties;
}

/** Config-driven CRM table: density-aware, zebra, sticky header, sortable, selectable. */
export function DataTable<Row = any>(props: DataTableProps<Row>): React.ReactElement;
