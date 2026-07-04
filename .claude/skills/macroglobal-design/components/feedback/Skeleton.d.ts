import * as React from 'react';

export interface SkeletonProps {
  /** @default "text" */
  variant?: 'text' | 'circle' | 'rect';
  width?: number | string;
  height?: number | string;
  /** For variant="text": number of shimmer rows (last row is shortened). @default 1 */
  lines?: number;
  /** Corner radius for variant="rect" */
  radius?: number | string;
  style?: React.CSSProperties;
}

/** Shimmer loading placeholder (text / circle / rect). */
export function Skeleton(props: SkeletonProps): React.ReactElement;
