import React from 'react';

/**
 * MACRO Global CRM — CommandPalette (Cmd+K)
 * Global search + quick actions overlay. Presentational + lightly interactive:
 * type to filter the provided items, arrow list, ⌘K hint. Pass `items` as
 * [{ icon, label, sub, kbd, active, onSelect }].
 */
export function CommandPalette({
  open = true, placeholder = 'Поиск лидов, юнитов, сделок…',
  items = [], onClose, hint = '⌘K', style,
}) {
  const [q, setQ] = React.useState('');
  if (!open) return null;
  const filtered = q.trim()
    ? items.filter((it) => (it.label + ' ' + (it.sub || '')).toLowerCase().includes(q.trim().toLowerCase()))
    : items;
  return (
    <div onClick={onClose} style={{
      position: 'fixed', inset: 0, zIndex: 1000, background: 'rgba(9,16,32,.5)',
      display: 'flex', alignItems: 'flex-start', justifyContent: 'center', padding: '96px 20px',
      fontFamily: 'var(--mg-font-sans)', ...style,
    }}>
      <div onClick={(e) => e.stopPropagation()} style={{
        width: '100%', maxWidth: 560, background: 'var(--mg-surface-card)',
        border: '1px solid var(--mg-border-default)', borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-lg)', overflow: 'hidden',
      }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 11, padding: '15px 17px', borderBottom: '1px solid var(--mg-border-default)' }}>
          <i className="pi pi-search" style={{ color: 'var(--mg-text-muted)' }} />
          <input autoFocus value={q} onChange={(e) => setQ(e.target.value)} placeholder={placeholder}
            style={{ flex: 1, border: 'none', outline: 'none', background: 'transparent', fontSize: 15, color: 'var(--mg-text-primary)', fontFamily: 'inherit' }} />
          <span style={{ fontSize: 11, fontWeight: 600, color: 'var(--mg-text-muted)', border: '1px solid var(--mg-border-default)', borderRadius: 6, padding: '2px 7px' }}>{hint}</span>
        </div>
        <div style={{ maxHeight: 320, overflowY: 'auto', padding: 6 }}>
          {filtered.length === 0 && (
            <div style={{ padding: '22px 16px', textAlign: 'center', fontSize: 13, color: 'var(--mg-text-muted)' }}>Ничего не найдено</div>
          )}
          {filtered.map((it, i) => (
            <div key={i} onClick={it.onSelect} style={{
              display: 'flex', alignItems: 'center', gap: 12, padding: '11px 12px', borderRadius: 'var(--mg-radius-md)', cursor: 'pointer',
              background: it.active ? 'var(--mg-primary-100)' : 'transparent',
            }}>
              <i className={`pi ${it.icon || 'pi-arrow-right'}`} style={{ fontSize: 15, color: it.active ? 'var(--mg-primary-900)' : 'var(--mg-text-muted)', width: 18, textAlign: 'center' }} />
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ fontSize: 14, color: 'var(--mg-text-primary)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{it.label}</div>
                {it.sub && <div style={{ fontSize: 12, color: 'var(--mg-text-muted)' }}>{it.sub}</div>}
              </div>
              {it.kbd && <span style={{ fontSize: 11, color: 'var(--mg-text-muted)' }}>{it.kbd}</span>}
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
