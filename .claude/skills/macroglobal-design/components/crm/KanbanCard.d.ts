import * as React from 'react';

/**
 * @startingPoint section="CRM" subtitle="Pipeline deal card" viewport="320x150"
 */
export interface KanbanCardProps {
  title: string;
  /** Pre-formatted amount, e.g. "1 200 000 ₽" */
  amount?: string;
  /** Primary product chip label */
  product?: string;
  /** Owner full name (→ avatar initials) */
  owner?: string;
  daysInStage?: number;
  /** Health signal driving the bottom strip + left inset border. @default "ok" */
  health?: 'ok' | 'no-task' | 'overdue';
  /** Task line text for the strip (ok / overdue) */
  task?: string;
  /** Days-in-stage rotting → red day counter */
  rotting?: boolean;
  selected?: boolean;
  onClick?: (e: React.MouseEvent) => void;
  style?: React.CSSProperties;
}

/**
 * Deal card for the sales pipeline board — title, amount, product chip, owner,
 * days-in-stage, and a health strip (ok / no-task / overdue).
 * @startingPoint section="CRM" subtitle="Pipeline deal card" viewport="320x150"
 */
export function KanbanCard(props: KanbanCardProps): React.ReactElement;
