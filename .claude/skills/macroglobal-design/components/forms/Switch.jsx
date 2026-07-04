import React from 'react';

/**
 * MACRO Global CRM — Switch
 * On/off toggle used across settings and inline row controls. Navy track when on,
 * neutral track when off. Distinct from Checkbox (boolean field) and
 * SegmentedControl (2–3 mutually-exclusive options).
 */
export function Switch({ on = false, onChange, disabled = false, size = 'md', label, style }) {
  const dims = size === 'sm' ? { w: 32, h: 18, k: 14 } : { w: 38, h: 22, k: 18 };
  const track = (
    <button
      type="button"
      role="switch"
      aria-checked={on}
      disabled={disabled}
      onClick={() => !disabled && onChange && onChange(!on)}
      style={{
        width: dims.w, height: dims.h, borderRadius: '999px', border: 'none', padding: 2,
        cursor: disabled ? 'not-allowed' : 'pointer', opacity: disabled ? 0.55 : 1, flexShrink: 0,
        background: on ? 'var(--mg-primary-900)' : 'var(--mg-border-strong)',
        transition: 'background var(--mg-transition-fast)',
        display: 'inline-flex', alignItems: 'center',
      }}
    >
      <span style={{
        width: dims.k, height: dims.k, borderRadius: '50%', background: '#fff',
        boxShadow: 'var(--mg-shadow-sm)', transition: 'transform var(--mg-transition-fast)',
        transform: on ? `translateX(${dims.w - dims.k - 4}px)` : 'translateX(0)',
      }} />
    </button>
  );
  if (!label) return React.cloneElement(track, { style: { ...track.props.style, ...style } });
  return (
    <label style={{
      display: 'inline-flex', alignItems: 'center', gap: '10px',
      fontFamily: 'var(--mg-font-sans)', fontSize: '14px', color: 'var(--mg-text-primary)',
      cursor: disabled ? 'not-allowed' : 'pointer', ...style,
    }}>
      {track}
      <span>{label}</span>
    </label>
  );
}
