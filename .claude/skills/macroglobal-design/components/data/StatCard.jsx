import React from 'react';

/**
 * MACRO Global CRM — StatCard
 * Board / dashboard KPI tile: label, big value, optional delta + icon. tone
 * maps to the status palette for the accent (primary by default).
 */
export function StatCard({ label, value, icon, delta, deltaDir, tone = 'primary', style }) {
  const accent = tone === 'primary' ? 'var(--mg-primary-900)' : `var(--mg-status-${tone}-solid)`;
  const plate = tone === 'primary' ? 'var(--mg-primary-100)' : `var(--mg-status-${tone}-bg)`;
  const up = deltaDir !== 'down';
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 10, background: 'var(--mg-surface-card)', border: '1px solid var(--mg-border-default)',
      borderRadius: 'var(--mg-radius-lg)', padding: 'var(--mg-card-pad, 16px)', boxShadow: 'var(--mg-shadow-sm)', fontFamily: 'var(--mg-font-sans)', minWidth: 160, ...style }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
        <span style={{ fontSize: 12, fontWeight: 600, color: 'var(--mg-text-muted)', textTransform: 'uppercase', letterSpacing: '.04em' }}>{label}</span>
        {icon && <span style={{ width: 30, height: 30, borderRadius: 'var(--mg-radius-md)', background: plate, display: 'inline-flex', alignItems: 'center', justifyContent: 'center' }}>
          <i className={'pi ' + icon} style={{ fontSize: 14, color: accent }} /></span>}
      </div>
      <div style={{ display: 'flex', alignItems: 'baseline', gap: 8 }}>
        <span style={{ fontSize: 26, fontWeight: 700, color: 'var(--mg-text-primary)', fontVariantNumeric: 'tabular-nums', lineHeight: 1 }}>{value}</span>
        {delta != null && <span style={{ display: 'inline-flex', alignItems: 'center', gap: 3, fontSize: 12, fontWeight: 600,
          color: up ? 'var(--mg-status-success-text)' : 'var(--mg-status-danger-text)' }}>
          <i className={'pi ' + (up ? 'pi-arrow-up-right' : 'pi-arrow-down-right')} style={{ fontSize: 11 }} />{delta}</span>}
      </div>
    </div>
  );
}
