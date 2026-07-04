import * as React from 'react';

export interface NotificationBadgeProps {
  value?: string | number;
  /** @default "danger" */
  variant?: 'danger' | 'primary' | 'warning';
  /** Render a bare dot instead of a count */
  dot?: boolean;
  /** Clamp numbers above this to "N+". @default 99 */
  max?: number;
  children?: React.ReactNode;
  style?: React.CSSProperties;
}

/** Pins a count/dot to the top-inline-end of a wrapped icon (topbar bell, nav). */
export function NotificationBadge(props: NotificationBadgeProps): React.ReactElement;
