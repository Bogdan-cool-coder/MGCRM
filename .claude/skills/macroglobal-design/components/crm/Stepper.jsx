import React from 'react';

/**
 * MACRO Global CRM — Stepper
 * Horizontal step track for deal stages / approval routes (brief D8). Steps:
 * [{ label, sub, status }] where status: 'done' | 'current' | 'todo'.
 */
export function Stepper({ steps = [], style }) {
  const tone = (s) => s === 'done'
    ? { ring: 'var(--mg-status-success-solid)', fill: 'var(--mg-status-success-solid)', ink: '#fff' }
    : s === 'current'
      ? { ring: 'var(--mg-primary-900)', fill: 'var(--mg-primary-900)', ink: '#fff' }
      : { ring: 'var(--mg-border-strong)', fill: 'var(--mg-surface-card)', ink: 'var(--mg-text-muted)' };
  return (
    <div style={{ display: 'flex', alignItems: 'flex-start', fontFamily: 'var(--mg-font-sans)', ...style }}>
      {steps.map((st, i) => {
        const t = tone(st.status);
        const last = i === steps.length - 1;
        return (
          <div key={i} style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', flex: last ? '0 0 auto' : 1, minWidth: 0, position: 'relative' }}>
            <div style={{ display: 'flex', alignItems: 'center', width: '100%' }}>
              <span style={{ flex: last ? '0 0 auto' : '0 0 28px', width: 28, height: 28, borderRadius: '50%', flexShrink: 0,
                display: 'inline-flex', alignItems: 'center', justifyContent: 'center', fontSize: 12, fontWeight: 700,
                border: '2px solid ' + t.ring, background: t.fill, color: t.ink, zIndex: 1 }}>
                {st.status === 'done' ? <i className="pi pi-check" style={{ fontSize: 11 }} /> : i + 1}</span>
              {!last && <span style={{ flex: 1, height: 2, background: st.status === 'done' ? 'var(--mg-status-success-solid)' : 'var(--mg-border-default)' }} />}
            </div>
            <div style={{ marginTop: 8, textAlign: 'center', paddingInline: 6, maxWidth: 130 }}>
              <div style={{ fontSize: 13, fontWeight: st.status === 'current' ? 600 : 500, color: st.status === 'todo' ? 'var(--mg-text-muted)' : 'var(--mg-text-primary)', lineHeight: 1.2 }}>{st.label}</div>
              {st.sub && <div style={{ fontSize: 11, color: 'var(--mg-text-muted)', marginTop: 2 }}>{st.sub}</div>}
            </div>
          </div>
        );
      })}
    </div>
  );
}
