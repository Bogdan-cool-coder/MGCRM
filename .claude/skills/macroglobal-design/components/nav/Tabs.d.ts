import * as React from 'react';

export interface TabItem {
  key: string;
  label: string;
  count?: number;
  icon?: string;
}

export interface TabsProps {
  items: TabItem[];
  value: string;
  onChange?: (key: string) => void;
  style?: React.CSSProperties;
}

/** Underline tab bar with optional count badges. */
export function Tabs(props: TabsProps): React.ReactElement;
