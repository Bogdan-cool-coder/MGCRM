import * as React from 'react';

export interface AvatarGroupItem {
  name: string;
  src?: string;
  color?: string;
}

export interface AvatarGroupProps {
  items?: AvatarGroupItem[];
  /** Max avatars before "+N" overflow. @default 4 */
  max?: number;
  /** Diameter in px. @default 32 */
  size?: number;
  style?: React.CSSProperties;
}

/** Overlapping initials avatars with a "+N" overflow chip. */
export function AvatarGroup(props: AvatarGroupProps): React.ReactElement;
