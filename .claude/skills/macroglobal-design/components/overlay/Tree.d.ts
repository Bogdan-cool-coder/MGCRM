import * as React from 'react';

export interface TreeNodeData {
  label: string;
  icon?: string;
  /** Trailing count/meta */
  count?: number | string;
  /** @default true */
  defaultExpanded?: boolean;
  children?: TreeNodeData[];
}

export interface TreeProps {
  nodes?: TreeNodeData[];
  style?: React.CSSProperties;
}

/** Collapsible hierarchy tree (property → building → entrance → units). RTL-safe. */
export function Tree(props: TreeProps): React.ReactElement;
