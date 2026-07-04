import React from 'react';

/**
 * MACRO Global CRM — PageHeader
 * Standard module header: icon plaque + title (+ optional subtitle) on the start,
 * actions on the end. Used at the top of every work surface (Deals, Contacts,
 * Tasks, Settings…). Sits on a card surface, separated by a bottom border.
 */
export function PageHeader({ icon, title, subtitle, actions, style }) {
  return (
    <header style={{
      display: 'flex', alignItems: 'center', gap: '14px', padding: '14px 22px',
      borderBottom: '1px solid var(--mg-border-default)', background: 'var(--mg-surface-card)',
      flexShrink: 0, flexWrap: 'wrap', fontFamily: 'var(--mg-font-sans)', ...style,
    }}>
      {icon && (
        <span style={{
          width: 38, height: 38, borderRadius: 'var(--mg-radius-md)', background: 'var(--mg-primary-100)',
          display: 'inline-flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0,
        }}>
          <i className={'pi ' + icon} style={{ fontSize: '17px', color: 'var(--mg-primary-900)' }} />
        </span>
      )}
      <div style={{ minWidth: 0 }}>
        <h1 style={{ fontSize: '19px', fontWeight: 600, color: 'var(--mg-text-primary)', margin: 0, lineHeight: 1.1 }}>{title}</h1>
        {subtitle && <div style={{ fontSize: '12px', color: 'var(--mg-text-muted)', marginTop: '2px' }}>{subtitle}</div>}
      </div>
      <span style={{ flex: 1, minWidth: 12 }} />
      {actions}
    </header>
  );
}
