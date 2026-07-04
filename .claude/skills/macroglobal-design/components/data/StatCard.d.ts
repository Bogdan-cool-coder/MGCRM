import * as React from 'react';

export interface StatCardProps {
  label: string;
  value: string | number;
  icon?: string;
  /** Delta text, e.g. "+12%" */
  delta?: string;
  /** @default "up" */
  deltaDir?: 'up' | 'down';
  /** Accent tone. @default "primary" */
  tone?: 'primary' | 'success' | 'danger' | 'warning' | 'info';
  style?: React.CSSProperties;
}

/** Dashboard / board KPI tile with value, delta and accent icon. */
export function StatCard(props: StatCardProps): React.ReactElement;
