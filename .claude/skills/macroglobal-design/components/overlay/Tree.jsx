import React from 'react';

/**
 * MACRO Global CRM — Tree
 * Hierarchy view (property → building → entrance → units). Recursive nodes:
 * { label, icon, count, defaultExpanded, children: [...] }. RTL-safe via
 * logical padding-inline-start indentation.
 */
function TreeNode({ node, depth }) {
  const [open, setOpen] = React.useState(node.defaultExpanded !== false);
  const hasChildren = node.children && node.children.length > 0;
  return (
    <div>
      <div onClick={() => hasChildren && setOpen(!open)} style={{
        display: 'flex', alignItems: 'center', gap: 9, padding: '7px 8px',
        paddingInlineStart: 8 + depth * 20, borderRadius: 'var(--mg-radius-sm)',
        cursor: hasChildren ? 'pointer' : 'default', fontSize: 14, color: 'var(--mg-text-primary)',
      }}>
        {hasChildren
          ? <i className={`pi ${open ? 'pi-chevron-down' : 'pi-chevron-right'} mg-flip-rtl`} style={{ fontSize: 11, color: 'var(--mg-text-muted)', width: 12 }} />
          : <span style={{ width: 12, display: 'inline-block' }} />}
        {node.icon && <i className={`pi ${node.icon}`} style={{ fontSize: 14, color: depth === 0 ? 'var(--mg-primary-900)' : 'var(--mg-text-secondary)' }} />}
        <span style={{ flex: 1, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{node.label}</span>
        {node.count != null && <span style={{ fontSize: 12, color: 'var(--mg-text-muted)' }}>{node.count}</span>}
      </div>
      {hasChildren && open && node.children.map((c, i) => <TreeNode key={i} node={c} depth={depth + 1} />)}
    </div>
  );
}

export function Tree({ nodes = [], style }) {
  return (
    <div style={{ fontFamily: 'var(--mg-font-sans)', display: 'flex', flexDirection: 'column', gap: 1, ...style }}>
      {nodes.map((n, i) => <TreeNode key={i} node={n} depth={0} />)}
    </div>
  );
}
