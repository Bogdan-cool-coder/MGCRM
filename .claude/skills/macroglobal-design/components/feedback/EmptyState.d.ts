import * as React from 'react';

export interface EmptyStateProps {
  /** PrimeIcon class. @default "pi-inbox" */
  icon?: string;
  title?: string;
  description?: string;
  /** Call-to-action node (e.g. a <Button/>) */
  action?: React.ReactNode;
  style?: React.CSSProperties;
}

/** Centered zero-data state with icon plate, title, description and optional action. */
export function EmptyState(props: EmptyStateProps): React.ReactElement;
