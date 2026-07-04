import * as React from 'react';

export interface TooltipProps {
  label: string;
  /** @default "top" */
  placement?: 'top' | 'bottom' | 'start' | 'end';
  children: React.ReactNode;
  style?: React.CSSProperties;
}

/** Hover/focus hint chip around a single child. RTL-safe (logical placement). */
export function Tooltip(props: TooltipProps): React.ReactElement;
