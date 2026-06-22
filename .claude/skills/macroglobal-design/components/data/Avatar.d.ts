import * as React from 'react';

export interface AvatarProps {
  name?: string;
  src?: string;
  /** @default "md" */
  size?: 'xs' | 'sm' | 'md' | 'lg' | number;
  /** Background fill when no src. @default navy */
  color?: string;
  /** Rounded square instead of circle */
  square?: boolean;
  style?: React.CSSProperties;
}

/** Initials/image avatar (navy fill) for owners, contacts, account menu. */
export function Avatar(props: AvatarProps): React.ReactElement;
