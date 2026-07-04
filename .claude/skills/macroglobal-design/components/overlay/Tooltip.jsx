import React from 'react';

/**
 * MACRO Global CRM — Tooltip
 * Hover/focus hint. Wraps a single child; shows `label` on a dark chip.
 * placement: top | bottom | start | end.
 */
export function Tooltip({ label, placement = 'top', children, style }) {
  const [show, setShow] = React.useState(false);
  const pos = {
    top:    { bottom: '100%', insetInlineStart: '50%', transform: 'translateX(-50%) translateY(-6px)' },
    bottom: { top: '100%', insetInlineStart: '50%', transform: 'translateX(-50%) translateY(6px)' },
    start:  { insetInlineEnd: '100%', top: '50%', transform: 'translateY(-50%) translateX(-6px)' },
    end:    { insetInlineStart: '100%', top: '50%', transform: 'translateY(-50%) translateX(6px)' },
  }[placement];
  return (
    <span style={{ position: 'relative', display: 'inline-flex', ...style }}
      onMouseEnter={() => setShow(true)} onMouseLeave={() => setShow(false)}
      onFocusCapture={() => setShow(true)} onBlurCapture={() => setShow(false)}>
      {children}
      {show && label && (
        <span role="tooltip" style={{
          position: 'absolute', ...pos, zIndex: 'var(--mg-z-tooltip, 1500)', whiteSpace: 'nowrap',
          background: 'var(--mg-gray-900)', color: 'var(--mg-gray-0)', fontFamily: 'var(--mg-font-sans)',
          fontSize: 12, fontWeight: 500, padding: '5px 9px', borderRadius: 'var(--mg-radius-sm)',
          boxShadow: 'var(--mg-shadow-md)', pointerEvents: 'none', animation: 'mg-fade-in .12s ease',
        }}>{label}</span>
      )}
    </span>
  );
}
