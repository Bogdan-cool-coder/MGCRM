import * as React from 'react';

/**
 * @startingPoint section="Data" subtitle="Status & category pills" viewport="700x140"
 */
export interface TagProps {
  children?: React.ReactNode;
  /** @default "secondary" */
  severity?: 'success' | 'danger' | 'warning' | 'warn' | 'info' | 'primary' | 'secondary';
  /** Leading PrimeIcons class, e.g. "pi-verified" */
  icon?: string;
  size?: 'sm' | 'md' | 'lg';
  /** Solid fill instead of soft tinted bg */
  solid?: boolean;
  style?: React.CSSProperties;
}

/**
 * Status / category pill (PrimeVue Tag severities + CRM deal colors).
 * @startingPoint section="Data" subtitle="Status & category pills" viewport="700x140"
 */
export function Tag(props: TagProps): React.ReactElement;
