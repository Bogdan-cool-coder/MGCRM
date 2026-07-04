import * as React from 'react';

export interface SegOption {
  key: string;
  label?: string;
  icon?: string;
  title?: string;
}

export interface SegmentedControlProps {
  options: Array<string | SegOption>;
  value: string;
  onChange?: (key: string) => void;
  /** @default "md" */
  size?: 'sm' | 'md' | 'lg';
  style?: React.CSSProperties;
}

/** Pill segmented switch (view toggle, scope). Icon-only when label omitted. */
export function SegmentedControl(props: SegmentedControlProps): React.ReactElement;
