import React from 'react';

/**
 * MACRO Global CRM — Card
 * White surface container: 1px border, 8px radius, soft card shadow.
 * Optional header (title + actions slot).
 */
export function Card({ title, icon, actions, children, padding = 16, hover = false, style }) {
  const [h, setH] = React.useState(false);
  return (
    <div
      onMouseEnter={() => setH(true)}
      onMouseLeave={() => setH(false)}
      style={{
        background: 'var(--mg-surface-card)', border: '1px solid var(--mg-border-default)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: hover && h ? 'var(--mg-shadow-md)' : 'var(--mg-shadow-card)',
        transition: 'box-shadow var(--mg-transition-fast)', overflow: 'hidden',
        fontFamily: 'var(--mg-font-sans)', color: 'var(--mg-text-primary)', ...style,
      }}
    >
      {title && (
        <div style={{
          display: 'flex', alignItems: 'center', gap: '10px',
          padding: '12px 16px', borderBottom: '1px solid var(--mg-border-default)',
        }}>
          {icon && <i className={`pi ${icon}`} style={{ fontSize: '15px', color: 'var(--mg-primary-900)' }} />}
          <span style={{ fontSize: '15px', fontWeight: 600, flex: 1 }}>{title}</span>
          {actions}
        </div>
      )}
      <div style={{ padding }}>{children}</div>
    </div>
  );
}
