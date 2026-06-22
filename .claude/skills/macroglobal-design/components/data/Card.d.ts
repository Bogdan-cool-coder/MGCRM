import * as React from 'react';

export interface CardProps {
  title?: React.ReactNode;
  /** Leading PrimeIcons class for the header */
  icon?: string;
  /** Right-aligned header content (buttons, menu) */
  actions?: React.ReactNode;
  children?: React.ReactNode;
  /** Body padding in px. @default 16 */
  padding?: number;
  /** Lift shadow on hover */
  hover?: boolean;
  style?: React.CSSProperties;
}

/** White surface container — 1px border, 8px radius, soft card shadow, optional header. */
export function Card(props: CardProps): React.ReactElement;
