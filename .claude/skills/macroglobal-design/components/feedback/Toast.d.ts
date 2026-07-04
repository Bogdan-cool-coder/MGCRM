import * as React from 'react';

export interface ToastProps {
  /** @default "success" */
  severity?: 'success' | 'danger' | 'warning' | 'info';
  title?: string;
  description?: string;
  /** PrimeIcon class override, e.g. "pi-bell" */
  icon?: string;
  /** Show a close affordance and handle dismissal */
  onClose?: () => void;
  style?: React.CSSProperties;
}

/** Notification toast with a status-colored inset border (success/danger/warning/info). */
export function Toast(props: ToastProps): React.ReactElement;
