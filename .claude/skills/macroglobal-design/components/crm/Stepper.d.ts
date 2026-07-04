import * as React from 'react';

export interface Step {
  label: string;
  sub?: string;
  /** @default "todo" */
  status?: 'done' | 'current' | 'todo';
}

export interface StepperProps {
  steps: Step[];
  style?: React.CSSProperties;
}

/** Horizontal step track for deal stages / approval routes. */
export function Stepper(props: StepperProps): React.ReactElement;
