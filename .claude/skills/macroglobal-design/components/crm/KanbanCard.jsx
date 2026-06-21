import React from 'react';
import { Avatar } from '../data/Avatar.jsx';

/**
 * MACRO Global CRM — KanbanCard (deal card)
 * Faithful recreation of DealsKanbanCard: title, amount + product chip,
 * owner + days-in-stage row, and a bottom "health strip" (ok / no-task / overdue)
 * with a colored left inset border.
 */
export function KanbanCard({
  title, amount = '0 ₽', product, owner = '—', daysInStage = 0,
  health = 'ok', task, rotting = false, selected = false, onClick, style,
}) {
  const [hover, setHover] = React.useState(false);
  const strip = {
    ok: ['var(--mg-gray-50)', 'var(--mg-text-secondary)'],
    'no-task': ['var(--mg-status-warning-bg)', 'var(--mg-status-warning-text)'],
    overdue: ['var(--mg-status-danger-bg)', 'var(--mg-status-danger-text)'],
  }[health] || ['var(--mg-gray-50)', 'var(--mg-text-secondary)'];

  const inset = health === 'no-task'
    ? 'inset 4px 0 0 var(--mg-warning)'
    : health === 'overdue' ? 'inset 4px 0 0 var(--mg-danger)' : 'none';
  const daysColor = rotting ? 'var(--mg-danger)' : 'var(--mg-gray-500)';

  return (
    <div
      onClick={onClick}
      onMouseEnter={() => setHover(true)}
      onMouseLeave={() => setHover(false)}
      style={{
        background: hover ? 'var(--mg-gray-50)' : 'var(--mg-surface-card)',
        border: `1px solid ${selected ? 'var(--mg-primary-900)' : health === 'overdue' ? 'var(--mg-danger)' : 'var(--mg-border-default)'}`,
        borderWidth: selected ? 2 : 1,
        borderRadius: 'var(--mg-radius-md)', overflow: 'hidden', cursor: 'pointer',
        boxShadow: hover ? 'var(--mg-shadow-card)' : 'none', fontFamily: 'var(--mg-font-sans)',
        transition: 'box-shadow var(--mg-transition-fast), background var(--mg-transition-fast)',
        ...style,
      }}
    >
      <div style={{ padding: '12px', boxShadow: inset }}>
        <div style={{ fontSize: '14px', fontWeight: 600, color: 'var(--mg-gray-800)', marginBottom: '8px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{title}</div>
        <div style={{ display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '8px' }}>
          <span style={{ fontSize: '12px', fontWeight: 700, color: 'var(--mg-primary-900)', whiteSpace: 'nowrap' }}>{amount}</span>
          {product && (
            <span style={{ display: 'inline-flex', alignItems: 'center', gap: '3px', background: 'var(--mg-gray-100)', borderRadius: 'var(--mg-radius-sm)', padding: '1px 6px', fontSize: '11px', color: 'var(--mg-gray-600)', overflow: 'hidden' }}>
              <i className="pi pi-box" style={{ fontSize: '10px' }} />
              <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', maxWidth: '100px' }}>{product}</span>
            </span>
          )}
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
          <span style={{ display: 'flex', alignItems: 'center', gap: '5px', flex: 1, minWidth: 0 }}>
            <Avatar name={owner} size={20} />
            <span style={{ fontSize: '12px', color: 'var(--mg-gray-500)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{owner}</span>
          </span>
          <span style={{ display: 'flex', alignItems: 'center', gap: '2px', fontSize: '12px', color: daysColor, whiteSpace: 'nowrap' }}>
            <i className="pi pi-clock" style={{ fontSize: '11px' }} />{daysInStage} дн.
          </span>
        </div>
      </div>
      <div style={{
        display: 'flex', alignItems: 'center', gap: '8px', padding: '7px 12px',
        borderTop: '1px solid var(--mg-border-default)', background: strip[0],
        fontSize: '11px', color: strip[1], minHeight: '28px',
      }}>
        {health === 'overdue' && <i className="pi pi-exclamation-circle" style={{ fontSize: '11px' }} />}
        {health === 'ok' && task && <i className="pi pi-phone" style={{ fontSize: '11px', color: 'var(--mg-gray-500)' }} />}
        <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', flex: 1, fontWeight: health === 'overdue' ? 600 : 400 }}>
          {health === 'no-task' ? 'Нет задачи' : task || 'Сегодня 14:00'}
        </span>
        {health === 'no-task' && <span style={{ color: 'var(--mg-primary-900)', fontWeight: 600 }}>Поставить</span>}
      </div>
    </div>
  );
}
