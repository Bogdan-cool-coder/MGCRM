import * as React from 'react';

export interface PageHeaderProps {
  /** PrimeIcon class without the leading `pi-` space, e.g. "pi-briefcase". Renders an icon plaque. */
  icon?: string;
  title: React.ReactNode;
  subtitle?: React.ReactNode;
  /** Right-aligned actions (buttons, search, view switch). */
  actions?: React.ReactNode;
  style?: React.CSSProperties;
}

/** Standard module header — icon plaque + title/subtitle on the start, actions on the end. */
export function PageHeader(props: PageHeaderProps): React.ReactElement;
