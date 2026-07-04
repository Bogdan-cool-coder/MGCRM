import React from 'react';

/**
 * MACRO Global CRM — Pagination
 * Page controls with first/prev/next/last + a windowed page list. RTL-safe
 * (chevrons flip via .mg-flip-rtl).
 */
export function Pagination({ page = 1, pageCount = 1, onChange, siblings = 1, style }) {
  const go = (p) => onChange && p >= 1 && p <= pageCount && p !== page && onChange(p);
  const pages = [];
  const from = Math.max(1, page - siblings), to = Math.min(pageCount, page + siblings);
  if (from > 1) pages.push(1, from > 2 ? '…' : null);
  for (let p = from; p <= to; p++) pages.push(p);
  if (to < pageCount) pages.push(to < pageCount - 1 ? '…' : null, pageCount);
  const Arrow = ({ icon, to: t, dis }) => (
    <button onClick={() => go(t)} disabled={dis} style={{ width: 28, height: 28, display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
      border: 'none', background: 'transparent', borderRadius: 5, cursor: dis ? 'default' : 'pointer', opacity: dis ? 0.4 : 1, color: 'var(--mg-text-muted)' }}>
      <i className={'pi ' + icon + ' mg-flip-rtl'} style={{ fontSize: 13 }} /></button>
  );
  return (
    <div style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontFamily: 'var(--mg-font-sans)', ...style }}>
      <Arrow icon="pi-angle-double-left" to={1} dis={page <= 1} />
      <Arrow icon="pi-angle-left" to={page - 1} dis={page <= 1} />
      {pages.filter((p) => p !== null).map((p, i) => p === '…'
        ? <span key={'e' + i} style={{ minWidth: 20, textAlign: 'center', color: 'var(--mg-text-muted)', fontSize: 13 }}>…</span>
        : <button key={p} onClick={() => go(p)} style={{ minWidth: 28, height: 28, display: 'inline-flex', alignItems: 'center', justifyContent: 'center', borderRadius: 5,
            border: 'none', cursor: 'pointer', fontFamily: 'inherit', fontSize: 13, fontWeight: p === page ? 700 : 500,
            background: p === page ? 'var(--mg-primary-900)' : 'transparent', color: p === page ? '#fff' : 'var(--mg-text-secondary)' }}>{p}</button>)}
      <Arrow icon="pi-angle-right" to={page + 1} dis={page >= pageCount} />
      <Arrow icon="pi-angle-double-right" to={pageCount} dis={page >= pageCount} />
    </div>
  );
}
