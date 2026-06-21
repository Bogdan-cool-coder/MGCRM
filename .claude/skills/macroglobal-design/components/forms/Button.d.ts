import * as React from 'react';

/**
 * @startingPoint section="Forms" subtitle="Brand button — primary / secondary / danger" viewport="700x150"
 */
export interface ButtonProps {
  children?: React.ReactNode;
  /** Visual role. @default "primary" */
  variant?: 'primary' | 'secondary' | 'danger';
  /** @default "md" */
  size?: 'sm' | 'md' | 'lg';
  /** PrimeIcons class without the leading "pi ", e.g. "pi-plus" */
  icon?: string;
  /** Trailing PrimeIcons class */
  iconRight?: string;
  /** Borderless text button */
  text?: boolean;
  /** Outlined (transparent bg, bordered) */
  outlined?: boolean;
  disabled?: boolean;
  loading?: boolean;
  fullWidth?: boolean;
  onClick?: (e: React.MouseEvent<HTMLButtonElement>) => void;
  style?: React.CSSProperties;
}

/**
 * Primary action button for the MACRO Global CRM. Navy primary, outlined
 * neutral secondary, red danger. Pair with PrimeIcons for leading/trailing glyphs.
 *
 * @startingPoint section="Forms" subtitle="Brand button — primary / secondary / danger" viewport="700x150"
 */
export function Button(props: ButtonProps): React.ReactElement;
