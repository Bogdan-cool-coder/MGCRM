import * as React from 'react';

export interface InputProps {
  value?: string;
  placeholder?: string;
  /** Leading PrimeIcons class, e.g. "pi-search" */
  icon?: string;
  type?: string;
  /** @default "md" */
  size?: 'sm' | 'md' | 'lg';
  invalid?: boolean;
  disabled?: boolean;
  fullWidth?: boolean;
  onChange?: (e: React.ChangeEvent<HTMLInputElement>) => void;
  style?: React.CSSProperties;
}

/** Single-line text input matching the CRM's PrimeVue InputText (navy focus ring). */
export function Input(props: InputProps): React.ReactElement;
