import * as React from 'react';

export interface SwitchProps {
  /** On/off state. */
  on?: boolean;
  onChange?: (on: boolean) => void;
  disabled?: boolean;
  /** Track size. 'md' = 38×22 (default), 'sm' = 32×18. */
  size?: 'sm' | 'md';
  /** Optional trailing label; renders as a clickable <label> row. */
  label?: React.ReactNode;
  style?: React.CSSProperties;
}

/** On/off toggle — navy track when on. For a boolean field use Checkbox; for 2–3 options use SegmentedControl. */
export function Switch(props: SwitchProps): React.ReactElement;
