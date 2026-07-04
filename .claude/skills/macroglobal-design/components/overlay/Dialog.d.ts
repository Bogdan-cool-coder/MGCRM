import * as React from 'react';

export interface DialogProps {
  /** @default true */
  open?: boolean;
  title?: string;
  icon?: string;
  children?: React.ReactNode;
  /** Footer node — typically a row of <Button/> */
  footer?: React.ReactNode;
  onClose?: () => void;
  width?: number;
  style?: React.CSSProperties;
}

/** Centered modal dialog with scrim, title bar, body and footer actions. */
export function Dialog(props: DialogProps): React.ReactElement | null;
