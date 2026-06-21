import * as React from 'react';

export interface CheckboxProps {
  checked?: boolean;
  label?: React.ReactNode;
  disabled?: boolean;
  onChange?: (checked: boolean) => void;
  style?: React.CSSProperties;
}

/** Checkbox matching PrimeVue — navy fill when checked, optional label. */
export function Checkbox(props: CheckboxProps): React.ReactElement;
