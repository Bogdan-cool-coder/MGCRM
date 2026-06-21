import * as React from 'react';

export interface SelectProps {
  value?: string;
  placeholder?: string;
  size?: 'sm' | 'md' | 'lg';
  disabled?: boolean;
  fullWidth?: boolean;
  /** Show the focused/open border + ring */
  open?: boolean;
  onClick?: (e: React.MouseEvent) => void;
  style?: React.CSSProperties;
}

/** Closed-state dropdown trigger mirroring PrimeVue Select. Display-only. */
export function Select(props: SelectProps): React.ReactElement;
