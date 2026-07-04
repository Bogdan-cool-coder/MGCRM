import React from 'react';

/**
 * MACRO Global CRM — EmptyState
 * Centered empty/zero-data state: icon plate, title, description, optional action.
 */
export function EmptyState({ icon = 'pi-inbox', title, description, action, style }) {
  return (
    <div style={{
      display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center',
      textAlign: 'center', gap: 10, padding: '32px 20px', fontFamily: 'var(--mg-font-sans)', ...style,
    }}>
      <div style={{
        width: 64, height: 64, borderRadius: 'var(--mg-radius-xl)',
        background: 'var(--mg-surface-muted)', display: 'flex', alignItems: 'center', justifyContent: 'center',
      }}>
        <i className={`pi ${icon}`} style={{ fontSize: 28, color: 'var(--mg-text-muted)' }} />
      </div>
      {title && <div style={{ fontSize: 16, fontWeight: 600, color: 'var(--mg-text-primary)' }}>{title}</div>}
      {description && <div style={{ fontSize: 13, color: 'var(--mg-text-muted)', maxWidth: 300, lineHeight: 1.5 }}>{description}</div>}
      {action && <div style={{ marginTop: 6 }}>{action}</div>}
    </div>
  );
}
