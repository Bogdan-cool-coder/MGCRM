import React from 'react';

/**
 * MACRO Global CRM — Avatar
 * Initials avatar (navy fill). Used for deal owners, contacts, account menu.
 */
export function Avatar({ name = '', src, size = 'md', color = 'var(--mg-primary-900)', square = false, style }) {
  const sizes = { xs: 20, sm: 28, md: 36, lg: 44 };
  const px = sizes[size] || (typeof size === 'number' ? size : 36);
  const initials = name.trim().split(/\s+/).slice(0, 2).map((w) => w[0] ? w[0].toUpperCase() : '').join('') || '?';
  return (
    <span style={{
      width: px, height: px, flexShrink: 0,
      borderRadius: square ? 'var(--mg-radius-md)' : '50%',
      background: src ? 'transparent' : color, color: '#fff',
      display: 'inline-flex', alignItems: 'center', justifyContent: 'center', overflow: 'hidden',
      fontFamily: 'var(--mg-font-sans)', fontWeight: 600, fontSize: Math.round(px * 0.4),
      ...style,
    }}>
      {src ? <img src={src} alt={name} style={{ width: '100%', height: '100%', objectFit: 'cover' }} /> : initials}
    </span>
  );
}
