import * as React from 'react';

export interface MenuItem {
  icon?: string;
  label?: string;
  onClick?: () => void;
  danger?: boolean;
  kbd?: string;
  sep?: boolean;
}

export interface MenuProps {
  items: MenuItem[];
  onClose?: () => void;
  /** Which edge to anchor to inside the relative parent. @default "end" */
  align?: 'start' | 'end';
  top?: string;
  width?: number;
  style?: React.CSSProperties;
}

/** Dropdown / context menu. Render inside a position:relative anchor. */
export function Menu(props: MenuProps): React.ReactElement;
