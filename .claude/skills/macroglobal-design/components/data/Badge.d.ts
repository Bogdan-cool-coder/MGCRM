import * as React from 'react';

export interface BadgeProps {
  value?: string | number;
  /** @default "warning" (amber) */
  variant?: 'warning' | 'danger' | 'primary';
  /** Render as a bare dot indicator */
  dot?: boolean;
  style?: React.CSSProperties;
}

/** Count indicator for nav items and tab headers (amber/red), matching the sidebar. */
export function Badge(props: BadgeProps): React.ReactElement;
