import React from 'react';

/**
 * MACRO Global CRM — DataTable
 * Config-driven table: the single most-used CRM surface. Density-aware
 * (--mg-cell-py), zebra rows, sticky header, optional row selection + sort UI.
 *
 * columns: [{ key, label, width, align, sortable, render(row, i) }]
 * rows:    array of objects (row[column.key] used unless render() given)
 */
export function DataTable({
  columns = [], rows = [], rowKey = 'id',
  selectable = false, selected = [], onToggle, onToggleAll,
  sortKey, sortDir = 'asc', onSort, zebra = true, onRowClick, empty, style,
}) {
  const allOn = selectable && rows.length > 0 && rows.every((r) => selected.includes(r[rowKey]));
  const someOn = selectable && selected.length > 0 && !allOn;
  const Check = ({ on, dash, onClick }) => (
    <span onClick={(e) => { e.stopPropagation(); onClick && onClick(); }} style={{
      display: 'inline-flex', width: 17, height: 17, borderRadius: 4, flexShrink: 0, cursor: 'pointer',
      border: '1.5px solid ' + (on || dash ? 'var(--mg-primary-900)' : 'var(--mg-border-strong)'),
      background: on ? 'var(--mg-primary-900)' : 'transparent', alignItems: 'center', justifyContent: 'center',
    }}>{on ? <i className="pi pi-check" style={{ fontSize: 9, color: '#fff' }} /> : dash ? <span style={{ width: 8, height: 2, background: 'var(--mg-primary-900)' }} /> : null}</span>
  );
  const th = {
    padding: 'var(--mg-row-py, 8px) 14px', fontSize: 12, fontWeight: 600, textAlign: 'start',
    color: 'var(--mg-text-secondary)', background: 'var(--mg-surface-card)', whiteSpace: 'nowrap',
    borderBottom: '1px solid var(--mg-border-default)', position: 'sticky', top: 0, zIndex: 'var(--mg-z-sticky, 100)',
  };
  return (
    <div style={{ background: 'var(--mg-surface-card)', border: '1px solid var(--mg-border-default)', borderRadius: 'var(--mg-radius-lg)', overflow: 'hidden', boxShadow: 'var(--mg-shadow-sm)', ...style }}>
      <table style={{ width: '100%', borderCollapse: 'collapse', fontFamily: 'var(--mg-font-sans)' }}>
        <thead><tr>
          {selectable && <th style={{ ...th, width: 40, textAlign: 'center' }}><Check on={allOn} dash={someOn} onClick={onToggleAll} /></th>}
          {columns.map((c) => (
            <th key={c.key} onClick={() => c.sortable && onSort && onSort(c.key)}
              style={{ ...th, width: c.width, textAlign: c.align || 'start', cursor: c.sortable ? 'pointer' : 'default' }}>
              <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}>{c.label}
                {c.sortable && <i className={'pi ' + (sortKey === c.key ? (sortDir === 'asc' ? 'pi-sort-up' : 'pi-sort-down') : 'pi-sort-alt')}
                  style={{ fontSize: 11, color: sortKey === c.key ? 'var(--mg-primary-900)' : 'var(--mg-text-muted)' }} />}</span>
            </th>
          ))}
        </tr></thead>
        <tbody>
          {rows.length === 0 && (
            <tr><td colSpan={columns.length + (selectable ? 1 : 0)} style={{ padding: 0 }}>{empty || <div style={{ padding: '40px', textAlign: 'center', color: 'var(--mg-text-muted)', fontSize: 13 }}>Записи не найдены</div>}</td></tr>
          )}
          {rows.map((r, i) => {
            const sel = selectable && selected.includes(r[rowKey]);
            return (
              <tr key={r[rowKey] ?? i} onClick={() => onRowClick && onRowClick(r)}
                style={{ background: sel ? 'var(--mg-primary-100)' : zebra && i % 2 ? 'var(--mg-surface-hover)' : 'var(--mg-surface-card)', cursor: onRowClick ? 'pointer' : 'default' }}>
                {selectable && <td style={{ padding: 'var(--mg-cell-py, 10px) 14px', textAlign: 'center', borderBottom: '1px solid var(--mg-border-default)' }}><Check on={sel} onClick={() => onToggle && onToggle(r[rowKey])} /></td>}
                {columns.map((c) => (
                  <td key={c.key} style={{ padding: 'var(--mg-cell-py, 10px) 14px', fontSize: 13, color: 'var(--mg-text-primary)', textAlign: c.align || 'start', borderBottom: '1px solid var(--mg-border-default)', verticalAlign: 'middle' }}>
                    {c.render ? c.render(r, i) : r[c.key]}
                  </td>
                ))}
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}
