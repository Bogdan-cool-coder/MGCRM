import React from 'react';

/**
 * MACRO Global CRM — AvatarGroup
 * Overlapping initials avatars with a "+N" overflow chip. Used for deal
 * watchers / shared owners. Pass items as [{ name, src, color }].
 */
const PALETTE = ['var(--mg-primary-900)', 'var(--mg-stage-blue)', 'var(--mg-stage-teal)', 'var(--mg-stage-amber)', 'var(--mg-stage-pink)', 'var(--mg-stage-purple)'];

export function AvatarGroup({ items = [], max = 4, size = 32, style }) {
  const shown = items.slice(0, max);
  const extra = items.length - shown.length;
  const initials = (n = '') => n.trim().split(/\s+/).slice(0, 2).map((w) => (w[0] ? w[0].toUpperCase() : '')).join('') || '?';
  const cell = {
    width: size, height: size, borderRadius: '50%', flexShrink: 0,
    display: 'inline-flex', alignItems: 'center', justifyContent: 'center', overflow: 'hidden',
    color: '#fff', fontFamily: 'var(--mg-font-sans)', fontWeight: 600, fontSize: Math.round(size * 0.38),
    border: '2px solid var(--mg-surface-card)', boxSizing: 'content-box',
  };
  return (
    <div style={{ display: 'inline-flex', alignItems: 'center', ...style }}>
      {shown.map((it, i) => (
        <span key={i} title={it.name} style={{ ...cell, background: it.src ? 'transparent' : (it.color || PALETTE[i % PALETTE.length]), marginInlineStart: i === 0 ? 0 : -10 }}>
          {it.src ? <img src={it.src} alt={it.name} style={{ width: '100%', height: '100%', objectFit: 'cover' }} /> : initials(it.name)}
        </span>
      ))}
      {extra > 0 && (
        <span style={{ ...cell, background: 'var(--mg-surface-hover)', color: 'var(--mg-text-secondary)', marginInlineStart: -10 }}>+{extra}</span>
      )}
    </div>
  );
}
