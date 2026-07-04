import * as React from 'react';

export interface CommandItem {
  icon?: string;
  label: string;
  sub?: string;
  /** Right-aligned hint text, e.g. "Перейти" */
  kbd?: string;
  active?: boolean;
  onSelect?: () => void;
}

export interface CommandPaletteProps {
  /** @default true */
  open?: boolean;
  placeholder?: string;
  items?: CommandItem[];
  onClose?: () => void;
  /** Keyboard hint chip. @default "⌘K" */
  hint?: string;
  style?: React.CSSProperties;
}

/** Global search + quick-action overlay (Cmd+K). Type to filter the provided items. */
export function CommandPalette(props: CommandPaletteProps): React.ReactElement | null;
