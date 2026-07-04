/* @ds-bundle: {"format":4,"namespace":"MACROGlobalCRMDesignSystem_2f42e6","components":[{"name":"KanbanCard","sourcePath":"components/crm/KanbanCard.jsx"},{"name":"Stepper","sourcePath":"components/crm/Stepper.jsx"},{"name":"Avatar","sourcePath":"components/data/Avatar.jsx"},{"name":"AvatarGroup","sourcePath":"components/data/AvatarGroup.jsx"},{"name":"Badge","sourcePath":"components/data/Badge.jsx"},{"name":"Card","sourcePath":"components/data/Card.jsx"},{"name":"DataTable","sourcePath":"components/data/DataTable.jsx"},{"name":"NotificationBadge","sourcePath":"components/data/NotificationBadge.jsx"},{"name":"StatCard","sourcePath":"components/data/StatCard.jsx"},{"name":"Tag","sourcePath":"components/data/Tag.jsx"},{"name":"EmptyState","sourcePath":"components/feedback/EmptyState.jsx"},{"name":"Skeleton","sourcePath":"components/feedback/Skeleton.jsx"},{"name":"Toast","sourcePath":"components/feedback/Toast.jsx"},{"name":"Button","sourcePath":"components/forms/Button.jsx"},{"name":"Checkbox","sourcePath":"components/forms/Checkbox.jsx"},{"name":"Input","sourcePath":"components/forms/Input.jsx"},{"name":"Select","sourcePath":"components/forms/Select.jsx"},{"name":"Switch","sourcePath":"components/forms/Switch.jsx"},{"name":"PageHeader","sourcePath":"components/nav/PageHeader.jsx"},{"name":"Pagination","sourcePath":"components/nav/Pagination.jsx"},{"name":"SegmentedControl","sourcePath":"components/nav/SegmentedControl.jsx"},{"name":"Tabs","sourcePath":"components/nav/Tabs.jsx"},{"name":"CommandPalette","sourcePath":"components/overlay/CommandPalette.jsx"},{"name":"Dialog","sourcePath":"components/overlay/Dialog.jsx"},{"name":"Menu","sourcePath":"components/overlay/Menu.jsx"},{"name":"Tooltip","sourcePath":"components/overlay/Tooltip.jsx"},{"name":"Tree","sourcePath":"components/overlay/Tree.jsx"}],"sourceHashes":{"access-section.jsx":"8e3d5304ff63","components/crm/KanbanCard.jsx":"13b0f08db43f","components/crm/Stepper.jsx":"2de5e19d558b","components/data/Avatar.jsx":"21c147e1dcf7","components/data/AvatarGroup.jsx":"aead87e432be","components/data/Badge.jsx":"3a49647ac718","components/data/Card.jsx":"9807cdea80f9","components/data/DataTable.jsx":"c69d928aba55","components/data/NotificationBadge.jsx":"edffcf0bfb43","components/data/StatCard.jsx":"7b3d076b2c2a","components/data/Tag.jsx":"fc1b4b642cef","components/feedback/EmptyState.jsx":"a05c8d7679f6","components/feedback/Skeleton.jsx":"947359d921db","components/feedback/Toast.jsx":"bb006dd6f4a3","components/forms/Button.jsx":"d97fe4f7dcde","components/forms/Checkbox.jsx":"8bb59607939b","components/forms/Input.jsx":"c32e1b2412af","components/forms/Select.jsx":"d266403078af","components/forms/Switch.jsx":"33ce97a92a4e","components/nav/PageHeader.jsx":"7ac5fe243593","components/nav/Pagination.jsx":"370b95391a9e","components/nav/SegmentedControl.jsx":"8fd6993b3021","components/nav/Tabs.jsx":"7795c111bf74","components/overlay/CommandPalette.jsx":"b09dd4a49a35","components/overlay/Dialog.jsx":"59b1dce17f77","components/overlay/Menu.jsx":"c84d56fc5eea","components/overlay/Tooltip.jsx":"1b93e5922cc9","components/overlay/Tree.jsx":"f3c0fa7f576b","exports/design-handoff/redesign/tweaks-panel.jsx":"6591467622ed","exports/mgcrm-package/design-handoff/redesign/access-section.jsx":"2d06ff94a05a","exports/mgcrm-package/design-handoff/redesign/system-section.jsx":"7de2e67201c7","exports/mgcrm-package/design-handoff/redesign/tweaks-panel.jsx":"6591467622ed","exports/mgcrm-package/design-handoff/redesign/users-section.jsx":"a11316573b8b","redesign/_backup_2026-07-04/tweaks-panel.jsx":"6591467622ed","redesign/_dh_update/redesign/access-section.jsx":"2d06ff94a05a","redesign/_dh_update/redesign/system-section.jsx":"7de2e67201c7","redesign/_dh_update/redesign/users-section.jsx":"a11316573b8b","redesign/access-section.jsx":"2d06ff94a05a","redesign/handoff_settings_redesign/design/access-section.jsx":"2d06ff94a05a","redesign/handoff_settings_redesign/design/system-section.jsx":"7de2e67201c7","redesign/handoff_settings_redesign/design/users-section.jsx":"a11316573b8b","redesign/system-section.jsx":"7de2e67201c7","redesign/tweaks-panel.jsx":"6591467622ed","redesign/users-section.jsx":"a11316573b8b","ui_kits/crm/Sidebar.jsx":"68d9775f8e71"},"inlinedExternals":[],"unexposedExports":[]} */

(() => {

const __ds_ns = (window.MACROGlobalCRMDesignSystem_2f42e6 = window.MACROGlobalCRMDesignSystem_2f42e6 || {});

const __ds_scope = {};

(__ds_ns.__errors = __ds_ns.__errors || []);

// access-section.jsx
try { (() => {
/* Доступ и оргструктура — Настройки → Система.
   Mirrors front/src/pages/AccessControlPage (index + DepartmentsTab, DepartmentTree,
   DepartmentSidePanel, OrgChartView, RolesPermissionsTab, PermissionMatrix,
   VisibilityScopeTab) + entities/accessControl + ru.json. Redesigned look.
   Exposes window.AccessTab. */
(function () {
  const {
    useState,
    useRef,
    useEffect
  } = React;

  /* ── i18n (ru.json → accessControl) ── */
  const A_T = {
    title: 'Доступ и оргструктура',
    subtitle: 'Отделы, роли и видимость записей',
    tabs: {
      departments: 'Отделы',
      roles: 'Роли и права',
      visibility: 'Видимость'
    },
    dep: {
      add: 'Добавить отдел',
      edit: 'Редактировать отдел',
      del: 'Удалить отдел',
      delConfirm: n => 'Удалить отдел «' + n + '»? Дочерние отделы и сотрудники останутся без родителя.',
      name: 'Название',
      parent: 'Родительский отдел',
      root: '— Корень —',
      manager: 'Руководитель',
      members: 'Сотрудники отдела',
      addMember: 'Добавить сотрудника',
      removeMember: 'Убрать из отдела',
      viewTree: 'Дерево',
      viewChart: 'Схема',
      empty: 'Отделы не созданы',
      emptyHint: 'Добавьте первый отдел, чтобы настроить оргструктуру',
      noMembers: 'Нет участников',
      saved: 'Отдел сохранён',
      deleted: 'Отдел удалён'
    },
    roles: {
      title: 'Матрица прав',
      adminNote: 'Роль admin всегда получает все права и не может быть ограничена.',
      permissionLabel: 'Право',
      save: 'Сохранить',
      reset: 'Сбросить',
      saved: 'Права ролей сохранены'
    },
    vis: {
      title: 'Видимость записей',
      warning: 'Настройки видимости влияют на то, какие записи (сделки, контакты, задачи) видит каждая роль. Изменяйте с осторожностью.',
      scopeAll: 'Все',
      scopeDepartment: 'Отдел (+подотделы)',
      scopeOwn: 'Свои',
      roleColumn: 'Роль',
      scopeColumn: 'Видимость записей',
      departmentHint: 'Значение «Отдел» работает только если у пользователя указан отдел.',
      save: 'Сохранить',
      reset: 'Сбросить',
      saved: 'Настройки видимости сохранены'
    }
  };
  const A_ROLES = ['admin', 'director', 'lawyer', 'manager', 'accountant', 'cfo'];
  const A_ROLE_LABEL = {
    admin: 'Администратор',
    director: 'Директор',
    lawyer: 'Юрист',
    manager: 'Менеджер',
    accountant: 'Бухгалтер',
    cfo: 'Финансовый директор'
  };
  const A_ROLE_SHORT = {
    admin: 'Админ',
    director: 'Директор',
    lawyer: 'Юрист',
    manager: 'Менеджер',
    accountant: 'Бухгалтер',
    cfo: 'Фин. дир.'
  };
  const A_ROLE_SEV = {
    admin: 'danger',
    director: 'warn',
    lawyer: 'info',
    manager: 'success',
    accountant: 'secondary',
    cfo: 'secondary'
  };
  const A_GROUPS = [{
    key: 'crm',
    label: 'CRM',
    perms: ['crm.view', 'crm.manage']
  }, {
    key: 'sales',
    label: 'Продажи',
    perms: ['sales.view', 'sales.manage']
  }, {
    key: 'contracts',
    label: 'Договоры',
    perms: ['contracts.view', 'contracts.manage']
  }, {
    key: 'users',
    label: 'Пользователи',
    perms: ['users.view', 'users.manage']
  }, {
    key: 'automation',
    label: 'Автоматизации',
    perms: ['automation.manage']
  }, {
    key: 'analytics',
    label: 'Аналитика',
    perms: ['analytics.view', 'settings.manage']
  }, {
    key: 'finance',
    label: 'Финансы',
    perms: ['finance.view', 'finance.entry', 'finance.posting', 'finance.journals.manual', 'finance.payments.approve', 'finance.period.close', 'finance.settings.manage', 'finance.reports.management']
  }, {
    key: 'system',
    label: 'Системные права',
    perms: ['admin-write', 'dedup-scan-all', 'view-manager-cabinet', 'system-reset']
  }];
  const A_PERM_LABEL = {
    'crm.view': 'CRM — просмотр',
    'crm.manage': 'CRM — управление',
    'sales.view': 'Продажи — просмотр',
    'sales.manage': 'Продажи — управление',
    'contracts.view': 'Договоры — просмотр',
    'contracts.manage': 'Договоры — управление',
    'users.view': 'Пользователи — просмотр',
    'users.manage': 'Пользователи — управление',
    'automation.manage': 'Автоматизации — управление',
    'analytics.view': 'Аналитика — просмотр',
    'settings.manage': 'Настройки системы — управление',
    'finance.view': 'Финансы — просмотр',
    'finance.entry': 'Финансы — ввод операций',
    'finance.posting': 'Финансы — проводки',
    'finance.journals.manual': 'Финансы — ручные журналы',
    'finance.payments.approve': 'Финансы — согласование платежей',
    'finance.period.close': 'Финансы — закрытие периода',
    'finance.settings.manage': 'Финансы — настройки',
    'finance.reports.management': 'Финансы — управленческие отчёты',
    'admin-write': 'Системные изменения (админ)',
    'dedup-scan-all': 'Дедупликация — полное сканирование',
    'view-manager-cabinet': 'Кабинет менеджера — доступ',
    'system-reset': 'Сброс системы'
  };
  /* default role → granted permissions */
  const A_DEFAULT_PERMS = {
    admin: A_GROUPS.flatMap(g => g.perms),
    director: ['crm.view', 'crm.manage', 'sales.view', 'sales.manage', 'contracts.view', 'contracts.manage', 'users.view', 'automation.manage', 'analytics.view', 'settings.manage', 'finance.view', 'view-manager-cabinet'],
    lawyer: ['crm.view', 'contracts.view', 'contracts.manage'],
    manager: ['crm.view', 'sales.view', 'sales.manage', 'contracts.view'],
    accountant: ['crm.view', 'finance.view', 'finance.entry', 'finance.posting', 'finance.journals.manual'],
    cfo: ['crm.view', 'analytics.view', 'finance.view', 'finance.entry', 'finance.posting', 'finance.payments.approve', 'finance.period.close', 'finance.settings.manage', 'finance.reports.management']
  };
  const A_DEFAULT_VIS = {
    admin: 'all',
    director: 'all',
    lawyer: 'department',
    manager: 'own',
    accountant: 'department',
    cfo: 'all'
  };
  const A_M = (id, full_name, email, role) => ({
    id,
    full_name,
    email,
    role
  });
  const A_TREE0 = [{
    id: 1,
    name: 'Руководство',
    manager: 'Директор Петров П.П.',
    members: [A_M(6, 'Директор Петров П.П.', 'director@mgcrm.test', 'director'), A_M(2, 'Bogdan Yadykin', 'b.yadykin@macroglobaltech.com', 'admin')],
    children: [{
      id: 2,
      name: 'Отдел продаж',
      manager: 'Иванов Алексей Сергеевич',
      members: [A_M(7, 'Иванов Алексей Сергеевич', 'manager1@mgcrm.test', 'manager'), A_M(8, 'Илья Рогов', 'ilyarogov.mera@gmail.com', 'manager'), A_M(9, 'Клим Федорин', 'k.fedorin@macroglobaltech.com', 'manager'), A_M(10, 'Олеся Моисеева', 'o.moiseeva@macroglobaltech.com', 'manager'), A_M(5, 'Георгий Некрасов', 'g.nekrasov@macroglobaltech.com', 'manager'), A_M(11, 'Петрова Мария Сергеевна', 'manager2@mgcrm.test', 'manager')],
      children: [{
        id: 5,
        name: 'Группа B2B',
        manager: 'Петрова Мария Сергеевна',
        members: [A_M(7, 'Иванов Алексей Сергеевич', 'manager1@mgcrm.test', 'manager'), A_M(9, 'Клим Федорин', 'k.fedorin@macroglobaltech.com', 'manager'), A_M(5, 'Георгий Некрасов', 'g.nekrasov@macroglobaltech.com', 'manager')],
        children: []
      }]
    }, {
      id: 3,
      name: 'Финансы',
      manager: 'Сергей Казначеев',
      members: [A_M(13, 'Сергей Казначеев', 'cfo@mgcrm.test', 'cfo')],
      children: [{
        id: 6,
        name: 'Бухгалтерия',
        manager: 'Анна Счётова',
        members: [A_M(12, 'Анна Счётова', 'accountant@mgcrm.test', 'accountant')],
        children: []
      }]
    }, {
      id: 4,
      name: 'Юридический отдел',
      manager: 'Lawyer Test',
      members: [A_M(3, 'Lawyer Test', 'lawyer@mgcrm.test', 'lawyer')],
      children: []
    }]
  }];
  const A_ALL_USERS = [A_M(1, 'Admin', 'svkv42@gmail.com', 'admin'), A_M(2, 'Bogdan Yadykin', 'b.yadykin@macroglobaltech.com', 'admin'), A_M(3, 'Lawyer Test', 'lawyer@mgcrm.test', 'lawyer'), A_M(5, 'Георгий Некрасов', 'g.nekrasov@macroglobaltech.com', 'manager'), A_M(6, 'Директор Петров П.П.', 'director@mgcrm.test', 'director'), A_M(7, 'Иванов Алексей Сергеевич', 'manager1@mgcrm.test', 'manager'), A_M(8, 'Илья Рогов', 'ilyarogov.mera@gmail.com', 'manager'), A_M(9, 'Клим Федорин', 'k.fedorin@macroglobaltech.com', 'manager'), A_M(10, 'Олеся Моисеева', 'o.moiseeva@macroglobaltech.com', 'manager'), A_M(11, 'Петрова Мария Сергеевна', 'manager2@mgcrm.test', 'manager'), A_M(12, 'Анна Счётова', 'accountant@mgcrm.test', 'accountant'), A_M(13, 'Сергей Казначеев', 'cfo@mgcrm.test', 'cfo')];

  /* ── shared primitives ── */
  const A_SEV = {
    success: {
      bg: 'var(--mg-status-success-bg)',
      fg: 'var(--mg-status-success-text)'
    },
    danger: {
      bg: 'var(--mg-status-danger-bg)',
      fg: 'var(--mg-status-danger-text)'
    },
    warn: {
      bg: 'var(--mg-status-warning-bg)',
      fg: 'var(--mg-status-warning-text)'
    },
    info: {
      bg: 'var(--mg-status-info-bg)',
      fg: 'var(--mg-status-info-text)'
    },
    secondary: {
      bg: 'var(--c-muted2)',
      fg: 'var(--c-text2)'
    }
  };
  function A_btn(kind) {
    const base = {
      display: 'inline-flex',
      alignItems: 'center',
      gap: 7,
      height: 38,
      padding: '0 15px',
      borderRadius: 'var(--mg-radius-md)',
      fontFamily: 'var(--mg-font-sans)',
      fontSize: 13,
      fontWeight: 600,
      cursor: 'pointer',
      border: 'none',
      whiteSpace: 'nowrap'
    };
    if (kind === 'primary') return {
      ...base,
      background: 'var(--mg-primary-900)',
      color: '#fff'
    };
    if (kind === 'danger') return {
      ...base,
      background: 'var(--mg-status-danger-solid)',
      color: '#fff'
    };
    if (kind === 'text') return {
      ...base,
      background: 'transparent',
      color: 'var(--c-text2)'
    };
    return {
      ...base,
      background: 'transparent',
      color: 'var(--c-text2)',
      border: '1px solid var(--c-border2)'
    };
  }
  function A_sbtn(kind, active) {
    const b = A_btn(kind === 'p' ? 'primary' : 'ghost');
    return {
      ...b,
      height: 34,
      padding: '0 12px',
      fontSize: 12.5,
      ...(active ? {} : {})
    };
  }
  function A_Tag({
    value,
    severity
  }) {
    const c = A_SEV[severity] || A_SEV.secondary;
    return /*#__PURE__*/React.createElement("span", {
      style: {
        display: 'inline-flex',
        alignItems: 'center',
        fontSize: 11.5,
        fontWeight: 600,
        padding: '3px 9px',
        borderRadius: 6,
        background: c.bg,
        color: c.fg,
        whiteSpace: 'nowrap'
      }
    }, value);
  }
  function A_Msg({
    severity,
    children
  }) {
    const map = {
      warn: {
        bg: 'var(--mg-status-warning-bg)',
        bd: 'var(--mg-status-warning-border)',
        fg: 'var(--mg-status-warning-text)',
        ic: 'pi-exclamation-triangle'
      },
      secondary: {
        bg: 'var(--c-hover)',
        bd: 'var(--c-border)',
        fg: 'var(--c-text2)',
        ic: 'pi-info-circle'
      }
    };
    const c = map[severity] || map.secondary;
    return /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'flex-start',
        gap: 10,
        padding: '11px 14px',
        background: c.bg,
        border: '1px solid ' + c.bd,
        borderRadius: 'var(--mg-radius-md)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + c.ic,
      style: {
        fontSize: 14,
        color: c.fg,
        marginTop: 1,
        flexShrink: 0
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 13,
        color: c.fg,
        lineHeight: 1.5
      }
    }, children));
  }
  function A_Check({
    checked,
    disabled,
    onChange
  }) {
    return /*#__PURE__*/React.createElement("span", {
      onClick: () => !disabled && onChange(!checked),
      style: {
        display: 'inline-flex',
        width: 20,
        height: 20,
        borderRadius: 5,
        border: '1.5px solid ' + (checked ? 'var(--mg-primary-900)' : 'var(--c-border2)'),
        background: checked ? 'var(--mg-primary-900)' : 'var(--c-card)',
        alignItems: 'center',
        justifyContent: 'center',
        cursor: disabled ? 'not-allowed' : 'pointer',
        opacity: disabled ? 0.5 : 1
      }
    }, checked && /*#__PURE__*/React.createElement("i", {
      className: "pi pi-check",
      style: {
        fontSize: 11,
        color: '#fff',
        fontWeight: 700
      }
    }));
  }
  function A_Dropdown({
    value,
    onChange,
    options,
    placeholder,
    width,
    filter
  }) {
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const ref = useRef(null);
    useEffect(() => {
      const h = e => {
        if (ref.current && !ref.current.contains(e.target)) setOpen(false);
      };
      document.addEventListener('mousedown', h);
      return () => document.removeEventListener('mousedown', h);
    }, []);
    const sel = options.find(o => o.value === value);
    const list = filter && q ? options.filter(o => o.label.toLowerCase().includes(q.toLowerCase())) : options;
    return /*#__PURE__*/React.createElement("div", {
      ref: ref,
      style: {
        position: 'relative',
        width: width || '100%'
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: () => setOpen(!open),
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        height: 40,
        padding: '0 12px',
        border: '1px solid ' + (open ? 'var(--mg-input-focus-border)' : 'var(--mg-input-border)'),
        borderRadius: 'var(--mg-radius-md)',
        background: 'var(--c-card)',
        cursor: 'pointer',
        boxShadow: open ? 'var(--mg-focus-ring)' : 'none'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 14,
        color: sel ? 'var(--c-text)' : 'var(--c-muted)',
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, sel ? sel.label : placeholder), /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + (open ? 'pi-chevron-up' : 'pi-chevron-down'),
      style: {
        fontSize: 12,
        color: 'var(--c-muted)'
      }
    })), open && /*#__PURE__*/React.createElement("div", {
      style: {
        position: 'absolute',
        top: 44,
        insetInlineStart: 0,
        zIndex: 40,
        width: '100%',
        minWidth: 180,
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-md)',
        boxShadow: 'var(--mg-shadow-lg)',
        padding: 5,
        maxHeight: 260,
        overflowY: 'auto'
      }
    }, filter && /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 7,
        height: 34,
        padding: '0 10px',
        margin: '2px 2px 6px',
        border: '1px solid var(--c-border2)',
        borderRadius: 'var(--mg-radius-sm)',
        background: 'var(--c-page)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-search",
      style: {
        fontSize: 12,
        color: 'var(--c-muted)'
      }
    }), /*#__PURE__*/React.createElement("input", {
      autoFocus: true,
      value: q,
      onChange: e => setQ(e.target.value),
      placeholder: "\u041F\u043E\u0438\u0441\u043A\u2026",
      style: {
        flex: 1,
        border: 'none',
        outline: 'none',
        background: 'transparent',
        fontFamily: 'var(--mg-font-sans)',
        fontSize: 13,
        color: 'var(--c-text)'
      }
    })), list.map(o => {
      const on = o.value === value;
      return /*#__PURE__*/React.createElement("div", {
        key: String(o.value),
        onClick: () => {
          onChange(o.value);
          setOpen(false);
          setQ('');
        },
        style: {
          display: 'flex',
          alignItems: 'center',
          gap: 8,
          padding: '8px 10px',
          borderRadius: 7,
          cursor: 'pointer',
          fontSize: 13.5,
          fontWeight: on ? 600 : 500,
          color: on ? 'var(--mg-primary-900)' : 'var(--c-text)',
          background: on ? 'var(--mg-primary-100)' : 'transparent'
        }
      }, o.label, on && /*#__PURE__*/React.createElement("i", {
        className: "pi pi-check",
        style: {
          marginInlineStart: 'auto',
          fontSize: 12
        }
      }));
    })));
  }
  function A_avatar(name, role, size) {
    const grad = {
      admin: 'linear-gradient(135deg,#4C7DF0,#2B4987)',
      director: 'linear-gradient(135deg,#E6A46E,#C67E3C)',
      cfo: 'linear-gradient(135deg,#E6A46E,#C67E3C)',
      lawyer: 'linear-gradient(135deg,#78B0E0,#4A82BC)',
      manager: 'linear-gradient(135deg,#4FBF8F,#2E9469)',
      accountant: 'linear-gradient(135deg,#9AA3B2,#6C7688)'
    };
    const w = name.trim().split(/\s+/);
    const ini = (w.length >= 2 ? w[0][0] + w[1][0] : w[0].slice(0, 2)).toUpperCase();
    return /*#__PURE__*/React.createElement("span", {
      style: {
        width: size,
        height: size,
        borderRadius: '50%',
        flexShrink: 0,
        background: grad[role] || grad.accountant,
        color: '#fff',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        fontSize: size * 0.36,
        fontWeight: 600
      }
    }, ini);
  }

  /* ── tree helpers ── */
  function A_walkUpdate(nodes, id, fn) {
    return nodes.map(n => n.id === id ? fn(n) : {
      ...n,
      children: A_walkUpdate(n.children, id, fn)
    });
  }
  function A_flatten(nodes, depth, acc) {
    acc = acc || [];
    nodes.forEach(n => {
      acc.push({
        id: n.id,
        name: n.name,
        depth: depth || 0
      });
      A_flatten(n.children, (depth || 0) + 1, acc);
    });
    return acc;
  }
  function A_removeNode(nodes, id) {
    const kept = [];
    let orphans = [];
    nodes.forEach(n => {
      if (n.id === id) {
        orphans = orphans.concat(n.children);
      } else {
        const r = A_removeNode(n.children, id);
        n = {
          ...n,
          children: r.nodes
        };
        orphans = orphans.concat(r.orphans);
        kept.push(n);
      }
    });
    return {
      nodes: kept,
      orphans
    };
  }
  function A_countAll(n) {
    return 1;
  }

  /* ── Department tree (redesigned) ── */
  function A_TreeNode({
    node,
    depth,
    selectedId,
    onSelect,
    onEdit,
    onDelete,
    expanded,
    toggle
  }) {
    const isOpen = expanded[node.id] !== false;
    const on = selectedId === node.id;
    return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("div", {
      className: "atnode",
      onClick: () => onSelect(node),
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        padding: '9px 12px',
        paddingInlineStart: 12 + depth * 22,
        borderRadius: 'var(--mg-radius-md)',
        cursor: 'pointer',
        background: on ? 'var(--mg-primary-100)' : 'transparent',
        transition: 'background .12s'
      }
    }, node.children.length > 0 ? /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + (isOpen ? 'pi-chevron-down' : 'pi-chevron-right'),
      onClick: e => {
        e.stopPropagation();
        toggle(node.id);
      },
      style: {
        fontSize: 11,
        color: 'var(--c-muted)',
        width: 14,
        cursor: 'pointer'
      }
    }) : /*#__PURE__*/React.createElement("span", {
      style: {
        width: 14
      }
    }), /*#__PURE__*/React.createElement("i", {
      className: "pi pi-building",
      style: {
        fontSize: 14,
        color: on ? 'var(--mg-primary-900)' : 'var(--c-muted)'
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 13.5,
        fontWeight: on ? 600 : 500,
        color: on ? 'var(--mg-primary-900)' : 'var(--c-text)',
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, node.name), /*#__PURE__*/React.createElement("span", {
      style: {
        display: 'inline-flex',
        alignItems: 'center',
        gap: 4,
        fontSize: 12,
        color: 'var(--c-muted)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-users",
      style: {
        fontSize: 11
      }
    }), node.members.length), /*#__PURE__*/React.createElement("span", {
      className: "atacts",
      style: {
        display: 'inline-flex',
        gap: 2,
        opacity: 0,
        transition: 'opacity .12s'
      }
    }, /*#__PURE__*/React.createElement("button", {
      className: "uicon",
      onClick: e => {
        e.stopPropagation();
        onEdit(node);
      },
      title: "\u0420\u0435\u0434\u0430\u043A\u0442\u0438\u0440\u043E\u0432\u0430\u0442\u044C",
      style: {
        width: 28,
        height: 28,
        borderRadius: 7,
        border: 'none',
        background: 'transparent',
        cursor: 'pointer'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-pencil",
      style: {
        fontSize: 12,
        color: 'var(--c-text2)'
      }
    })), /*#__PURE__*/React.createElement("button", {
      className: "uicon",
      onClick: e => {
        e.stopPropagation();
        onDelete(node);
      },
      title: "\u0423\u0434\u0430\u043B\u0438\u0442\u044C",
      style: {
        width: 28,
        height: 28,
        borderRadius: 7,
        border: 'none',
        background: 'transparent',
        cursor: 'pointer'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-trash",
      style: {
        fontSize: 12,
        color: 'var(--mg-status-danger-text)'
      }
    })))), isOpen && node.children.map(c => /*#__PURE__*/React.createElement(A_TreeNode, {
      key: c.id,
      node: c,
      depth: depth + 1,
      selectedId: selectedId,
      onSelect: onSelect,
      onEdit: onEdit,
      onDelete: onDelete,
      expanded: expanded,
      toggle: toggle
    })));
  }

  /* ── Org chart (Схема) ── */
  function A_ChartNode({
    node,
    onSelect
  }) {
    return /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center'
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: () => onSelect(node),
      style: {
        minWidth: 170,
        background: 'var(--c-card)',
        border: '1px solid var(--c-border2)',
        borderRadius: 'var(--mg-radius-md)',
        boxShadow: 'var(--mg-shadow-sm)',
        padding: '11px 14px',
        cursor: 'pointer',
        textAlign: 'center'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 13.5,
        fontWeight: 600,
        color: 'var(--c-text)'
      }
    }, node.name), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 12,
        color: 'var(--c-muted)',
        marginTop: 3
      }
    }, node.manager), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'inline-flex',
        alignItems: 'center',
        gap: 4,
        marginTop: 7,
        fontSize: 11.5,
        fontWeight: 600,
        padding: '2px 8px',
        borderRadius: 999,
        background: 'var(--mg-primary-100)',
        color: 'var(--mg-primary-900)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-users",
      style: {
        fontSize: 10
      }
    }), node.members.length)), node.children.length > 0 && /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("div", {
      style: {
        width: 1,
        height: 20,
        background: 'var(--c-border2)'
      }
    }), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 26,
        position: 'relative',
        paddingTop: 20,
        borderTop: node.children.length > 1 ? '1px solid var(--c-border2)' : 'none'
      }
    }, node.children.map(c => /*#__PURE__*/React.createElement("div", {
      key: c.id,
      style: {
        position: 'relative'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        position: 'absolute',
        top: -20,
        insetInlineStart: '50%',
        width: 1,
        height: 20,
        background: 'var(--c-border2)'
      }
    }), /*#__PURE__*/React.createElement(A_ChartNode, {
      node: c,
      onSelect: onSelect
    }))))));
  }

  /* ── Department side panel (drawer) ── */
  function A_SidePanel({
    mode,
    form,
    setForm,
    parentOptions,
    userOptions,
    onClose,
    onSave,
    onOpenPicker,
    onRemoveMember
  }) {
    const set = (k, v) => setForm(s => ({
      ...s,
      [k]: v
    }));
    return /*#__PURE__*/React.createElement("div", {
      onClick: onClose,
      style: {
        position: 'fixed',
        inset: 0,
        zIndex: 1300,
        background: 'rgba(9,16,32,0.45)',
        display: 'flex',
        justifyContent: 'flex-end'
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: e => e.stopPropagation(),
      style: {
        width: 440,
        maxWidth: '92vw',
        height: '100%',
        background: 'var(--c-card)',
        borderInlineStart: '1px solid var(--c-border)',
        boxShadow: 'var(--mg-shadow-lg)',
        display: 'flex',
        flexDirection: 'column'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '16px 20px',
        borderBottom: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 16,
        fontWeight: 600
      }
    }, mode === 'create' ? A_T.dep.add : A_T.dep.edit), /*#__PURE__*/React.createElement("i", {
      className: "pi pi-times",
      onClick: onClose,
      style: {
        fontSize: 14,
        color: 'var(--c-muted)',
        cursor: 'pointer'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        flex: 1,
        overflowY: 'auto',
        padding: 20,
        display: 'flex',
        flexDirection: 'column',
        gap: 16
      }
    }, /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("label", {
      style: A_lbl
    }, A_T.dep.name), /*#__PURE__*/React.createElement("input", {
      className: "inp",
      autoFocus: true,
      value: form.name,
      onChange: e => set('name', e.target.value),
      placeholder: A_T.dep.name,
      style: A_inp
    })), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("label", {
      style: A_lbl
    }, A_T.dep.parent), /*#__PURE__*/React.createElement(A_Dropdown, {
      value: form.parentId,
      onChange: v => set('parentId', v),
      options: [{
        value: null,
        label: A_T.dep.root
      }].concat(parentOptions),
      placeholder: A_T.dep.root
    })), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("label", {
      style: A_lbl
    }, A_T.dep.manager), /*#__PURE__*/React.createElement(A_Dropdown, {
      value: form.managerId,
      onChange: v => set('managerId', v),
      options: userOptions,
      placeholder: "\u2014",
      filter: true
    })), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        marginBottom: 8
      }
    }, /*#__PURE__*/React.createElement("label", {
      style: {
        ...A_lbl,
        margin: 0,
        flex: 1
      }
    }, A_T.dep.members), /*#__PURE__*/React.createElement("button", {
      style: {
        ...A_btn('text'),
        height: 30,
        padding: '0 8px',
        fontSize: 12.5,
        color: 'var(--mg-primary-900)'
      },
      onClick: onOpenPicker
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-plus",
      style: {
        fontSize: 11
      }
    }), A_T.dep.addMember)), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        gap: 6
      }
    }, form.members.length === 0 && /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 13,
        color: 'var(--c-muted)',
        padding: '10px 0'
      }
    }, A_T.dep.noMembers), form.members.map(m => /*#__PURE__*/React.createElement("div", {
      key: m.id,
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '7px 10px',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-md)'
      }
    }, A_avatar(m.full_name, m.role, 28), /*#__PURE__*/React.createElement("div", {
      style: {
        flex: 1,
        minWidth: 0
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 13,
        fontWeight: 500,
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, m.full_name)), /*#__PURE__*/React.createElement("i", {
      className: "pi pi-times",
      onClick: () => onRemoveMember(m.id),
      title: A_T.dep.removeMember,
      style: {
        fontSize: 12,
        color: 'var(--c-muted)',
        cursor: 'pointer'
      }
    })))))), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        justifyContent: 'flex-end',
        gap: 10,
        padding: '14px 20px',
        borderTop: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("button", {
      style: A_btn('ghost'),
      onClick: onClose
    }, "\u041E\u0442\u043C\u0435\u043D\u0430"), /*#__PURE__*/React.createElement("button", {
      style: A_btn('primary'),
      onClick: onSave
    }, "\u0421\u043E\u0445\u0440\u0430\u043D\u0438\u0442\u044C"))));
  }
  const A_lbl = {
    display: 'block',
    fontSize: 13,
    fontWeight: 500,
    color: 'var(--c-text)',
    marginBottom: 6
  };
  const A_inp = {
    width: '100%',
    height: 40,
    padding: '0 13px',
    borderRadius: 'var(--mg-radius-md)',
    border: '1px solid var(--mg-input-border)',
    background: 'var(--c-card)',
    color: 'var(--c-text)',
    fontFamily: 'var(--mg-font-sans)',
    fontSize: 14,
    boxSizing: 'border-box'
  };

  /* ── member picker dialog ── */
  function A_MemberPicker({
    available,
    close,
    onAdd
  }) {
    const [sel, setSel] = useState([]);
    const [q, setQ] = useState('');
    const list = available.filter(u => u.full_name.toLowerCase().includes(q.toLowerCase()));
    const toggle = id => setSel(s => s.includes(id) ? s.filter(x => x !== id) : [...s, id]);
    return /*#__PURE__*/React.createElement("div", {
      onClick: close,
      style: {
        position: 'fixed',
        inset: 0,
        zIndex: 1400,
        background: 'rgba(9,16,32,0.55)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: 20
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: e => e.stopPropagation(),
      style: {
        width: 440,
        maxWidth: '92vw',
        maxHeight: 'calc(100vh - 40px)',
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-lg)',
        display: 'flex',
        flexDirection: 'column'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '16px 20px',
        borderBottom: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 16,
        fontWeight: 600
      }
    }, A_T.dep.addMember), /*#__PURE__*/React.createElement("i", {
      className: "pi pi-times",
      onClick: close,
      style: {
        fontSize: 14,
        color: 'var(--c-muted)',
        cursor: 'pointer'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        padding: '14px 20px'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        height: 40,
        padding: '0 12px',
        border: '1px solid var(--mg-input-border)',
        borderRadius: 'var(--mg-radius-md)',
        background: 'var(--c-page)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-search",
      style: {
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }), /*#__PURE__*/React.createElement("input", {
      autoFocus: true,
      value: q,
      onChange: e => setQ(e.target.value),
      placeholder: "\u041F\u043E\u0438\u0441\u043A\u2026",
      style: {
        flex: 1,
        border: 'none',
        outline: 'none',
        background: 'transparent',
        fontFamily: 'var(--mg-font-sans)',
        fontSize: 14,
        color: 'var(--c-text)'
      }
    }))), /*#__PURE__*/React.createElement("div", {
      style: {
        flex: 1,
        overflowY: 'auto',
        padding: '0 12px 8px'
      }
    }, list.map(u => {
      const on = sel.includes(u.id);
      return /*#__PURE__*/React.createElement("div", {
        key: u.id,
        className: "urow",
        onClick: () => toggle(u.id),
        style: {
          display: 'flex',
          alignItems: 'center',
          gap: 11,
          padding: '8px 10px',
          borderRadius: 8,
          cursor: 'pointer'
        }
      }, /*#__PURE__*/React.createElement(A_Check, {
        checked: on,
        onChange: () => toggle(u.id)
      }), A_avatar(u.full_name, u.role, 30), /*#__PURE__*/React.createElement("div", {
        style: {
          flex: 1,
          minWidth: 0
        }
      }, /*#__PURE__*/React.createElement("div", {
        style: {
          fontSize: 13.5,
          fontWeight: 500
        }
      }, u.full_name), /*#__PURE__*/React.createElement("div", {
        style: {
          fontSize: 12,
          color: 'var(--c-muted)'
        }
      }, u.email)));
    }), list.length === 0 && /*#__PURE__*/React.createElement("div", {
      style: {
        padding: '20px 10px',
        fontSize: 13,
        color: 'var(--c-muted)',
        textAlign: 'center'
      }
    }, "\u041D\u0438\u0447\u0435\u0433\u043E \u043D\u0435 \u043D\u0430\u0439\u0434\u0435\u043D\u043E")), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        justifyContent: 'flex-end',
        gap: 10,
        padding: '14px 20px',
        borderTop: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("button", {
      style: A_btn('ghost'),
      onClick: close
    }, "\u041E\u0442\u043C\u0435\u043D\u0430"), /*#__PURE__*/React.createElement("button", {
      style: {
        ...A_btn('primary'),
        opacity: sel.length ? 1 : 0.5,
        pointerEvents: sel.length ? 'auto' : 'none'
      },
      onClick: () => onAdd(sel)
    }, "\u0414\u043E\u0431\u0430\u0432\u0438\u0442\u044C"))));
  }

  /* ── confirm delete ── */
  function A_Confirm({
    message,
    onAccept,
    close
  }) {
    return /*#__PURE__*/React.createElement("div", {
      onClick: close,
      style: {
        position: 'fixed',
        inset: 0,
        zIndex: 1500,
        background: 'rgba(9,16,32,0.55)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: 20
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: e => e.stopPropagation(),
      style: {
        width: 430,
        maxWidth: '92vw',
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-lg)'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '16px 20px',
        borderBottom: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 16,
        fontWeight: 600
      }
    }, A_T.dep.del), /*#__PURE__*/React.createElement("i", {
      className: "pi pi-times",
      onClick: close,
      style: {
        fontSize: 14,
        color: 'var(--c-muted)',
        cursor: 'pointer'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        padding: 20,
        display: 'flex',
        gap: 14,
        alignItems: 'flex-start'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        width: 40,
        height: 40,
        borderRadius: '50%',
        background: 'var(--mg-status-danger-bg)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        flexShrink: 0
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-exclamation-triangle",
      style: {
        fontSize: 17,
        color: 'var(--mg-status-danger-text)'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 14,
        color: 'var(--c-text2)',
        lineHeight: 1.55,
        paddingTop: 3
      }
    }, message)), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        justifyContent: 'flex-end',
        gap: 10,
        padding: '14px 20px',
        borderTop: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("button", {
      style: A_btn('ghost'),
      onClick: close
    }, "\u041E\u0442\u043C\u0435\u043D\u0430"), /*#__PURE__*/React.createElement("button", {
      style: A_btn('danger'),
      onClick: onAccept
    }, "\u0423\u0434\u0430\u043B\u0438\u0442\u044C"))));
  }
  function A_Toasts({
    items
  }) {
    return /*#__PURE__*/React.createElement("div", {
      style: {
        position: 'fixed',
        insetBlockEnd: 20,
        insetInlineEnd: 20,
        zIndex: 2000,
        display: 'flex',
        flexDirection: 'column',
        gap: 10
      }
    }, items.map(t => /*#__PURE__*/React.createElement("div", {
      key: t.id,
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        minWidth: 240,
        maxWidth: 360,
        padding: '12px 14px',
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderInlineStart: '3px solid var(--mg-status-success-text)',
        borderRadius: 'var(--mg-radius-md)',
        boxShadow: 'var(--mg-shadow-lg)',
        animation: 'toastIn .2s ease'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-check-circle",
      style: {
        fontSize: 16,
        color: 'var(--mg-status-success-text)'
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 13.5,
        fontWeight: 500
      }
    }, t.summary))));
  }

  /* ═══════════════ TABS ═══════════════ */
  function A_DepartmentsTab({
    pushToast
  }) {
    const [tree, setTree] = useState(A_TREE0);
    const [search, setSearch] = useState('');
    const [viewMode, setViewMode] = useState('tree');
    const [selectedId, setSelectedId] = useState(2);
    const [expanded, setExpanded] = useState({});
    const [panel, setPanel] = useState(null);
    const [form, setForm] = useState({
      name: '',
      parentId: null,
      managerId: null,
      members: []
    });
    const [picker, setPicker] = useState(false);
    const [confirm, setConfirm] = useState(null);
    const toggle = id => setExpanded(e => ({
      ...e,
      [id]: e[id] === false ? true : false
    }));
    const findNode = (nodes, id) => {
      for (const n of nodes) {
        if (n.id === id) return n;
        const f = findNode(n.children, id);
        if (f) return f;
      }
      return null;
    };
    const selected = findNode(tree, selectedId);
    const parentOptions = A_flatten(tree).map(d => ({
      value: d.id,
      label: '— '.repeat(d.depth) + d.name
    }));
    const userOptions = A_ALL_USERS.map(u => ({
      value: u.id,
      label: u.full_name
    }));
    function openCreate() {
      setForm({
        name: '',
        parentId: selectedId || null,
        managerId: null,
        members: []
      });
      setPanel('create');
    }
    function openEdit(n) {
      setForm({
        id: n.id,
        name: n.name,
        parentId: null,
        managerId: (A_ALL_USERS.find(u => u.full_name === n.manager) || {}).id ?? null,
        members: n.members.slice()
      });
      setPanel('edit');
    }
    function savePanel() {
      if (!form.name.trim()) return;
      const mgr = (A_ALL_USERS.find(u => u.id === form.managerId) || {}).full_name || '—';
      if (panel === 'edit') {
        setTree(t => A_walkUpdate(t, form.id, n => ({
          ...n,
          name: form.name.trim(),
          manager: mgr,
          members: form.members
        })));
      } else {
        const id = Date.now();
        const node = {
          id,
          name: form.name.trim(),
          manager: mgr,
          members: form.members,
          children: []
        };
        setTree(t => form.parentId ? A_walkUpdate(t, form.parentId, n => ({
          ...n,
          children: [...n.children, node]
        })) : [...t, node]);
        if (form.parentId) setExpanded(e => ({
          ...e,
          [form.parentId]: false
        }));
      }
      pushToast(A_T.dep.saved);
      setPanel(null);
    }
    function doDelete(n) {
      setTree(t => A_removeNode(t, n.id).nodes);
      if (selectedId === n.id) setSelectedId(null);
      pushToast(A_T.dep.deleted);
      setConfirm(null);
    }
    function addMembers(ids) {
      const add = A_ALL_USERS.filter(u => ids.includes(u.id) && !form.members.some(m => m.id === u.id));
      setForm(f => ({
        ...f,
        members: [...f.members, ...add]
      }));
      setPicker(false);
    }
    function removeMember(id) {
      setForm(f => ({
        ...f,
        members: f.members.filter(m => m.id !== id)
      }));
    }
    const q = search.trim().toLowerCase();
    const filterTree = nodes => nodes.map(n => ({
      ...n,
      children: filterTree(n.children)
    })).filter(n => !q || n.name.toLowerCase().includes(q) || n.children.length);
    const shown = filterTree(tree);
    return /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        gap: 16
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        flexWrap: 'wrap'
      },
      "data-comment-anchor": "a8ea98e910-div-264-5"
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        height: 40,
        padding: '0 12px',
        flex: 1,
        minWidth: 180,
        maxWidth: 280,
        border: '1px solid var(--mg-input-border)',
        borderRadius: 'var(--mg-radius-md)',
        background: 'var(--c-card)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-search",
      style: {
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }), /*#__PURE__*/React.createElement("input", {
      value: search,
      onChange: e => setSearch(e.target.value),
      placeholder: "\u041F\u043E\u0438\u0441\u043A\u2026",
      style: {
        flex: 1,
        border: 'none',
        outline: 'none',
        background: 'transparent',
        fontFamily: 'var(--mg-font-sans)',
        fontSize: 14,
        color: 'var(--c-text)'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'inline-flex',
        padding: 3,
        gap: 2,
        background: 'var(--c-muted2)',
        borderRadius: 'var(--mg-radius-md)'
      }
    }, [['tree', A_T.dep.viewTree, 'pi-sitemap'], ['chart', A_T.dep.viewChart, 'pi-share-alt']].map(([k, l, ic]) => /*#__PURE__*/React.createElement("button", {
      key: k,
      onClick: () => setViewMode(k),
      style: {
        display: 'inline-flex',
        alignItems: 'center',
        gap: 6,
        height: 32,
        padding: '0 12px',
        border: 'none',
        borderRadius: 'var(--mg-radius-sm)',
        cursor: 'pointer',
        fontFamily: 'var(--mg-font-sans)',
        fontSize: 12.5,
        fontWeight: 600,
        background: viewMode === k ? 'var(--c-card)' : 'transparent',
        color: viewMode === k ? 'var(--mg-primary-900)' : 'var(--c-text2)',
        boxShadow: viewMode === k ? 'var(--mg-shadow-sm)' : 'none'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + ic,
      style: {
        fontSize: 12
      }
    }), l))), /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1
      }
    }), /*#__PURE__*/React.createElement("button", {
      style: A_btn('primary'),
      onClick: openCreate
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-plus",
      style: {
        fontSize: 12
      }
    }), A_T.dep.add)), viewMode === 'tree' ? /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'grid',
        gridTemplateColumns: '1fr 340px',
        gap: 16,
        alignItems: 'start'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-sm)',
        padding: 8
      }
    }, shown.length ? shown.map(n => /*#__PURE__*/React.createElement(A_TreeNode, {
      key: n.id,
      node: n,
      depth: 0,
      selectedId: selectedId,
      onSelect: d => setSelectedId(d.id),
      onEdit: openEdit,
      onDelete: d => setConfirm(d),
      expanded: expanded,
      toggle: toggle
    })) : /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        gap: 10,
        padding: '48px 20px',
        color: 'var(--c-muted)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-building",
      style: {
        fontSize: 28,
        opacity: 0.3
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 14,
        color: 'var(--c-text2)',
        fontWeight: 500
      }
    }, A_T.dep.empty), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 13,
        textAlign: 'center',
        maxWidth: 240
      }
    }, A_T.dep.emptyHint), /*#__PURE__*/React.createElement("button", {
      style: {
        ...A_btn('ghost'),
        marginTop: 4
      },
      onClick: openCreate
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-plus",
      style: {
        fontSize: 12
      }
    }), A_T.dep.add))), selected ? /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-sm)',
        overflow: 'hidden'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '14px 16px',
        borderBottom: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        flex: 1,
        minWidth: 0
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 15,
        fontWeight: 600,
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, selected.name), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 12,
        color: 'var(--c-muted)',
        marginTop: 2
      }
    }, "\u0420\u0443\u043A\u043E\u0432\u043E\u0434\u0438\u0442\u0435\u043B\u044C: ", selected.manager)), /*#__PURE__*/React.createElement("button", {
      className: "uicon",
      onClick: () => openEdit(selected),
      title: "\u0420\u0435\u0434\u0430\u043A\u0442\u0438\u0440\u043E\u0432\u0430\u0442\u044C",
      style: {
        width: 30,
        height: 30,
        borderRadius: 7,
        border: 'none',
        background: 'transparent',
        cursor: 'pointer'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-pencil",
      style: {
        fontSize: 13,
        color: 'var(--c-text2)'
      }
    }))), /*#__PURE__*/React.createElement("div", {
      style: {
        padding: 4
      }
    }, selected.members.length ? selected.members.map(m => /*#__PURE__*/React.createElement("div", {
      key: m.id,
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '8px 12px'
      }
    }, A_avatar(m.full_name, m.role, 30), /*#__PURE__*/React.createElement("div", {
      style: {
        flex: 1,
        minWidth: 0
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 13,
        fontWeight: 500,
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, m.full_name), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 11.5,
        color: 'var(--c-muted)',
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, m.email)), /*#__PURE__*/React.createElement(A_Tag, {
      value: A_ROLE_LABEL[m.role],
      severity: A_ROLE_SEV[m.role]
    }))) : /*#__PURE__*/React.createElement("div", {
      style: {
        padding: '28px 12px',
        fontSize: 13,
        color: 'var(--c-muted)',
        textAlign: 'center'
      }
    }, A_T.dep.noMembers))) : /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px dashed var(--c-border2)',
        borderRadius: 'var(--mg-radius-lg)',
        padding: '40px 20px',
        textAlign: 'center',
        color: 'var(--c-muted)',
        fontSize: 13
      }
    }, "\u0412\u044B\u0431\u0435\u0440\u0438\u0442\u0435 \u043E\u0442\u0434\u0435\u043B, \u0447\u0442\u043E\u0431\u044B \u0443\u0432\u0438\u0434\u0435\u0442\u044C \u0441\u043E\u0442\u0440\u0443\u0434\u043D\u0438\u043A\u043E\u0432")) : /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-page)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        padding: '32px 20px',
        overflowX: 'auto'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 40,
        justifyContent: 'center',
        minWidth: 'min-content'
      }
    }, shown.map(n => /*#__PURE__*/React.createElement(A_ChartNode, {
      key: n.id,
      node: n,
      onSelect: d => {
        setViewMode('tree');
        setSelectedId(d.id);
        openEdit(d);
      }
    })))), panel && /*#__PURE__*/React.createElement(A_SidePanel, {
      mode: panel,
      form: form,
      setForm: setForm,
      parentOptions: parentOptions.filter(o => o.value !== form.id),
      userOptions: userOptions,
      onClose: () => setPanel(null),
      onSave: savePanel,
      onOpenPicker: () => setPicker(true),
      onRemoveMember: removeMember
    }), picker && /*#__PURE__*/React.createElement(A_MemberPicker, {
      available: A_ALL_USERS.filter(u => !form.members.some(m => m.id === u.id)),
      close: () => setPicker(false),
      onAdd: addMembers
    }), confirm && /*#__PURE__*/React.createElement(A_Confirm, {
      message: A_T.dep.delConfirm(confirm.name),
      onAccept: () => doDelete(confirm),
      close: () => setConfirm(null)
    }));
  }
  function A_RolesTab({
    pushToast
  }) {
    const [perms, setPerms] = useState(() => {
      const m = {};
      A_ROLES.forEach(r => {
        m[r] = new Set(A_DEFAULT_PERMS[r]);
      });
      return m;
    });
    const [collapsed, setCollapsed] = useState({});
    const [dirty, setDirty] = useState(false);
    const has = (r, p) => perms[r].has(p);
    function toggle(p, r, v) {
      if (r === 'admin') return;
      setPerms(m => {
        const s = new Set(m[r]);
        v ? s.add(p) : s.delete(p);
        return {
          ...m,
          [r]: s
        };
      });
      setDirty(true);
    }
    function reset() {
      const m = {};
      A_ROLES.forEach(r => {
        m[r] = new Set(A_DEFAULT_PERMS[r]);
      });
      setPerms(m);
      setDirty(false);
    }
    function save() {
      pushToast(A_T.roles.saved);
      setDirty(false);
    }
    const th = {
      padding: '9px 12px',
      fontSize: 11,
      fontWeight: 600,
      letterSpacing: '.02em',
      textTransform: 'uppercase',
      color: 'var(--c-muted)',
      borderBottom: '1px solid var(--c-border)'
    };
    return /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        gap: 16
      }
    }, /*#__PURE__*/React.createElement(A_Msg, {
      severity: "secondary"
    }, A_T.roles.adminNote), A_GROUPS.map(g => {
      const open = !collapsed[g.key];
      return /*#__PURE__*/React.createElement("div", {
        key: g.key,
        style: {
          background: 'var(--c-card)',
          border: '1px solid var(--c-border)',
          borderRadius: 'var(--mg-radius-lg)',
          boxShadow: 'var(--mg-shadow-sm)',
          overflow: 'hidden'
        }
      }, /*#__PURE__*/React.createElement("div", {
        onClick: () => setCollapsed(c => ({
          ...c,
          [g.key]: !c[g.key]
        })),
        style: {
          display: 'flex',
          alignItems: 'center',
          gap: 10,
          padding: '12px 16px',
          cursor: 'pointer',
          background: 'var(--c-hover)',
          borderBottom: open ? '1px solid var(--c-border)' : 'none'
        }
      }, /*#__PURE__*/React.createElement("i", {
        className: 'pi ' + (open ? 'pi-chevron-down' : 'pi-chevron-right'),
        style: {
          fontSize: 12,
          color: 'var(--c-muted)'
        }
      }), /*#__PURE__*/React.createElement("span", {
        style: {
          flex: 1,
          fontSize: 14,
          fontWeight: 600
        }
      }, g.label), /*#__PURE__*/React.createElement("span", {
        style: {
          fontSize: 11.5,
          fontWeight: 600,
          padding: '2px 8px',
          borderRadius: 999,
          background: 'var(--c-muted2)',
          color: 'var(--c-text2)'
        }
      }, g.perms.length)), open && /*#__PURE__*/React.createElement("div", {
        style: {
          overflowX: 'auto'
        }
      }, /*#__PURE__*/React.createElement("table", {
        style: {
          width: '100%',
          borderCollapse: 'collapse',
          minWidth: 760
        }
      }, /*#__PURE__*/React.createElement("thead", null, /*#__PURE__*/React.createElement("tr", null, /*#__PURE__*/React.createElement("th", {
        style: {
          ...th,
          textAlign: 'start',
          minWidth: 260
        }
      }, A_T.roles.permissionLabel), A_ROLES.map(r => /*#__PURE__*/React.createElement("th", {
        key: r,
        style: {
          ...th,
          textAlign: 'center',
          width: 92
        }
      }, A_ROLE_SHORT[r])))), /*#__PURE__*/React.createElement("tbody", null, g.perms.map(p => /*#__PURE__*/React.createElement("tr", {
        key: p,
        className: "urow"
      }, /*#__PURE__*/React.createElement("td", {
        style: {
          padding: '10px 12px',
          borderBottom: '1px solid var(--c-border)'
        }
      }, /*#__PURE__*/React.createElement("div", {
        style: {
          display: 'flex',
          flexDirection: 'column',
          gap: 3
        }
      }, /*#__PURE__*/React.createElement("span", {
        style: {
          fontSize: 13.5,
          color: 'var(--c-text)'
        }
      }, A_PERM_LABEL[p] || p), /*#__PURE__*/React.createElement("code", {
        style: {
          fontFamily: 'ui-monospace,monospace',
          fontSize: 11,
          color: 'var(--c-muted)',
          background: 'var(--c-muted2)',
          padding: '1px 6px',
          borderRadius: 4,
          alignSelf: 'flex-start'
        }
      }, p))), A_ROLES.map(r => /*#__PURE__*/React.createElement("td", {
        key: r,
        style: {
          padding: '10px 12px',
          borderBottom: '1px solid var(--c-border)',
          textAlign: 'center'
        }
      }, /*#__PURE__*/React.createElement("div", {
        style: {
          display: 'flex',
          justifyContent: 'center'
        }
      }, /*#__PURE__*/React.createElement(A_Check, {
        checked: r === 'admin' ? true : has(r, p),
        disabled: r === 'admin',
        onChange: v => toggle(p, r, v)
      }))))))))));
    }), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        justifyContent: 'flex-end',
        gap: 10,
        paddingTop: 2
      }
    }, /*#__PURE__*/React.createElement("button", {
      style: {
        ...A_btn('ghost'),
        opacity: dirty ? 1 : 0.5,
        pointerEvents: dirty ? 'auto' : 'none'
      },
      onClick: reset
    }, A_T.roles.reset), /*#__PURE__*/React.createElement("button", {
      style: {
        ...A_btn('primary'),
        opacity: dirty ? 1 : 0.5,
        pointerEvents: dirty ? 'auto' : 'none'
      },
      onClick: save
    }, A_T.roles.save)));
  }
  function A_VisibilityTab({
    pushToast
  }) {
    const [vis, setVis] = useState(() => ({
      ...A_DEFAULT_VIS
    }));
    const [dirty, setDirty] = useState(false);
    const opts = [{
      value: 'all',
      label: A_T.vis.scopeAll
    }, {
      value: 'department',
      label: A_T.vis.scopeDepartment
    }, {
      value: 'own',
      label: A_T.vis.scopeOwn
    }];
    function setScope(r, v) {
      setVis(m => ({
        ...m,
        [r]: v
      }));
      setDirty(true);
    }
    function reset() {
      setVis({
        ...A_DEFAULT_VIS
      });
      setDirty(false);
    }
    function save() {
      pushToast(A_T.vis.saved);
      setDirty(false);
    }
    const th = {
      padding: '11px 16px',
      fontSize: 11.5,
      fontWeight: 600,
      letterSpacing: '.03em',
      textTransform: 'uppercase',
      color: 'var(--c-muted)',
      textAlign: 'start',
      borderBottom: '1px solid var(--c-border)'
    };
    return /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        gap: 16,
        maxWidth: 640
      }
    }, /*#__PURE__*/React.createElement(A_Msg, {
      severity: "warn"
    }, A_T.vis.warning), /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-sm)',
        overflow: 'hidden'
      }
    }, /*#__PURE__*/React.createElement("table", {
      style: {
        width: '100%',
        borderCollapse: 'collapse'
      }
    }, /*#__PURE__*/React.createElement("thead", null, /*#__PURE__*/React.createElement("tr", null, /*#__PURE__*/React.createElement("th", {
      style: {
        ...th,
        width: 200
      }
    }, A_T.vis.roleColumn), /*#__PURE__*/React.createElement("th", {
      style: th
    }, A_T.vis.scopeColumn))), /*#__PURE__*/React.createElement("tbody", null, A_ROLES.map(r => /*#__PURE__*/React.createElement("tr", {
      key: r,
      className: "urow"
    }, /*#__PURE__*/React.createElement("td", {
      style: {
        padding: '12px 16px',
        borderBottom: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement(A_Tag, {
      value: A_ROLE_LABEL[r],
      severity: A_ROLE_SEV[r]
    })), /*#__PURE__*/React.createElement("td", {
      style: {
        padding: '10px 16px',
        borderBottom: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement(A_Dropdown, {
      value: vis[r],
      onChange: v => setScope(r, v),
      options: opts,
      width: 240
    }))))))), /*#__PURE__*/React.createElement(A_Msg, {
      severity: "secondary"
    }, A_T.vis.departmentHint), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        justifyContent: 'flex-end',
        gap: 10
      }
    }, /*#__PURE__*/React.createElement("button", {
      style: {
        ...A_btn('ghost'),
        opacity: dirty ? 1 : 0.5,
        pointerEvents: dirty ? 'auto' : 'none'
      },
      onClick: reset
    }, A_T.vis.reset), /*#__PURE__*/React.createElement("button", {
      style: {
        ...A_btn('primary'),
        opacity: dirty ? 1 : 0.5,
        pointerEvents: dirty ? 'auto' : 'none'
      },
      onClick: save
    }, A_T.vis.save)));
  }
  function AccessTab() {
    const [tab, setTab] = useState('departments');
    const [toasts, setToasts] = useState([]);
    const pushToast = summary => {
      const id = Date.now() + Math.random();
      setToasts(t => [...t, {
        id,
        summary
      }]);
      setTimeout(() => setToasts(t => t.filter(x => x.id !== id)), 2600);
    };
    const TABS = [['departments', A_T.tabs.departments], ['roles', A_T.tabs.roles], ['visibility', A_T.tabs.visibility]];
    return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("div", {
      style: {
        marginBottom: 18
      }
    }, /*#__PURE__*/React.createElement("h2", {
      style: {
        margin: 0,
        fontSize: 20,
        fontWeight: 600
      }
    }, A_T.title), /*#__PURE__*/React.createElement("p", {
      style: {
        margin: '5px 0 0',
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }, A_T.subtitle)), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 4,
        borderBottom: '1px solid var(--c-border)',
        marginBottom: 20
      }
    }, TABS.map(([k, l]) => {
      const on = tab === k;
      return /*#__PURE__*/React.createElement("button", {
        key: k,
        onClick: () => setTab(k),
        style: {
          background: 'transparent',
          border: 'none',
          cursor: 'pointer',
          padding: '10px 14px',
          marginBottom: -1,
          whiteSpace: 'nowrap',
          fontFamily: 'var(--mg-font-sans)',
          fontSize: 14,
          fontWeight: on ? 600 : 500,
          color: on ? 'var(--mg-primary-900)' : 'var(--c-muted)',
          borderBottom: '2px solid ' + (on ? 'var(--mg-primary-900)' : 'transparent')
        }
      }, l);
    })), tab === 'departments' && /*#__PURE__*/React.createElement(A_DepartmentsTab, {
      pushToast: pushToast
    }), tab === 'roles' && /*#__PURE__*/React.createElement(A_RolesTab, {
      pushToast: pushToast
    }), tab === 'visibility' && /*#__PURE__*/React.createElement(A_VisibilityTab, {
      pushToast: pushToast
    }), /*#__PURE__*/React.createElement(A_Toasts, {
      items: toasts
    }));
  }
  window.AccessTab = AccessTab;
})();
})(); } catch (e) { __ds_ns.__errors.push({ path: "access-section.jsx", error: String((e && e.message) || e) }); }

// components/crm/Stepper.jsx
try { (() => {
/**
 * MACRO Global CRM — Stepper
 * Horizontal step track for deal stages / approval routes (brief D8). Steps:
 * [{ label, sub, status }] where status: 'done' | 'current' | 'todo'.
 */
function Stepper({
  steps = [],
  style
}) {
  const tone = s => s === 'done' ? {
    ring: 'var(--mg-status-success-solid)',
    fill: 'var(--mg-status-success-solid)',
    ink: '#fff'
  } : s === 'current' ? {
    ring: 'var(--mg-primary-900)',
    fill: 'var(--mg-primary-900)',
    ink: '#fff'
  } : {
    ring: 'var(--mg-border-strong)',
    fill: 'var(--mg-surface-card)',
    ink: 'var(--mg-text-muted)'
  };
  return /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'flex-start',
      fontFamily: 'var(--mg-font-sans)',
      ...style
    }
  }, steps.map((st, i) => {
    const t = tone(st.status);
    const last = i === steps.length - 1;
    return /*#__PURE__*/React.createElement("div", {
      key: i,
      style: {
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        flex: last ? '0 0 auto' : 1,
        minWidth: 0,
        position: 'relative'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        width: '100%'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        flex: last ? '0 0 auto' : '0 0 28px',
        width: 28,
        height: 28,
        borderRadius: '50%',
        flexShrink: 0,
        display: 'inline-flex',
        alignItems: 'center',
        justifyContent: 'center',
        fontSize: 12,
        fontWeight: 700,
        border: '2px solid ' + t.ring,
        background: t.fill,
        color: t.ink,
        zIndex: 1
      }
    }, st.status === 'done' ? /*#__PURE__*/React.createElement("i", {
      className: "pi pi-check",
      style: {
        fontSize: 11
      }
    }) : i + 1), !last && /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        height: 2,
        background: st.status === 'done' ? 'var(--mg-status-success-solid)' : 'var(--mg-border-default)'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        marginTop: 8,
        textAlign: 'center',
        paddingInline: 6,
        maxWidth: 130
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 13,
        fontWeight: st.status === 'current' ? 600 : 500,
        color: st.status === 'todo' ? 'var(--mg-text-muted)' : 'var(--mg-text-primary)',
        lineHeight: 1.2
      }
    }, st.label), st.sub && /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 11,
        color: 'var(--mg-text-muted)',
        marginTop: 2
      }
    }, st.sub)));
  }));
}
Object.assign(__ds_scope, { Stepper });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/crm/Stepper.jsx", error: String((e && e.message) || e) }); }

// components/data/Avatar.jsx
try { (() => {
/**
 * MACRO Global CRM — Avatar
 * Initials avatar (navy fill). Used for deal owners, contacts, account menu.
 */
function Avatar({
  name = '',
  src,
  size = 'md',
  color = 'var(--mg-primary-900)',
  square = false,
  style
}) {
  const sizes = {
    xs: 20,
    sm: 28,
    md: 36,
    lg: 44
  };
  const px = sizes[size] || (typeof size === 'number' ? size : 36);
  const initials = name.trim().split(/\s+/).slice(0, 2).map(w => w[0] ? w[0].toUpperCase() : '').join('') || '?';
  return /*#__PURE__*/React.createElement("span", {
    style: {
      width: px,
      height: px,
      flexShrink: 0,
      borderRadius: square ? 'var(--mg-radius-md)' : '50%',
      background: src ? 'transparent' : color,
      color: '#fff',
      display: 'inline-flex',
      alignItems: 'center',
      justifyContent: 'center',
      overflow: 'hidden',
      fontFamily: 'var(--mg-font-sans)',
      fontWeight: 600,
      fontSize: Math.round(px * 0.4),
      ...style
    }
  }, src ? /*#__PURE__*/React.createElement("img", {
    src: src,
    alt: name,
    style: {
      width: '100%',
      height: '100%',
      objectFit: 'cover'
    }
  }) : initials);
}
Object.assign(__ds_scope, { Avatar });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/data/Avatar.jsx", error: String((e && e.message) || e) }); }

// components/crm/KanbanCard.jsx
try { (() => {
/**
 * MACRO Global CRM — KanbanCard (deal card)
 * Faithful recreation of DealsKanbanCard: title, amount + product chip,
 * owner + days-in-stage row, and a bottom "health strip" (ok / no-task / overdue)
 * with a colored left inset border.
 */
function KanbanCard({
  title,
  amount = '0 ₽',
  product,
  owner = '—',
  daysInStage = 0,
  health = 'ok',
  task,
  rotting = false,
  selected = false,
  onClick,
  style
}) {
  const [hover, setHover] = React.useState(false);
  const strip = {
    ok: ['var(--mg-gray-50)', 'var(--mg-text-secondary)'],
    'no-task': ['var(--mg-status-warning-bg)', 'var(--mg-status-warning-text)'],
    overdue: ['var(--mg-status-danger-bg)', 'var(--mg-status-danger-text)']
  }[health] || ['var(--mg-gray-50)', 'var(--mg-text-secondary)'];
  const inset = health === 'no-task' ? 'inset 4px 0 0 var(--mg-warning)' : health === 'overdue' ? 'inset 4px 0 0 var(--mg-danger)' : 'none';
  const daysColor = rotting ? 'var(--mg-danger)' : 'var(--mg-gray-500)';
  return /*#__PURE__*/React.createElement("div", {
    onClick: onClick,
    onMouseEnter: () => setHover(true),
    onMouseLeave: () => setHover(false),
    style: {
      background: hover ? 'var(--mg-gray-50)' : 'var(--mg-surface-card)',
      border: `1px solid ${selected ? 'var(--mg-primary-900)' : health === 'overdue' ? 'var(--mg-danger)' : 'var(--mg-border-default)'}`,
      borderWidth: selected ? 2 : 1,
      borderRadius: 'var(--mg-radius-md)',
      overflow: 'hidden',
      cursor: 'pointer',
      boxShadow: hover ? 'var(--mg-shadow-card)' : 'none',
      fontFamily: 'var(--mg-font-sans)',
      transition: 'box-shadow var(--mg-transition-fast), background var(--mg-transition-fast)',
      ...style
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      padding: '12px',
      boxShadow: inset
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      fontSize: '14px',
      fontWeight: 600,
      color: 'var(--mg-gray-800)',
      marginBottom: '8px',
      overflow: 'hidden',
      textOverflow: 'ellipsis',
      whiteSpace: 'nowrap'
    }
  }, title), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: '8px',
      marginBottom: '8px'
    }
  }, /*#__PURE__*/React.createElement("span", {
    style: {
      fontSize: '12px',
      fontWeight: 700,
      color: 'var(--mg-primary-900)',
      whiteSpace: 'nowrap'
    }
  }, amount), product && /*#__PURE__*/React.createElement("span", {
    style: {
      display: 'inline-flex',
      alignItems: 'center',
      gap: '3px',
      background: 'var(--mg-gray-100)',
      borderRadius: 'var(--mg-radius-sm)',
      padding: '1px 6px',
      fontSize: '11px',
      color: 'var(--mg-gray-600)',
      overflow: 'hidden'
    }
  }, /*#__PURE__*/React.createElement("i", {
    className: "pi pi-box",
    style: {
      fontSize: '10px'
    }
  }), /*#__PURE__*/React.createElement("span", {
    style: {
      overflow: 'hidden',
      textOverflow: 'ellipsis',
      whiteSpace: 'nowrap',
      maxWidth: '100px'
    }
  }, product))), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: '8px'
    }
  }, /*#__PURE__*/React.createElement("span", {
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: '5px',
      flex: 1,
      minWidth: 0
    }
  }, /*#__PURE__*/React.createElement(__ds_scope.Avatar, {
    name: owner,
    size: 20
  }), /*#__PURE__*/React.createElement("span", {
    style: {
      fontSize: '12px',
      color: 'var(--mg-gray-500)',
      overflow: 'hidden',
      textOverflow: 'ellipsis',
      whiteSpace: 'nowrap'
    }
  }, owner)), /*#__PURE__*/React.createElement("span", {
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: '2px',
      fontSize: '12px',
      color: daysColor,
      whiteSpace: 'nowrap'
    }
  }, /*#__PURE__*/React.createElement("i", {
    className: "pi pi-clock",
    style: {
      fontSize: '11px'
    }
  }), daysInStage, " \u0434\u043D."))), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: '8px',
      padding: '7px 12px',
      borderTop: '1px solid var(--mg-border-default)',
      background: strip[0],
      fontSize: '11px',
      color: strip[1],
      minHeight: '28px'
    }
  }, health === 'overdue' && /*#__PURE__*/React.createElement("i", {
    className: "pi pi-exclamation-circle",
    style: {
      fontSize: '11px'
    }
  }), health === 'ok' && task && /*#__PURE__*/React.createElement("i", {
    className: "pi pi-phone",
    style: {
      fontSize: '11px',
      color: 'var(--mg-gray-500)'
    }
  }), /*#__PURE__*/React.createElement("span", {
    style: {
      overflow: 'hidden',
      textOverflow: 'ellipsis',
      whiteSpace: 'nowrap',
      flex: 1,
      fontWeight: health === 'overdue' ? 600 : 400
    }
  }, health === 'no-task' ? 'Нет задачи' : task || 'Сегодня 14:00'), health === 'no-task' && /*#__PURE__*/React.createElement("span", {
    style: {
      color: 'var(--mg-primary-900)',
      fontWeight: 600
    }
  }, "\u041F\u043E\u0441\u0442\u0430\u0432\u0438\u0442\u044C")));
}
Object.assign(__ds_scope, { KanbanCard });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/crm/KanbanCard.jsx", error: String((e && e.message) || e) }); }

// components/data/AvatarGroup.jsx
try { (() => {
/**
 * MACRO Global CRM — AvatarGroup
 * Overlapping initials avatars with a "+N" overflow chip. Used for deal
 * watchers / shared owners. Pass items as [{ name, src, color }].
 */
const PALETTE = ['var(--mg-primary-900)', 'var(--mg-stage-blue)', 'var(--mg-stage-teal)', 'var(--mg-stage-amber)', 'var(--mg-stage-pink)', 'var(--mg-stage-purple)'];
function AvatarGroup({
  items = [],
  max = 4,
  size = 32,
  style
}) {
  const shown = items.slice(0, max);
  const extra = items.length - shown.length;
  const initials = (n = '') => n.trim().split(/\s+/).slice(0, 2).map(w => w[0] ? w[0].toUpperCase() : '').join('') || '?';
  const cell = {
    width: size,
    height: size,
    borderRadius: '50%',
    flexShrink: 0,
    display: 'inline-flex',
    alignItems: 'center',
    justifyContent: 'center',
    overflow: 'hidden',
    color: '#fff',
    fontFamily: 'var(--mg-font-sans)',
    fontWeight: 600,
    fontSize: Math.round(size * 0.38),
    border: '2px solid var(--mg-surface-card)',
    boxSizing: 'content-box'
  };
  return /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'inline-flex',
      alignItems: 'center',
      ...style
    }
  }, shown.map((it, i) => /*#__PURE__*/React.createElement("span", {
    key: i,
    title: it.name,
    style: {
      ...cell,
      background: it.src ? 'transparent' : it.color || PALETTE[i % PALETTE.length],
      marginInlineStart: i === 0 ? 0 : -10
    }
  }, it.src ? /*#__PURE__*/React.createElement("img", {
    src: it.src,
    alt: it.name,
    style: {
      width: '100%',
      height: '100%',
      objectFit: 'cover'
    }
  }) : initials(it.name))), extra > 0 && /*#__PURE__*/React.createElement("span", {
    style: {
      ...cell,
      background: 'var(--mg-surface-hover)',
      color: 'var(--mg-text-secondary)',
      marginInlineStart: -10
    }
  }, "+", extra));
}
Object.assign(__ds_scope, { AvatarGroup });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/data/AvatarGroup.jsx", error: String((e && e.message) || e) }); }

// components/data/Badge.jsx
try { (() => {
/**
 * MACRO Global CRM — Badge
 * Small count indicator (nav badges, tab counts). Warning amber by default,
 * danger red variant — matches the sidebar nav badges.
 */
function Badge({
  value,
  variant = 'warning',
  dot = false,
  style
}) {
  const bg = variant === 'danger' ? 'var(--mg-danger)' : variant === 'primary' ? 'var(--mg-primary-900)' : 'var(--mg-orange-600)';
  if (dot) {
    return /*#__PURE__*/React.createElement("span", {
      style: {
        width: 7,
        height: 7,
        borderRadius: '50%',
        background: bg,
        display: 'inline-block',
        ...style
      }
    });
  }
  return /*#__PURE__*/React.createElement("span", {
    style: {
      display: 'inline-flex',
      alignItems: 'center',
      justifyContent: 'center',
      minWidth: 18,
      height: 18,
      padding: '0 5px',
      borderRadius: 9,
      background: bg,
      color: '#fff',
      fontFamily: 'var(--mg-font-sans)',
      fontSize: 10,
      fontWeight: 700,
      lineHeight: 1,
      ...style
    }
  }, value);
}
Object.assign(__ds_scope, { Badge });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/data/Badge.jsx", error: String((e && e.message) || e) }); }

// components/data/Card.jsx
try { (() => {
/**
 * MACRO Global CRM — Card
 * White surface container: 1px border, 8px radius, soft card shadow.
 * Optional header (title + actions slot).
 */
function Card({
  title,
  icon,
  actions,
  children,
  padding = 16,
  hover = false,
  style
}) {
  const [h, setH] = React.useState(false);
  return /*#__PURE__*/React.createElement("div", {
    onMouseEnter: () => setH(true),
    onMouseLeave: () => setH(false),
    style: {
      background: 'var(--mg-surface-card)',
      border: '1px solid var(--mg-border-default)',
      borderRadius: 'var(--mg-radius-lg)',
      boxShadow: hover && h ? 'var(--mg-shadow-md)' : 'var(--mg-shadow-card)',
      transition: 'box-shadow var(--mg-transition-fast)',
      overflow: 'hidden',
      fontFamily: 'var(--mg-font-sans)',
      color: 'var(--mg-text-primary)',
      ...style
    }
  }, title && /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: '10px',
      padding: '12px 16px',
      borderBottom: '1px solid var(--mg-border-default)'
    }
  }, icon && /*#__PURE__*/React.createElement("i", {
    className: `pi ${icon}`,
    style: {
      fontSize: '15px',
      color: 'var(--mg-primary-900)'
    }
  }), /*#__PURE__*/React.createElement("span", {
    style: {
      fontSize: '15px',
      fontWeight: 600,
      flex: 1
    }
  }, title), actions), /*#__PURE__*/React.createElement("div", {
    style: {
      padding
    }
  }, children));
}
Object.assign(__ds_scope, { Card });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/data/Card.jsx", error: String((e && e.message) || e) }); }

// components/data/DataTable.jsx
try { (() => {
/**
 * MACRO Global CRM — DataTable
 * Config-driven table: the single most-used CRM surface. Density-aware
 * (--mg-cell-py), zebra rows, sticky header, optional row selection + sort UI.
 *
 * columns: [{ key, label, width, align, sortable, render(row, i) }]
 * rows:    array of objects (row[column.key] used unless render() given)
 */
function DataTable({
  columns = [],
  rows = [],
  rowKey = 'id',
  selectable = false,
  selected = [],
  onToggle,
  onToggleAll,
  sortKey,
  sortDir = 'asc',
  onSort,
  zebra = true,
  onRowClick,
  empty,
  style
}) {
  const allOn = selectable && rows.length > 0 && rows.every(r => selected.includes(r[rowKey]));
  const someOn = selectable && selected.length > 0 && !allOn;
  const Check = ({
    on,
    dash,
    onClick
  }) => /*#__PURE__*/React.createElement("span", {
    onClick: e => {
      e.stopPropagation();
      onClick && onClick();
    },
    style: {
      display: 'inline-flex',
      width: 17,
      height: 17,
      borderRadius: 4,
      flexShrink: 0,
      cursor: 'pointer',
      border: '1.5px solid ' + (on || dash ? 'var(--mg-primary-900)' : 'var(--mg-border-strong)'),
      background: on ? 'var(--mg-primary-900)' : 'transparent',
      alignItems: 'center',
      justifyContent: 'center'
    }
  }, on ? /*#__PURE__*/React.createElement("i", {
    className: "pi pi-check",
    style: {
      fontSize: 9,
      color: '#fff'
    }
  }) : dash ? /*#__PURE__*/React.createElement("span", {
    style: {
      width: 8,
      height: 2,
      background: 'var(--mg-primary-900)'
    }
  }) : null);
  const th = {
    padding: 'var(--mg-row-py, 8px) 14px',
    fontSize: 12,
    fontWeight: 600,
    textAlign: 'start',
    color: 'var(--mg-text-secondary)',
    background: 'var(--mg-surface-card)',
    whiteSpace: 'nowrap',
    borderBottom: '1px solid var(--mg-border-default)',
    position: 'sticky',
    top: 0,
    zIndex: 'var(--mg-z-sticky, 100)'
  };
  return /*#__PURE__*/React.createElement("div", {
    style: {
      background: 'var(--mg-surface-card)',
      border: '1px solid var(--mg-border-default)',
      borderRadius: 'var(--mg-radius-lg)',
      overflow: 'hidden',
      boxShadow: 'var(--mg-shadow-sm)',
      ...style
    }
  }, /*#__PURE__*/React.createElement("table", {
    style: {
      width: '100%',
      borderCollapse: 'collapse',
      fontFamily: 'var(--mg-font-sans)'
    }
  }, /*#__PURE__*/React.createElement("thead", null, /*#__PURE__*/React.createElement("tr", null, selectable && /*#__PURE__*/React.createElement("th", {
    style: {
      ...th,
      width: 40,
      textAlign: 'center'
    }
  }, /*#__PURE__*/React.createElement(Check, {
    on: allOn,
    dash: someOn,
    onClick: onToggleAll
  })), columns.map(c => /*#__PURE__*/React.createElement("th", {
    key: c.key,
    onClick: () => c.sortable && onSort && onSort(c.key),
    style: {
      ...th,
      width: c.width,
      textAlign: c.align || 'start',
      cursor: c.sortable ? 'pointer' : 'default'
    }
  }, /*#__PURE__*/React.createElement("span", {
    style: {
      display: 'inline-flex',
      alignItems: 'center',
      gap: 5
    }
  }, c.label, c.sortable && /*#__PURE__*/React.createElement("i", {
    className: 'pi ' + (sortKey === c.key ? sortDir === 'asc' ? 'pi-sort-up' : 'pi-sort-down' : 'pi-sort-alt'),
    style: {
      fontSize: 11,
      color: sortKey === c.key ? 'var(--mg-primary-900)' : 'var(--mg-text-muted)'
    }
  })))))), /*#__PURE__*/React.createElement("tbody", null, rows.length === 0 && /*#__PURE__*/React.createElement("tr", null, /*#__PURE__*/React.createElement("td", {
    colSpan: columns.length + (selectable ? 1 : 0),
    style: {
      padding: 0
    }
  }, empty || /*#__PURE__*/React.createElement("div", {
    style: {
      padding: '40px',
      textAlign: 'center',
      color: 'var(--mg-text-muted)',
      fontSize: 13
    }
  }, "\u0417\u0430\u043F\u0438\u0441\u0438 \u043D\u0435 \u043D\u0430\u0439\u0434\u0435\u043D\u044B"))), rows.map((r, i) => {
    const sel = selectable && selected.includes(r[rowKey]);
    return /*#__PURE__*/React.createElement("tr", {
      key: r[rowKey] ?? i,
      onClick: () => onRowClick && onRowClick(r),
      style: {
        background: sel ? 'var(--mg-primary-100)' : zebra && i % 2 ? 'var(--mg-surface-hover)' : 'var(--mg-surface-card)',
        cursor: onRowClick ? 'pointer' : 'default'
      }
    }, selectable && /*#__PURE__*/React.createElement("td", {
      style: {
        padding: 'var(--mg-cell-py, 10px) 14px',
        textAlign: 'center',
        borderBottom: '1px solid var(--mg-border-default)'
      }
    }, /*#__PURE__*/React.createElement(Check, {
      on: sel,
      onClick: () => onToggle && onToggle(r[rowKey])
    })), columns.map(c => /*#__PURE__*/React.createElement("td", {
      key: c.key,
      style: {
        padding: 'var(--mg-cell-py, 10px) 14px',
        fontSize: 13,
        color: 'var(--mg-text-primary)',
        textAlign: c.align || 'start',
        borderBottom: '1px solid var(--mg-border-default)',
        verticalAlign: 'middle'
      }
    }, c.render ? c.render(r, i) : r[c.key])));
  }))));
}
Object.assign(__ds_scope, { DataTable });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/data/DataTable.jsx", error: String((e && e.message) || e) }); }

// components/data/NotificationBadge.jsx
try { (() => {
/**
 * MACRO Global CRM — NotificationBadge
 * Wraps any icon/element and pins a count (or dot) at its top-inline-end.
 * RTL-safe via logical inset. Used on the topbar bell / nav items.
 */
function NotificationBadge({
  value,
  variant = 'danger',
  dot = false,
  max = 99,
  children,
  style
}) {
  const bg = variant === 'primary' ? 'var(--mg-primary-900)' : variant === 'warning' ? 'var(--mg-orange-600)' : 'var(--mg-danger)';
  const display = typeof value === 'number' && value > max ? `${max}+` : value;
  return /*#__PURE__*/React.createElement("span", {
    style: {
      position: 'relative',
      display: 'inline-flex',
      ...style
    }
  }, children, dot ? /*#__PURE__*/React.createElement("span", {
    style: {
      position: 'absolute',
      top: -1,
      insetInlineEnd: -1,
      width: 9,
      height: 9,
      borderRadius: '50%',
      background: bg,
      border: '2px solid var(--mg-surface-card)'
    }
  }) : value != null && /*#__PURE__*/React.createElement("span", {
    style: {
      position: 'absolute',
      top: -6,
      insetInlineEnd: -8,
      minWidth: 18,
      height: 18,
      padding: '0 5px',
      borderRadius: 999,
      background: bg,
      color: '#fff',
      fontFamily: 'var(--mg-font-sans)',
      fontSize: 10,
      fontWeight: 700,
      lineHeight: 1,
      display: 'inline-flex',
      alignItems: 'center',
      justifyContent: 'center',
      border: '2px solid var(--mg-surface-card)'
    }
  }, display));
}
Object.assign(__ds_scope, { NotificationBadge });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/data/NotificationBadge.jsx", error: String((e && e.message) || e) }); }

// components/data/StatCard.jsx
try { (() => {
/**
 * MACRO Global CRM — StatCard
 * Board / dashboard KPI tile: label, big value, optional delta + icon. tone
 * maps to the status palette for the accent (primary by default).
 */
function StatCard({
  label,
  value,
  icon,
  delta,
  deltaDir,
  tone = 'primary',
  style
}) {
  const accent = tone === 'primary' ? 'var(--mg-primary-900)' : `var(--mg-status-${tone}-solid)`;
  const plate = tone === 'primary' ? 'var(--mg-primary-100)' : `var(--mg-status-${tone}-bg)`;
  const up = deltaDir !== 'down';
  return /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      flexDirection: 'column',
      gap: 10,
      background: 'var(--mg-surface-card)',
      border: '1px solid var(--mg-border-default)',
      borderRadius: 'var(--mg-radius-lg)',
      padding: 'var(--mg-card-pad, 16px)',
      boxShadow: 'var(--mg-shadow-sm)',
      fontFamily: 'var(--mg-font-sans)',
      minWidth: 160,
      ...style
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'space-between'
    }
  }, /*#__PURE__*/React.createElement("span", {
    style: {
      fontSize: 12,
      fontWeight: 600,
      color: 'var(--mg-text-muted)',
      textTransform: 'uppercase',
      letterSpacing: '.04em'
    }
  }, label), icon && /*#__PURE__*/React.createElement("span", {
    style: {
      width: 30,
      height: 30,
      borderRadius: 'var(--mg-radius-md)',
      background: plate,
      display: 'inline-flex',
      alignItems: 'center',
      justifyContent: 'center'
    }
  }, /*#__PURE__*/React.createElement("i", {
    className: 'pi ' + icon,
    style: {
      fontSize: 14,
      color: accent
    }
  }))), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'baseline',
      gap: 8
    }
  }, /*#__PURE__*/React.createElement("span", {
    style: {
      fontSize: 26,
      fontWeight: 700,
      color: 'var(--mg-text-primary)',
      fontVariantNumeric: 'tabular-nums',
      lineHeight: 1
    }
  }, value), delta != null && /*#__PURE__*/React.createElement("span", {
    style: {
      display: 'inline-flex',
      alignItems: 'center',
      gap: 3,
      fontSize: 12,
      fontWeight: 600,
      color: up ? 'var(--mg-status-success-text)' : 'var(--mg-status-danger-text)'
    }
  }, /*#__PURE__*/React.createElement("i", {
    className: 'pi ' + (up ? 'pi-arrow-up-right' : 'pi-arrow-down-right'),
    style: {
      fontSize: 11
    }
  }), delta)));
}
Object.assign(__ds_scope, { StatCard });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/data/StatCard.jsx", error: String((e && e.message) || e) }); }

// components/data/Tag.jsx
try { (() => {
/**
 * MACRO Global CRM — Tag
 * Status / category pill. Matches PrimeVue Tag severities + the CRM's
 * pipeline/deal pill colors.
 */
function Tag({
  children,
  severity = 'secondary',
  icon,
  size = 'md',
  solid = false,
  style
}) {
  const map = {
    success: ['var(--mg-status-success-bg)', 'var(--mg-status-success-text)'],
    danger: ['var(--mg-status-danger-bg)', 'var(--mg-status-danger-text)'],
    warning: ['var(--mg-status-warning-bg)', 'var(--mg-status-warning-text)'],
    warn: ['var(--mg-status-warning-bg)', 'var(--mg-status-warning-text)'],
    info: ['var(--mg-status-info-bg)', 'var(--mg-status-info-text)'],
    primary: ['var(--mg-primary-100)', 'var(--mg-primary-900)'],
    secondary: ['var(--mg-gray-200)', 'var(--mg-gray-800)']
  };
  let [bg, color] = map[severity] || map.secondary;
  if (solid) {
    color = '#fff';
    const solidBg = {
      success: 'var(--mg-green-700)',
      danger: 'var(--mg-red-600)',
      warning: 'var(--mg-orange-700)',
      warn: 'var(--mg-orange-700)',
      info: 'var(--mg-blue-700)',
      primary: 'var(--mg-primary-900)',
      secondary: 'var(--mg-gray-600)'
    };
    bg = solidBg[severity] || solidBg.secondary;
  }
  const sizes = {
    sm: ['11px', '1px 6px'],
    md: ['12px', '2px 8px'],
    lg: ['13px', '3px 10px']
  };
  const [fs, pad] = sizes[size] || sizes.md;
  return /*#__PURE__*/React.createElement("span", {
    style: {
      display: 'inline-flex',
      alignItems: 'center',
      gap: '4px',
      fontFamily: 'var(--mg-font-sans)',
      fontSize: fs,
      fontWeight: 600,
      lineHeight: 1.4,
      padding: pad,
      borderRadius: 'var(--mg-radius-sm)',
      background: bg,
      color,
      whiteSpace: 'nowrap',
      ...style
    }
  }, icon && /*#__PURE__*/React.createElement("i", {
    className: `pi ${icon}`,
    style: {
      fontSize: `calc(${fs} - 1px)`
    }
  }), children);
}
Object.assign(__ds_scope, { Tag });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/data/Tag.jsx", error: String((e && e.message) || e) }); }

// components/feedback/EmptyState.jsx
try { (() => {
/**
 * MACRO Global CRM — EmptyState
 * Centered empty/zero-data state: icon plate, title, description, optional action.
 */
function EmptyState({
  icon = 'pi-inbox',
  title,
  description,
  action,
  style
}) {
  return /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      flexDirection: 'column',
      alignItems: 'center',
      justifyContent: 'center',
      textAlign: 'center',
      gap: 10,
      padding: '32px 20px',
      fontFamily: 'var(--mg-font-sans)',
      ...style
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      width: 64,
      height: 64,
      borderRadius: 'var(--mg-radius-xl)',
      background: 'var(--mg-surface-muted)',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center'
    }
  }, /*#__PURE__*/React.createElement("i", {
    className: `pi ${icon}`,
    style: {
      fontSize: 28,
      color: 'var(--mg-text-muted)'
    }
  })), title && /*#__PURE__*/React.createElement("div", {
    style: {
      fontSize: 16,
      fontWeight: 600,
      color: 'var(--mg-text-primary)'
    }
  }, title), description && /*#__PURE__*/React.createElement("div", {
    style: {
      fontSize: 13,
      color: 'var(--mg-text-muted)',
      maxWidth: 300,
      lineHeight: 1.5
    }
  }, description), action && /*#__PURE__*/React.createElement("div", {
    style: {
      marginTop: 6
    }
  }, action));
}
Object.assign(__ds_scope, { EmptyState });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/feedback/EmptyState.jsx", error: String((e && e.message) || e) }); }

// components/feedback/Skeleton.jsx
try { (() => {
/**
 * MACRO Global CRM — Skeleton
 * Shimmer placeholder for loading states. variant: text | circle | rect.
 * Use `lines` with variant="text" for multi-line paragraph skeletons.
 */
if (typeof document !== 'undefined' && !document.getElementById('mg-skeleton-kf')) {
  const s = document.createElement('style');
  s.id = 'mg-skeleton-kf';
  s.textContent = '@keyframes mg-shimmer{0%{background-position:-320px 0}100%{background-position:320px 0}}';
  document.head.appendChild(s);
}
const shimmer = {
  background: 'linear-gradient(90deg, var(--mg-surface-muted) 25%, var(--mg-surface-hover) 37%, var(--mg-surface-muted) 63%)',
  backgroundSize: '640px 100%',
  animation: 'mg-shimmer 1.4s infinite linear'
};
function Skeleton({
  variant = 'text',
  width,
  height,
  lines = 1,
  radius,
  style
}) {
  if (variant === 'circle') {
    const d = width || height || 44;
    return /*#__PURE__*/React.createElement("span", {
      style: {
        ...shimmer,
        width: d,
        height: d,
        borderRadius: '50%',
        display: 'inline-block',
        flexShrink: 0,
        ...style
      }
    });
  }
  if (variant === 'rect') {
    return /*#__PURE__*/React.createElement("span", {
      style: {
        ...shimmer,
        width: width || '100%',
        height: height || 80,
        borderRadius: radius || 'var(--mg-radius-md)',
        display: 'block',
        ...style
      }
    });
  }
  // text (single or multi-line)
  const rows = Array.from({
    length: lines
  });
  return /*#__PURE__*/React.createElement("span", {
    style: {
      display: 'flex',
      flexDirection: 'column',
      gap: 8,
      width: width || '100%',
      ...style
    }
  }, rows.map((_, i) => /*#__PURE__*/React.createElement("span", {
    key: i,
    style: {
      ...shimmer,
      height: height || 12,
      borderRadius: 6,
      width: i === rows.length - 1 && lines > 1 ? '70%' : '100%'
    }
  })));
}
Object.assign(__ds_scope, { Skeleton });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/feedback/Skeleton.jsx", error: String((e && e.message) || e) }); }

// components/feedback/Toast.jsx
try { (() => {
/**
 * MACRO Global CRM — Toast
 * Transient notification with a status-colored inset border. Matches the
 * MSales 2.0 toast pattern (success/danger/warning/info).
 */
const ICONS = {
  success: 'pi-check-circle',
  danger: 'pi-exclamation-triangle',
  warning: 'pi-exclamation-circle',
  info: 'pi-info-circle'
};
function Toast({
  severity = 'success',
  title,
  description,
  icon,
  onClose,
  style
}) {
  const solid = `var(--mg-status-${severity}-solid)`;
  const iconCls = icon || ICONS[severity] || ICONS.info;
  return /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'flex-start',
      gap: 12,
      background: 'var(--mg-surface-card)',
      borderInlineStart: `4px solid ${solid}`,
      border: '1px solid var(--mg-border-default)',
      borderRadius: 'var(--mg-radius-md)',
      padding: '13px 15px',
      boxShadow: 'var(--mg-shadow-md)',
      fontFamily: 'var(--mg-font-sans)',
      minWidth: 280,
      maxWidth: 400,
      ...style
    }
  }, /*#__PURE__*/React.createElement("i", {
    className: `pi ${iconCls}`,
    style: {
      fontSize: 18,
      color: solid,
      marginTop: 1
    }
  }), /*#__PURE__*/React.createElement("div", {
    style: {
      flex: 1,
      minWidth: 0
    }
  }, title && /*#__PURE__*/React.createElement("div", {
    style: {
      fontSize: 14,
      fontWeight: 600,
      color: 'var(--mg-text-primary)'
    }
  }, title), description && /*#__PURE__*/React.createElement("div", {
    style: {
      fontSize: 13,
      color: 'var(--mg-text-muted)',
      marginTop: title ? 2 : 0
    }
  }, description)), onClose && /*#__PURE__*/React.createElement("i", {
    className: "pi pi-times",
    onClick: onClose,
    style: {
      fontSize: 13,
      color: 'var(--mg-text-muted)',
      cursor: 'pointer',
      marginTop: 2
    }
  }));
}
Object.assign(__ds_scope, { Toast });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/feedback/Toast.jsx", error: String((e && e.message) || e) }); }

// components/forms/Button.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/**
 * MACRO Global CRM — Button
 * Faithful to the PrimeVue 4 Aura-derived button used across the CRM.
 * Primary = brand navy; secondary = outlined neutral; danger = red.
 */
function Button({
  children,
  variant = 'primary',
  size = 'md',
  icon,
  iconRight,
  text = false,
  outlined = false,
  disabled = false,
  loading = false,
  fullWidth = false,
  onClick,
  style,
  ...rest
}) {
  const sizes = {
    sm: {
      fontSize: '12px',
      padding: '5px 10px',
      gap: '5px',
      icon: '12px'
    },
    md: {
      fontSize: '14px',
      padding: '8px 14px',
      gap: '7px',
      icon: '14px'
    },
    lg: {
      fontSize: '15px',
      padding: '11px 18px',
      gap: '8px',
      icon: '16px'
    }
  };
  const s = sizes[size] || sizes.md;
  const palette = {
    primary: {
      bg: 'var(--mg-action-primary-bg)',
      color: '#fff',
      border: 'var(--mg-action-primary-bg)'
    },
    secondary: {
      bg: 'var(--mg-action-secondary-bg)',
      color: 'var(--mg-action-secondary-text)',
      border: 'var(--mg-action-secondary-border)'
    },
    danger: {
      bg: 'var(--mg-action-danger-bg)',
      color: '#fff',
      border: 'var(--mg-action-danger-bg)'
    }
  }[variant] || {};
  let bg = palette.bg,
    color = palette.color,
    border = palette.border;
  if (text) {
    bg = 'transparent';
    border = 'transparent';
    color = variant === 'primary' ? 'var(--mg-primary-900)' : variant === 'danger' ? 'var(--mg-red-600)' : 'var(--mg-text-secondary)';
  } else if (outlined) {
    bg = 'transparent';
    color = variant === 'danger' ? 'var(--mg-red-600)' : 'var(--mg-primary-900)';
    border = variant === 'danger' ? 'var(--mg-red-300)' : 'var(--mg-border-strong)';
  }
  const [hover, setHover] = React.useState(false);
  let renderBg = bg;
  if (hover && !disabled && !loading) {
    if (text || outlined) renderBg = variant === 'danger' ? 'var(--mg-red-50)' : 'var(--mg-primary-50)';else if (variant === 'primary') renderBg = 'var(--mg-action-primary-hover)';else if (variant === 'danger') renderBg = 'var(--mg-action-danger-hover)';else renderBg = 'var(--mg-action-secondary-hover)';
  }
  return /*#__PURE__*/React.createElement("button", _extends({
    type: "button",
    disabled: disabled || loading,
    onClick: onClick,
    onMouseEnter: () => setHover(true),
    onMouseLeave: () => setHover(false),
    style: {
      display: 'inline-flex',
      alignItems: 'center',
      justifyContent: 'center',
      gap: s.gap,
      fontFamily: 'var(--mg-font-sans)',
      fontSize: s.fontSize,
      fontWeight: 600,
      lineHeight: 1.2,
      padding: s.padding,
      borderRadius: 'var(--mg-radius-md)',
      background: renderBg,
      color,
      border: `1px solid ${border}`,
      cursor: disabled || loading ? 'not-allowed' : 'pointer',
      opacity: disabled ? 0.55 : 1,
      width: fullWidth ? '100%' : undefined,
      whiteSpace: 'nowrap',
      transition: 'background var(--mg-transition-fast), border-color var(--mg-transition-fast)',
      ...style
    }
  }, rest), loading && /*#__PURE__*/React.createElement("i", {
    className: "pi pi-spinner pi-spin",
    style: {
      fontSize: s.icon
    }
  }), !loading && icon && /*#__PURE__*/React.createElement("i", {
    className: `pi ${icon}`,
    style: {
      fontSize: s.icon
    }
  }), children && /*#__PURE__*/React.createElement("span", null, children), !loading && iconRight && /*#__PURE__*/React.createElement("i", {
    className: `pi ${iconRight}`,
    style: {
      fontSize: s.icon
    }
  }));
}
Object.assign(__ds_scope, { Button });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/forms/Button.jsx", error: String((e && e.message) || e) }); }

// components/forms/Checkbox.jsx
try { (() => {
/**
 * MACRO Global CRM — Checkbox
 * Matches the PrimeVue Checkbox: navy fill when checked, 4px radius.
 */
function Checkbox({
  checked = false,
  label,
  disabled = false,
  onChange,
  style
}) {
  return /*#__PURE__*/React.createElement("label", {
    style: {
      display: 'inline-flex',
      alignItems: 'center',
      gap: '8px',
      fontFamily: 'var(--mg-font-sans)',
      fontSize: '14px',
      color: 'var(--mg-text-primary)',
      cursor: disabled ? 'not-allowed' : 'pointer',
      opacity: disabled ? 0.55 : 1,
      ...style
    }
  }, /*#__PURE__*/React.createElement("span", {
    onClick: () => !disabled && onChange && onChange(!checked),
    style: {
      width: '18px',
      height: '18px',
      flexShrink: 0,
      borderRadius: 'var(--mg-radius-sm)',
      display: 'inline-flex',
      alignItems: 'center',
      justifyContent: 'center',
      background: checked ? 'var(--mg-primary-900)' : 'var(--mg-input-bg)',
      border: `1px solid ${checked ? 'var(--mg-primary-900)' : 'var(--mg-border-strong)'}`,
      transition: 'background var(--mg-transition-fast), border-color var(--mg-transition-fast)'
    }
  }, checked && /*#__PURE__*/React.createElement("i", {
    className: "pi pi-check",
    style: {
      fontSize: '11px',
      color: '#fff',
      fontWeight: 700
    }
  })), label && /*#__PURE__*/React.createElement("span", null, label));
}
Object.assign(__ds_scope, { Checkbox });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/forms/Checkbox.jsx", error: String((e && e.message) || e) }); }

// components/forms/Input.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/**
 * MACRO Global CRM — Input
 * Single-line text field matching the PrimeVue InputText used in CRM forms.
 */
function Input({
  value,
  placeholder,
  icon,
  type = 'text',
  size = 'md',
  invalid = false,
  disabled = false,
  fullWidth = false,
  onChange,
  style,
  ...rest
}) {
  const [focus, setFocus] = React.useState(false);
  const sizes = {
    sm: {
      fontSize: '13px',
      padding: '6px 10px',
      icon: '13px'
    },
    md: {
      fontSize: '14px',
      padding: '8px 12px',
      icon: '14px'
    },
    lg: {
      fontSize: '15px',
      padding: '10px 14px',
      icon: '15px'
    }
  };
  const s = sizes[size] || sizes.md;
  const border = invalid ? 'var(--mg-red-500)' : focus ? 'var(--mg-input-focus-border)' : 'var(--mg-input-border)';
  return /*#__PURE__*/React.createElement("div", {
    style: {
      position: 'relative',
      display: fullWidth ? 'block' : 'inline-block',
      width: fullWidth ? '100%' : undefined
    }
  }, icon && /*#__PURE__*/React.createElement("i", {
    className: `pi ${icon}`,
    style: {
      position: 'absolute',
      left: '11px',
      top: '50%',
      transform: 'translateY(-50%)',
      fontSize: s.icon,
      color: 'var(--mg-input-placeholder)',
      pointerEvents: 'none'
    }
  }), /*#__PURE__*/React.createElement("input", _extends({
    type: type,
    value: value,
    placeholder: placeholder,
    disabled: disabled,
    onChange: onChange,
    onFocus: () => setFocus(true),
    onBlur: () => setFocus(false),
    style: {
      boxSizing: 'border-box',
      width: fullWidth ? '100%' : undefined,
      fontFamily: 'var(--mg-font-sans)',
      fontSize: s.fontSize,
      padding: s.padding,
      paddingLeft: icon ? '34px' : undefined,
      color: 'var(--mg-input-text)',
      background: disabled ? 'var(--mg-input-disabled-bg)' : 'var(--mg-input-bg)',
      border: `1px solid ${border}`,
      borderRadius: 'var(--mg-radius-md)',
      outline: 'none',
      boxShadow: focus && !invalid ? '0 0 0 2px var(--mg-primary-100)' : 'none',
      transition: 'border-color var(--mg-transition-fast), box-shadow var(--mg-transition-fast)',
      ...style
    }
  }, rest)));
}
Object.assign(__ds_scope, { Input });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/forms/Input.jsx", error: String((e && e.message) || e) }); }

// components/forms/Select.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/**
 * MACRO Global CRM — Select (display-only trigger)
 * Mirrors the PrimeVue Select closed state. For specimen/prototype use.
 */
function Select({
  value,
  placeholder = 'Выберите',
  size = 'md',
  disabled = false,
  fullWidth = false,
  open = false,
  onClick,
  style,
  ...rest
}) {
  const [hover, setHover] = React.useState(false);
  const sizes = {
    sm: {
      fontSize: '13px',
      padding: '6px 10px'
    },
    md: {
      fontSize: '14px',
      padding: '8px 12px'
    },
    lg: {
      fontSize: '15px',
      padding: '10px 14px'
    }
  };
  const s = sizes[size] || sizes.md;
  return /*#__PURE__*/React.createElement("div", _extends({
    onClick: disabled ? undefined : onClick,
    onMouseEnter: () => setHover(true),
    onMouseLeave: () => setHover(false),
    style: {
      boxSizing: 'border-box',
      display: 'inline-flex',
      alignItems: 'center',
      justifyContent: 'space-between',
      gap: '10px',
      width: fullWidth ? '100%' : undefined,
      minWidth: '140px',
      fontFamily: 'var(--mg-font-sans)',
      fontSize: s.fontSize,
      padding: s.padding,
      background: disabled ? 'var(--mg-input-disabled-bg)' : 'var(--mg-input-bg)',
      color: value ? 'var(--mg-input-text)' : 'var(--mg-input-placeholder)',
      border: `1px solid ${open ? 'var(--mg-input-focus-border)' : hover && !disabled ? 'var(--mg-input-hover-border)' : 'var(--mg-input-border)'}`,
      borderRadius: 'var(--mg-radius-md)',
      cursor: disabled ? 'not-allowed' : 'pointer',
      boxShadow: open ? '0 0 0 2px var(--mg-primary-100)' : 'none',
      transition: 'border-color var(--mg-transition-fast)',
      ...style
    }
  }, rest), /*#__PURE__*/React.createElement("span", {
    style: {
      overflow: 'hidden',
      textOverflow: 'ellipsis',
      whiteSpace: 'nowrap'
    }
  }, value || placeholder), /*#__PURE__*/React.createElement("i", {
    className: "pi pi-chevron-down",
    style: {
      fontSize: '11px',
      color: 'var(--mg-text-muted)',
      flexShrink: 0
    }
  }));
}
Object.assign(__ds_scope, { Select });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/forms/Select.jsx", error: String((e && e.message) || e) }); }

// components/forms/Switch.jsx
try { (() => {
/**
 * MACRO Global CRM — Switch
 * On/off toggle used across settings and inline row controls. Navy track when on,
 * neutral track when off. Distinct from Checkbox (boolean field) and
 * SegmentedControl (2–3 mutually-exclusive options).
 */
function Switch({
  on = false,
  onChange,
  disabled = false,
  size = 'md',
  label,
  style
}) {
  const dims = size === 'sm' ? {
    w: 32,
    h: 18,
    k: 14
  } : {
    w: 38,
    h: 22,
    k: 18
  };
  const track = /*#__PURE__*/React.createElement("button", {
    type: "button",
    role: "switch",
    "aria-checked": on,
    disabled: disabled,
    onClick: () => !disabled && onChange && onChange(!on),
    style: {
      width: dims.w,
      height: dims.h,
      borderRadius: '999px',
      border: 'none',
      padding: 2,
      cursor: disabled ? 'not-allowed' : 'pointer',
      opacity: disabled ? 0.55 : 1,
      flexShrink: 0,
      background: on ? 'var(--mg-primary-900)' : 'var(--mg-border-strong)',
      transition: 'background var(--mg-transition-fast)',
      display: 'inline-flex',
      alignItems: 'center'
    }
  }, /*#__PURE__*/React.createElement("span", {
    style: {
      width: dims.k,
      height: dims.k,
      borderRadius: '50%',
      background: '#fff',
      boxShadow: 'var(--mg-shadow-sm)',
      transition: 'transform var(--mg-transition-fast)',
      transform: on ? `translateX(${dims.w - dims.k - 4}px)` : 'translateX(0)'
    }
  }));
  if (!label) return React.cloneElement(track, {
    style: {
      ...track.props.style,
      ...style
    }
  });
  return /*#__PURE__*/React.createElement("label", {
    style: {
      display: 'inline-flex',
      alignItems: 'center',
      gap: '10px',
      fontFamily: 'var(--mg-font-sans)',
      fontSize: '14px',
      color: 'var(--mg-text-primary)',
      cursor: disabled ? 'not-allowed' : 'pointer',
      ...style
    }
  }, track, /*#__PURE__*/React.createElement("span", null, label));
}
Object.assign(__ds_scope, { Switch });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/forms/Switch.jsx", error: String((e && e.message) || e) }); }

// components/nav/PageHeader.jsx
try { (() => {
/**
 * MACRO Global CRM — PageHeader
 * Standard module header: icon plaque + title (+ optional subtitle) on the start,
 * actions on the end. Used at the top of every work surface (Deals, Contacts,
 * Tasks, Settings…). Sits on a card surface, separated by a bottom border.
 */
function PageHeader({
  icon,
  title,
  subtitle,
  actions,
  style
}) {
  return /*#__PURE__*/React.createElement("header", {
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: '14px',
      padding: '14px 22px',
      borderBottom: '1px solid var(--mg-border-default)',
      background: 'var(--mg-surface-card)',
      flexShrink: 0,
      flexWrap: 'wrap',
      fontFamily: 'var(--mg-font-sans)',
      ...style
    }
  }, icon && /*#__PURE__*/React.createElement("span", {
    style: {
      width: 38,
      height: 38,
      borderRadius: 'var(--mg-radius-md)',
      background: 'var(--mg-primary-100)',
      display: 'inline-flex',
      alignItems: 'center',
      justifyContent: 'center',
      flexShrink: 0
    }
  }, /*#__PURE__*/React.createElement("i", {
    className: 'pi ' + icon,
    style: {
      fontSize: '17px',
      color: 'var(--mg-primary-900)'
    }
  })), /*#__PURE__*/React.createElement("div", {
    style: {
      minWidth: 0
    }
  }, /*#__PURE__*/React.createElement("h1", {
    style: {
      fontSize: '19px',
      fontWeight: 600,
      color: 'var(--mg-text-primary)',
      margin: 0,
      lineHeight: 1.1
    }
  }, title), subtitle && /*#__PURE__*/React.createElement("div", {
    style: {
      fontSize: '12px',
      color: 'var(--mg-text-muted)',
      marginTop: '2px'
    }
  }, subtitle)), /*#__PURE__*/React.createElement("span", {
    style: {
      flex: 1,
      minWidth: 12
    }
  }), actions);
}
Object.assign(__ds_scope, { PageHeader });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/nav/PageHeader.jsx", error: String((e && e.message) || e) }); }

// components/nav/Pagination.jsx
try { (() => {
/**
 * MACRO Global CRM — Pagination
 * Page controls with first/prev/next/last + a windowed page list. RTL-safe
 * (chevrons flip via .mg-flip-rtl).
 */
function Pagination({
  page = 1,
  pageCount = 1,
  onChange,
  siblings = 1,
  style
}) {
  const go = p => onChange && p >= 1 && p <= pageCount && p !== page && onChange(p);
  const pages = [];
  const from = Math.max(1, page - siblings),
    to = Math.min(pageCount, page + siblings);
  if (from > 1) pages.push(1, from > 2 ? '…' : null);
  for (let p = from; p <= to; p++) pages.push(p);
  if (to < pageCount) pages.push(to < pageCount - 1 ? '…' : null, pageCount);
  const Arrow = ({
    icon,
    to: t,
    dis
  }) => /*#__PURE__*/React.createElement("button", {
    onClick: () => go(t),
    disabled: dis,
    style: {
      width: 28,
      height: 28,
      display: 'inline-flex',
      alignItems: 'center',
      justifyContent: 'center',
      border: 'none',
      background: 'transparent',
      borderRadius: 5,
      cursor: dis ? 'default' : 'pointer',
      opacity: dis ? 0.4 : 1,
      color: 'var(--mg-text-muted)'
    }
  }, /*#__PURE__*/React.createElement("i", {
    className: 'pi ' + icon + ' mg-flip-rtl',
    style: {
      fontSize: 13
    }
  }));
  return /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'inline-flex',
      alignItems: 'center',
      gap: 4,
      fontFamily: 'var(--mg-font-sans)',
      ...style
    }
  }, /*#__PURE__*/React.createElement(Arrow, {
    icon: "pi-angle-double-left",
    to: 1,
    dis: page <= 1
  }), /*#__PURE__*/React.createElement(Arrow, {
    icon: "pi-angle-left",
    to: page - 1,
    dis: page <= 1
  }), pages.filter(p => p !== null).map((p, i) => p === '…' ? /*#__PURE__*/React.createElement("span", {
    key: 'e' + i,
    style: {
      minWidth: 20,
      textAlign: 'center',
      color: 'var(--mg-text-muted)',
      fontSize: 13
    }
  }, "\u2026") : /*#__PURE__*/React.createElement("button", {
    key: p,
    onClick: () => go(p),
    style: {
      minWidth: 28,
      height: 28,
      display: 'inline-flex',
      alignItems: 'center',
      justifyContent: 'center',
      borderRadius: 5,
      border: 'none',
      cursor: 'pointer',
      fontFamily: 'inherit',
      fontSize: 13,
      fontWeight: p === page ? 700 : 500,
      background: p === page ? 'var(--mg-primary-900)' : 'transparent',
      color: p === page ? '#fff' : 'var(--mg-text-secondary)'
    }
  }, p)), /*#__PURE__*/React.createElement(Arrow, {
    icon: "pi-angle-right",
    to: page + 1,
    dis: page >= pageCount
  }), /*#__PURE__*/React.createElement(Arrow, {
    icon: "pi-angle-double-right",
    to: pageCount,
    dis: page >= pageCount
  }));
}
Object.assign(__ds_scope, { Pagination });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/nav/Pagination.jsx", error: String((e && e.message) || e) }); }

// components/nav/SegmentedControl.jsx
try { (() => {
/**
 * MACRO Global CRM — SegmentedControl
 * Pill segmented switch (Kanban/List view toggle, scope switch). Options are
 * strings or [{ key, label, icon }]. Icon-only when label omitted.
 */
function SegmentedControl({
  options = [],
  value,
  onChange,
  size = 'md',
  style
}) {
  const opts = options.map(o => typeof o === 'string' ? {
    key: o,
    label: o
  } : o);
  const h = size === 'sm' ? 25 : size === 'lg' ? 34 : 30;
  return /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'inline-flex',
      gap: 2,
      background: 'var(--mg-surface-muted)',
      borderRadius: 7,
      padding: 3,
      fontFamily: 'var(--mg-font-sans)',
      ...style
    }
  }, opts.map(o => {
    const on = o.key === value;
    const iconOnly = o.icon && !o.label;
    return /*#__PURE__*/React.createElement("button", {
      key: o.key,
      onClick: () => onChange && onChange(o.key),
      title: o.title || o.label,
      style: {
        display: 'inline-flex',
        alignItems: 'center',
        justifyContent: 'center',
        gap: 6,
        height: h,
        width: iconOnly ? h + 2 : undefined,
        padding: iconOnly ? 0 : '0 12px',
        border: 'none',
        borderRadius: 5,
        cursor: 'pointer',
        fontFamily: 'inherit',
        fontSize: 12,
        fontWeight: 600,
        background: on ? 'var(--mg-surface-card)' : 'transparent',
        color: on ? 'var(--mg-primary-900)' : 'var(--mg-text-muted)',
        boxShadow: on ? 'var(--mg-shadow-sm)' : 'none'
      }
    }, o.icon && /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + o.icon,
      style: {
        fontSize: 14
      }
    }), o.label);
  }));
}
Object.assign(__ds_scope, { SegmentedControl });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/nav/SegmentedControl.jsx", error: String((e && e.message) || e) }); }

// components/nav/Tabs.jsx
try { (() => {
/**
 * MACRO Global CRM — Tabs
 * Underline tab bar (deal card, entity card). items: [{ key, label, count, icon }].
 */
function Tabs({
  items = [],
  value,
  onChange,
  style
}) {
  return /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: 4,
      borderBottom: '1px solid var(--mg-border-default)',
      fontFamily: 'var(--mg-font-sans)',
      ...style
    }
  }, items.map(it => {
    const on = it.key === value;
    return /*#__PURE__*/React.createElement("button", {
      key: it.key,
      onClick: () => onChange && onChange(it.key),
      style: {
        display: 'inline-flex',
        alignItems: 'center',
        gap: 7,
        background: 'transparent',
        border: 'none',
        cursor: 'pointer',
        padding: '10px 14px',
        marginBottom: -1,
        fontFamily: 'inherit',
        fontSize: 14,
        fontWeight: on ? 600 : 500,
        color: on ? 'var(--mg-primary-900)' : 'var(--mg-text-muted)',
        borderBottom: '2px solid ' + (on ? 'var(--mg-primary-900)' : 'transparent')
      }
    }, it.icon && /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + it.icon,
      style: {
        fontSize: 14
      }
    }), it.label, it.count != null && /*#__PURE__*/React.createElement("span", {
      style: {
        display: 'inline-flex',
        alignItems: 'center',
        justifyContent: 'center',
        minWidth: 20,
        height: 20,
        padding: '0 6px',
        borderRadius: 999,
        fontSize: 11,
        fontWeight: 700,
        background: on ? 'var(--mg-primary-100)' : 'var(--mg-surface-hover)',
        color: on ? 'var(--mg-primary-900)' : 'var(--mg-text-muted)'
      }
    }, it.count));
  }));
}
Object.assign(__ds_scope, { Tabs });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/nav/Tabs.jsx", error: String((e && e.message) || e) }); }

// components/overlay/CommandPalette.jsx
try { (() => {
/**
 * MACRO Global CRM — CommandPalette (Cmd+K)
 * Global search + quick actions overlay. Presentational + lightly interactive:
 * type to filter the provided items, arrow list, ⌘K hint. Pass `items` as
 * [{ icon, label, sub, kbd, active, onSelect }].
 */
function CommandPalette({
  open = true,
  placeholder = 'Поиск лидов, юнитов, сделок…',
  items = [],
  onClose,
  hint = '⌘K',
  style
}) {
  const [q, setQ] = React.useState('');
  if (!open) return null;
  const filtered = q.trim() ? items.filter(it => (it.label + ' ' + (it.sub || '')).toLowerCase().includes(q.trim().toLowerCase())) : items;
  return /*#__PURE__*/React.createElement("div", {
    onClick: onClose,
    style: {
      position: 'fixed',
      inset: 0,
      zIndex: 1000,
      background: 'rgba(9,16,32,.5)',
      display: 'flex',
      alignItems: 'flex-start',
      justifyContent: 'center',
      padding: '96px 20px',
      fontFamily: 'var(--mg-font-sans)',
      ...style
    }
  }, /*#__PURE__*/React.createElement("div", {
    onClick: e => e.stopPropagation(),
    style: {
      width: '100%',
      maxWidth: 560,
      background: 'var(--mg-surface-card)',
      border: '1px solid var(--mg-border-default)',
      borderRadius: 'var(--mg-radius-lg)',
      boxShadow: 'var(--mg-shadow-lg)',
      overflow: 'hidden'
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: 11,
      padding: '15px 17px',
      borderBottom: '1px solid var(--mg-border-default)'
    }
  }, /*#__PURE__*/React.createElement("i", {
    className: "pi pi-search",
    style: {
      color: 'var(--mg-text-muted)'
    }
  }), /*#__PURE__*/React.createElement("input", {
    autoFocus: true,
    value: q,
    onChange: e => setQ(e.target.value),
    placeholder: placeholder,
    style: {
      flex: 1,
      border: 'none',
      outline: 'none',
      background: 'transparent',
      fontSize: 15,
      color: 'var(--mg-text-primary)',
      fontFamily: 'inherit'
    }
  }), /*#__PURE__*/React.createElement("span", {
    style: {
      fontSize: 11,
      fontWeight: 600,
      color: 'var(--mg-text-muted)',
      border: '1px solid var(--mg-border-default)',
      borderRadius: 6,
      padding: '2px 7px'
    }
  }, hint)), /*#__PURE__*/React.createElement("div", {
    style: {
      maxHeight: 320,
      overflowY: 'auto',
      padding: 6
    }
  }, filtered.length === 0 && /*#__PURE__*/React.createElement("div", {
    style: {
      padding: '22px 16px',
      textAlign: 'center',
      fontSize: 13,
      color: 'var(--mg-text-muted)'
    }
  }, "\u041D\u0438\u0447\u0435\u0433\u043E \u043D\u0435 \u043D\u0430\u0439\u0434\u0435\u043D\u043E"), filtered.map((it, i) => /*#__PURE__*/React.createElement("div", {
    key: i,
    onClick: it.onSelect,
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: 12,
      padding: '11px 12px',
      borderRadius: 'var(--mg-radius-md)',
      cursor: 'pointer',
      background: it.active ? 'var(--mg-primary-100)' : 'transparent'
    }
  }, /*#__PURE__*/React.createElement("i", {
    className: `pi ${it.icon || 'pi-arrow-right'}`,
    style: {
      fontSize: 15,
      color: it.active ? 'var(--mg-primary-900)' : 'var(--mg-text-muted)',
      width: 18,
      textAlign: 'center'
    }
  }), /*#__PURE__*/React.createElement("div", {
    style: {
      flex: 1,
      minWidth: 0
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      fontSize: 14,
      color: 'var(--mg-text-primary)',
      overflow: 'hidden',
      textOverflow: 'ellipsis',
      whiteSpace: 'nowrap'
    }
  }, it.label), it.sub && /*#__PURE__*/React.createElement("div", {
    style: {
      fontSize: 12,
      color: 'var(--mg-text-muted)'
    }
  }, it.sub)), it.kbd && /*#__PURE__*/React.createElement("span", {
    style: {
      fontSize: 11,
      color: 'var(--mg-text-muted)'
    }
  }, it.kbd))))));
}
Object.assign(__ds_scope, { CommandPalette });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/overlay/CommandPalette.jsx", error: String((e && e.message) || e) }); }

// components/overlay/Dialog.jsx
try { (() => {
/**
 * MACRO Global CRM — Dialog
 * Centered modal with scrim, title bar, body and footer actions. Consolidates
 * the confirm/edit dialogs hand-rolled in the deal card.
 */
function Dialog({
  open = true,
  title,
  icon,
  children,
  footer,
  onClose,
  width = 420,
  style
}) {
  if (!open) return null;
  return /*#__PURE__*/React.createElement("div", {
    onClick: onClose,
    style: {
      position: 'fixed',
      inset: 0,
      zIndex: 'var(--mg-z-modal, 1300)',
      background: 'rgba(9,16,32,.5)',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      padding: 20,
      fontFamily: 'var(--mg-font-sans)'
    }
  }, /*#__PURE__*/React.createElement("div", {
    onClick: e => e.stopPropagation(),
    role: "dialog",
    "aria-modal": "true",
    style: {
      width: '100%',
      maxWidth: width,
      background: 'var(--mg-surface-card)',
      borderRadius: 'var(--mg-radius-lg)',
      boxShadow: 'var(--mg-shadow-lg)',
      overflow: 'hidden',
      animation: 'mg-scale-in .16s ease',
      ...style
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: 10,
      padding: '16px 20px',
      borderBottom: '1px solid var(--mg-border-default)'
    }
  }, icon && /*#__PURE__*/React.createElement("i", {
    className: 'pi ' + icon,
    style: {
      fontSize: 17,
      color: 'var(--mg-primary-900)'
    }
  }), /*#__PURE__*/React.createElement("span", {
    style: {
      flex: 1,
      fontSize: 16,
      fontWeight: 600,
      color: 'var(--mg-text-primary)'
    }
  }, title), onClose && /*#__PURE__*/React.createElement("i", {
    className: "pi pi-times",
    onClick: onClose,
    style: {
      fontSize: 14,
      color: 'var(--mg-text-muted)',
      cursor: 'pointer'
    }
  })), /*#__PURE__*/React.createElement("div", {
    style: {
      padding: '18px 20px',
      fontSize: 14,
      color: 'var(--mg-text-secondary)',
      lineHeight: 1.55
    }
  }, children), footer && /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      justifyContent: 'flex-end',
      gap: 10,
      padding: '0 20px 18px'
    }
  }, footer)));
}
Object.assign(__ds_scope, { Dialog });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/overlay/Dialog.jsx", error: String((e && e.message) || e) }); }

// components/overlay/Menu.jsx
try { (() => {
/**
 * MACRO Global CRM — Menu
 * Dropdown/context menu. Consolidates the row-menu pattern hand-rolled across
 * Tasks/Contacts/Deal pages. items: [{ icon, label, onClick, danger }] or
 * { sep: true } for a divider. Render inside a position:relative anchor.
 */
function Menu({
  items = [],
  onClose,
  align = 'end',
  top = 'calc(100% + 4px)',
  width = 200,
  style
}) {
  return /*#__PURE__*/React.createElement("div", {
    onMouseLeave: onClose,
    role: "menu",
    style: {
      position: 'absolute',
      top,
      [align === 'end' ? 'insetInlineEnd' : 'insetInlineStart']: 0,
      zIndex: 'var(--mg-z-dropdown, 1000)',
      minWidth: width,
      background: 'var(--mg-surface-card)',
      border: '1px solid var(--mg-border-default)',
      borderRadius: 'var(--mg-radius-md)',
      boxShadow: 'var(--mg-shadow-lg)',
      padding: 5,
      fontFamily: 'var(--mg-font-sans)',
      animation: 'mg-slide-up .14s ease',
      ...style
    }
  }, items.map((it, i) => it.sep ? /*#__PURE__*/React.createElement("div", {
    key: i,
    style: {
      height: 1,
      background: 'var(--mg-border-default)',
      margin: '5px 0'
    }
  }) : /*#__PURE__*/React.createElement("button", {
    key: i,
    role: "menuitem",
    onClick: () => {
      it.onClick && it.onClick();
      onClose && onClose();
    },
    onMouseEnter: e => e.currentTarget.style.background = 'var(--mg-surface-hover)',
    onMouseLeave: e => e.currentTarget.style.background = 'transparent',
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: 10,
      width: '100%',
      background: 'transparent',
      border: 'none',
      cursor: 'pointer',
      fontFamily: 'inherit',
      fontSize: 13,
      fontWeight: 500,
      padding: '8px 10px',
      borderRadius: 'var(--mg-radius-sm)',
      textAlign: 'start',
      color: it.danger ? 'var(--mg-status-danger-text)' : 'var(--mg-text-primary)'
    }
  }, it.icon && /*#__PURE__*/React.createElement("i", {
    className: 'pi ' + it.icon,
    style: {
      fontSize: 14,
      width: 15,
      color: it.danger ? 'var(--mg-status-danger-text)' : 'var(--mg-text-muted)'
    }
  }), /*#__PURE__*/React.createElement("span", {
    style: {
      flex: 1
    }
  }, it.label), it.kbd && /*#__PURE__*/React.createElement("span", {
    style: {
      fontSize: 11,
      color: 'var(--mg-text-muted)'
    }
  }, it.kbd))));
}
Object.assign(__ds_scope, { Menu });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/overlay/Menu.jsx", error: String((e && e.message) || e) }); }

// components/overlay/Tooltip.jsx
try { (() => {
/**
 * MACRO Global CRM — Tooltip
 * Hover/focus hint. Wraps a single child; shows `label` on a dark chip.
 * placement: top | bottom | start | end.
 */
function Tooltip({
  label,
  placement = 'top',
  children,
  style
}) {
  const [show, setShow] = React.useState(false);
  const pos = {
    top: {
      bottom: '100%',
      insetInlineStart: '50%',
      transform: 'translateX(-50%) translateY(-6px)'
    },
    bottom: {
      top: '100%',
      insetInlineStart: '50%',
      transform: 'translateX(-50%) translateY(6px)'
    },
    start: {
      insetInlineEnd: '100%',
      top: '50%',
      transform: 'translateY(-50%) translateX(-6px)'
    },
    end: {
      insetInlineStart: '100%',
      top: '50%',
      transform: 'translateY(-50%) translateX(6px)'
    }
  }[placement];
  return /*#__PURE__*/React.createElement("span", {
    style: {
      position: 'relative',
      display: 'inline-flex',
      ...style
    },
    onMouseEnter: () => setShow(true),
    onMouseLeave: () => setShow(false),
    onFocusCapture: () => setShow(true),
    onBlurCapture: () => setShow(false)
  }, children, show && label && /*#__PURE__*/React.createElement("span", {
    role: "tooltip",
    style: {
      position: 'absolute',
      ...pos,
      zIndex: 'var(--mg-z-tooltip, 1500)',
      whiteSpace: 'nowrap',
      background: 'var(--mg-gray-900)',
      color: 'var(--mg-gray-0)',
      fontFamily: 'var(--mg-font-sans)',
      fontSize: 12,
      fontWeight: 500,
      padding: '5px 9px',
      borderRadius: 'var(--mg-radius-sm)',
      boxShadow: 'var(--mg-shadow-md)',
      pointerEvents: 'none',
      animation: 'mg-fade-in .12s ease'
    }
  }, label));
}
Object.assign(__ds_scope, { Tooltip });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/overlay/Tooltip.jsx", error: String((e && e.message) || e) }); }

// components/overlay/Tree.jsx
try { (() => {
/**
 * MACRO Global CRM — Tree
 * Hierarchy view (property → building → entrance → units). Recursive nodes:
 * { label, icon, count, defaultExpanded, children: [...] }. RTL-safe via
 * logical padding-inline-start indentation.
 */
function TreeNode({
  node,
  depth
}) {
  const [open, setOpen] = React.useState(node.defaultExpanded !== false);
  const hasChildren = node.children && node.children.length > 0;
  return /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("div", {
    onClick: () => hasChildren && setOpen(!open),
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: 9,
      padding: '7px 8px',
      paddingInlineStart: 8 + depth * 20,
      borderRadius: 'var(--mg-radius-sm)',
      cursor: hasChildren ? 'pointer' : 'default',
      fontSize: 14,
      color: 'var(--mg-text-primary)'
    }
  }, hasChildren ? /*#__PURE__*/React.createElement("i", {
    className: `pi ${open ? 'pi-chevron-down' : 'pi-chevron-right'} mg-flip-rtl`,
    style: {
      fontSize: 11,
      color: 'var(--mg-text-muted)',
      width: 12
    }
  }) : /*#__PURE__*/React.createElement("span", {
    style: {
      width: 12,
      display: 'inline-block'
    }
  }), node.icon && /*#__PURE__*/React.createElement("i", {
    className: `pi ${node.icon}`,
    style: {
      fontSize: 14,
      color: depth === 0 ? 'var(--mg-primary-900)' : 'var(--mg-text-secondary)'
    }
  }), /*#__PURE__*/React.createElement("span", {
    style: {
      flex: 1,
      minWidth: 0,
      overflow: 'hidden',
      textOverflow: 'ellipsis',
      whiteSpace: 'nowrap'
    }
  }, node.label), node.count != null && /*#__PURE__*/React.createElement("span", {
    style: {
      fontSize: 12,
      color: 'var(--mg-text-muted)'
    }
  }, node.count)), hasChildren && open && node.children.map((c, i) => /*#__PURE__*/React.createElement(TreeNode, {
    key: i,
    node: c,
    depth: depth + 1
  })));
}
function Tree({
  nodes = [],
  style
}) {
  return /*#__PURE__*/React.createElement("div", {
    style: {
      fontFamily: 'var(--mg-font-sans)',
      display: 'flex',
      flexDirection: 'column',
      gap: 1,
      ...style
    }
  }, nodes.map((n, i) => /*#__PURE__*/React.createElement(TreeNode, {
    key: i,
    node: n,
    depth: 0
  })));
}
Object.assign(__ds_scope, { Tree });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/overlay/Tree.jsx", error: String((e && e.message) || e) }); }

// exports/design-handoff/redesign/tweaks-panel.jsx
try { (() => {
// @ds-adherence-ignore -- omelette starter scaffold (raw elements/hex/px by design)

/* BEGIN USAGE */
// tweaks-panel.jsx
// Reusable Tweaks shell + form-control helpers.
// Exports (to window): useTweaks, TweaksPanel, TweakSection, TweakRow, TweakSlider,
//   TweakToggle, TweakRadio, TweakSelect, TweakText, TweakNumber, TweakColor, TweakButton.
//
// Owns the host protocol (listens for __activate_edit_mode / __deactivate_edit_mode,
// posts __edit_mode_available / __edit_mode_set_keys / __edit_mode_dismissed) so
// individual prototypes don't re-roll it. Ships a consistent set of controls so you
// don't hand-draw <input type="range">, segmented radios, steppers, etc.
//
// Usage (in an HTML file that loads React + Babel):
//
//   const TWEAK_DEFAULTS = /*EDITMODE-BEGIN*/{
//     "primaryColor": "#D97757",
//     "palette": ["#D97757", "#29261b", "#f6f4ef"],
//     "fontSize": 16,
//     "density": "regular",
//     "dark": false
//   }/*EDITMODE-END*/;
//
//   function App() {
//     const [t, setTweak] = useTweaks(TWEAK_DEFAULTS);
//     return (
//       <div style={{ fontSize: t.fontSize, color: t.primaryColor }}>
//         Hello
//         <TweaksPanel>
//           <TweakSection label="Typography" />
//           <TweakSlider label="Font size" value={t.fontSize} min={10} max={32} unit="px"
//                        onChange={(v) => setTweak('fontSize', v)} />
//           <TweakRadio  label="Density" value={t.density}
//                        options={['compact', 'regular', 'comfy']}
//                        onChange={(v) => setTweak('density', v)} />
//           <TweakSection label="Theme" />
//           <TweakColor  label="Primary" value={t.primaryColor}
//                        options={['#D97757', '#2A6FDB', '#1F8A5B', '#7A5AE0']}
//                        onChange={(v) => setTweak('primaryColor', v)} />
//           <TweakColor  label="Palette" value={t.palette}
//                        options={[['#D97757', '#29261b', '#f6f4ef'],
//                                  ['#475569', '#0f172a', '#f1f5f9']]}
//                        onChange={(v) => setTweak('palette', v)} />
//           <TweakToggle label="Dark mode" value={t.dark}
//                        onChange={(v) => setTweak('dark', v)} />
//         </TweaksPanel>
//       </div>
//     );
//   }
//
// TweakRadio is the segmented control for 2–3 short options (auto-falls-back to
// TweakSelect past ~16/~10 chars per label); reach for TweakSelect directly when
// options are many or long. For color tweaks always curate 3-4 options rather than
// a free picker; an option can also be a whole 2–5 color palette (the stored value
// is the array). The Tweak* controls are a floor, not a ceiling — build custom
// controls inside the panel if a tweak calls for UI they don't cover.
/* END USAGE */
// ─────────────────────────────────────────────────────────────────────────────

const __TWEAKS_STYLE = `
  .twk-panel{position:fixed;right:16px;bottom:16px;z-index:2147483646;width:280px;
    max-height:calc(100vh - 32px);display:flex;flex-direction:column;
    transform:scale(var(--dc-inv-zoom,1));transform-origin:bottom right;
    background:rgba(250,249,247,.78);color:#29261b;
    -webkit-backdrop-filter:blur(24px) saturate(160%);backdrop-filter:blur(24px) saturate(160%);
    border:.5px solid rgba(255,255,255,.6);border-radius:14px;
    box-shadow:0 1px 0 rgba(255,255,255,.5) inset,0 12px 40px rgba(0,0,0,.18);
    font:11.5px/1.4 ui-sans-serif,system-ui,-apple-system,sans-serif;overflow:hidden}
  .twk-hd{display:flex;align-items:center;justify-content:space-between;
    padding:10px 8px 10px 14px;cursor:move;user-select:none}
  .twk-hd b{font-size:12px;font-weight:600;letter-spacing:.01em}
  .twk-x{appearance:none;border:0;background:transparent;color:rgba(41,38,27,.55);
    width:22px;height:22px;border-radius:6px;cursor:default;font-size:13px;line-height:1}
  .twk-x:hover{background:rgba(0,0,0,.06);color:#29261b}
  .twk-body{padding:2px 14px 14px;display:flex;flex-direction:column;gap:10px;
    overflow-y:auto;overflow-x:hidden;min-height:0;
    scrollbar-width:thin;scrollbar-color:rgba(0,0,0,.15) transparent}
  .twk-body::-webkit-scrollbar{width:8px}
  .twk-body::-webkit-scrollbar-track{background:transparent;margin:2px}
  .twk-body::-webkit-scrollbar-thumb{background:rgba(0,0,0,.15);border-radius:4px;
    border:2px solid transparent;background-clip:content-box}
  .twk-body::-webkit-scrollbar-thumb:hover{background:rgba(0,0,0,.25);
    border:2px solid transparent;background-clip:content-box}
  .twk-row{display:flex;flex-direction:column;gap:5px}
  .twk-row-h{flex-direction:row;align-items:center;justify-content:space-between;gap:10px}
  .twk-lbl{display:flex;justify-content:space-between;align-items:baseline;
    color:rgba(41,38,27,.72)}
  .twk-lbl>span:first-child{font-weight:500}
  .twk-val{color:rgba(41,38,27,.5);font-variant-numeric:tabular-nums}

  .twk-sect{font-size:10px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;
    color:rgba(41,38,27,.45);padding:10px 0 0}
  .twk-sect:first-child{padding-top:0}

  .twk-field{appearance:none;box-sizing:border-box;width:100%;min-width:0;height:26px;padding:0 8px;
    border:.5px solid rgba(0,0,0,.1);border-radius:7px;
    background:rgba(255,255,255,.6);color:inherit;font:inherit;outline:none}
  .twk-field:focus{border-color:rgba(0,0,0,.25);background:rgba(255,255,255,.85)}
  select.twk-field{padding-right:22px;
    background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'><path fill='rgba(0,0,0,.5)' d='M0 0h10L5 6z'/></svg>");
    background-repeat:no-repeat;background-position:right 8px center}

  .twk-slider{appearance:none;-webkit-appearance:none;width:100%;height:4px;margin:6px 0;
    border-radius:999px;background:rgba(0,0,0,.12);outline:none}
  .twk-slider::-webkit-slider-thumb{-webkit-appearance:none;appearance:none;
    width:14px;height:14px;border-radius:50%;background:#fff;
    border:.5px solid rgba(0,0,0,.12);box-shadow:0 1px 3px rgba(0,0,0,.2);cursor:default}
  .twk-slider::-moz-range-thumb{width:14px;height:14px;border-radius:50%;
    background:#fff;border:.5px solid rgba(0,0,0,.12);box-shadow:0 1px 3px rgba(0,0,0,.2);cursor:default}

  .twk-seg{position:relative;display:flex;padding:2px;border-radius:8px;
    background:rgba(0,0,0,.06);user-select:none}
  .twk-seg-thumb{position:absolute;top:2px;bottom:2px;border-radius:6px;
    background:rgba(255,255,255,.9);box-shadow:0 1px 2px rgba(0,0,0,.12);
    transition:left .15s cubic-bezier(.3,.7,.4,1),width .15s}
  .twk-seg.dragging .twk-seg-thumb{transition:none}
  .twk-seg button{appearance:none;position:relative;z-index:1;flex:1;border:0;
    background:transparent;color:inherit;font:inherit;font-weight:500;min-height:22px;
    border-radius:6px;cursor:default;padding:4px 6px;line-height:1.2;
    overflow-wrap:anywhere}

  .twk-toggle{position:relative;width:32px;height:18px;border:0;border-radius:999px;
    background:rgba(0,0,0,.15);transition:background .15s;cursor:default;padding:0}
  .twk-toggle[data-on="1"]{background:#34c759}
  .twk-toggle i{position:absolute;top:2px;left:2px;width:14px;height:14px;border-radius:50%;
    background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.25);transition:transform .15s}
  .twk-toggle[data-on="1"] i{transform:translateX(14px)}

  .twk-num{display:flex;align-items:center;box-sizing:border-box;min-width:0;height:26px;padding:0 0 0 8px;
    border:.5px solid rgba(0,0,0,.1);border-radius:7px;background:rgba(255,255,255,.6)}
  .twk-num-lbl{font-weight:500;color:rgba(41,38,27,.6);cursor:ew-resize;
    user-select:none;padding-right:8px}
  .twk-num input{flex:1;min-width:0;height:100%;border:0;background:transparent;
    font:inherit;font-variant-numeric:tabular-nums;text-align:right;padding:0 8px 0 0;
    outline:none;color:inherit;-moz-appearance:textfield}
  .twk-num input::-webkit-inner-spin-button,.twk-num input::-webkit-outer-spin-button{
    -webkit-appearance:none;margin:0}
  .twk-num-unit{padding-right:8px;color:rgba(41,38,27,.45)}

  .twk-btn{appearance:none;height:26px;padding:0 12px;border:0;border-radius:7px;
    background:rgba(0,0,0,.78);color:#fff;font:inherit;font-weight:500;cursor:default}
  .twk-btn:hover{background:rgba(0,0,0,.88)}
  .twk-btn.secondary{background:rgba(0,0,0,.06);color:inherit}
  .twk-btn.secondary:hover{background:rgba(0,0,0,.1)}

  .twk-swatch{appearance:none;-webkit-appearance:none;width:56px;height:22px;
    border:.5px solid rgba(0,0,0,.1);border-radius:6px;padding:0;cursor:default;
    background:transparent;flex-shrink:0}
  .twk-swatch::-webkit-color-swatch-wrapper{padding:0}
  .twk-swatch::-webkit-color-swatch{border:0;border-radius:5.5px}
  .twk-swatch::-moz-color-swatch{border:0;border-radius:5.5px}

  .twk-chips{display:flex;gap:6px}
  .twk-chip{position:relative;appearance:none;flex:1;min-width:0;height:46px;
    padding:0;border:0;border-radius:6px;overflow:hidden;cursor:default;
    box-shadow:0 0 0 .5px rgba(0,0,0,.12),0 1px 2px rgba(0,0,0,.06);
    transition:transform .12s cubic-bezier(.3,.7,.4,1),box-shadow .12s}
  .twk-chip:hover{transform:translateY(-1px);
    box-shadow:0 0 0 .5px rgba(0,0,0,.18),0 4px 10px rgba(0,0,0,.12)}
  .twk-chip[data-on="1"]{box-shadow:0 0 0 1.5px rgba(0,0,0,.85),
    0 2px 6px rgba(0,0,0,.15)}
  .twk-chip>span{position:absolute;top:0;bottom:0;right:0;width:34%;
    display:flex;flex-direction:column;box-shadow:-1px 0 0 rgba(0,0,0,.1)}
  .twk-chip>span>i{flex:1;box-shadow:0 -1px 0 rgba(0,0,0,.1)}
  .twk-chip>span>i:first-child{box-shadow:none}
  .twk-chip svg{position:absolute;top:6px;left:6px;width:13px;height:13px;
    filter:drop-shadow(0 1px 1px rgba(0,0,0,.3))}
`;

// ── useTweaks ───────────────────────────────────────────────────────────────
// Single source of truth for tweak values. setTweak persists via the host
// (__edit_mode_set_keys → host rewrites the EDITMODE block on disk).
function useTweaks(defaults) {
  const [values, setValues] = React.useState(defaults);
  // Accepts either setTweak('key', value) or setTweak({ key: value, ... }) so a
  // useState-style call doesn't write a "[object Object]" key into the persisted
  // JSON block.
  const setTweak = React.useCallback((keyOrEdits, val) => {
    const edits = typeof keyOrEdits === 'object' && keyOrEdits !== null ? keyOrEdits : {
      [keyOrEdits]: val
    };
    setValues(prev => ({
      ...prev,
      ...edits
    }));
    window.parent.postMessage({
      type: '__edit_mode_set_keys',
      edits
    }, '*');
    // Same-window signal so in-page listeners (deck-stage rail thumbnails)
    // can react — the parent message only reaches the host, not peers.
    window.dispatchEvent(new CustomEvent('tweakchange', {
      detail: edits
    }));
  }, []);
  return [values, setTweak];
}

// ── TweaksPanel ─────────────────────────────────────────────────────────────
// Floating shell. Registers the protocol listener BEFORE announcing
// availability — if the announce ran first, the host's activate could land
// before our handler exists and the toolbar toggle would silently no-op.
// The close button posts __edit_mode_dismissed so the host's toolbar toggle
// flips off in lockstep; the host echoes __deactivate_edit_mode back which
// is what actually hides the panel.
function TweaksPanel({
  title = 'Tweaks',
  children
}) {
  const [open, setOpen] = React.useState(false);
  const dragRef = React.useRef(null);
  const offsetRef = React.useRef({
    x: 16,
    y: 16
  });
  const PAD = 16;
  const clampToViewport = React.useCallback(() => {
    const panel = dragRef.current;
    if (!panel) return;
    const w = panel.offsetWidth,
      h = panel.offsetHeight;
    const maxRight = Math.max(PAD, window.innerWidth - w - PAD);
    const maxBottom = Math.max(PAD, window.innerHeight - h - PAD);
    offsetRef.current = {
      x: Math.min(maxRight, Math.max(PAD, offsetRef.current.x)),
      y: Math.min(maxBottom, Math.max(PAD, offsetRef.current.y))
    };
    panel.style.right = offsetRef.current.x + 'px';
    panel.style.bottom = offsetRef.current.y + 'px';
  }, []);
  React.useEffect(() => {
    if (!open) return;
    clampToViewport();
    if (typeof ResizeObserver === 'undefined') {
      window.addEventListener('resize', clampToViewport);
      return () => window.removeEventListener('resize', clampToViewport);
    }
    const ro = new ResizeObserver(clampToViewport);
    ro.observe(document.documentElement);
    return () => ro.disconnect();
  }, [open, clampToViewport]);
  React.useEffect(() => {
    const onMsg = e => {
      const t = e?.data?.type;
      if (t === '__activate_edit_mode') setOpen(true);else if (t === '__deactivate_edit_mode') setOpen(false);
    };
    window.addEventListener('message', onMsg);
    window.parent.postMessage({
      type: '__edit_mode_available'
    }, '*');
    return () => window.removeEventListener('message', onMsg);
  }, []);
  const dismiss = () => {
    setOpen(false);
    window.parent.postMessage({
      type: '__edit_mode_dismissed'
    }, '*');
  };
  const onDragStart = e => {
    const panel = dragRef.current;
    if (!panel) return;
    const r = panel.getBoundingClientRect();
    const sx = e.clientX,
      sy = e.clientY;
    const startRight = window.innerWidth - r.right;
    const startBottom = window.innerHeight - r.bottom;
    const move = ev => {
      offsetRef.current = {
        x: startRight - (ev.clientX - sx),
        y: startBottom - (ev.clientY - sy)
      };
      clampToViewport();
    };
    const up = () => {
      window.removeEventListener('mousemove', move);
      window.removeEventListener('mouseup', up);
    };
    window.addEventListener('mousemove', move);
    window.addEventListener('mouseup', up);
  };
  if (!open) return null;
  return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("style", null, __TWEAKS_STYLE), /*#__PURE__*/React.createElement("div", {
    ref: dragRef,
    className: "twk-panel",
    "data-omelette-chrome": "",
    style: {
      right: offsetRef.current.x,
      bottom: offsetRef.current.y
    }
  }, /*#__PURE__*/React.createElement("div", {
    className: "twk-hd",
    onMouseDown: onDragStart
  }, /*#__PURE__*/React.createElement("b", null, title), /*#__PURE__*/React.createElement("button", {
    className: "twk-x",
    "aria-label": "Close tweaks",
    onMouseDown: e => e.stopPropagation(),
    onClick: dismiss
  }, "\u2715")), /*#__PURE__*/React.createElement("div", {
    className: "twk-body"
  }, children)));
}

// ── Layout helpers ──────────────────────────────────────────────────────────

function TweakSection({
  label,
  children
}) {
  return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("div", {
    className: "twk-sect"
  }, label), children);
}
function TweakRow({
  label,
  value,
  children,
  inline = false
}) {
  return /*#__PURE__*/React.createElement("div", {
    className: inline ? 'twk-row twk-row-h' : 'twk-row'
  }, /*#__PURE__*/React.createElement("div", {
    className: "twk-lbl"
  }, /*#__PURE__*/React.createElement("span", null, label), value != null && /*#__PURE__*/React.createElement("span", {
    className: "twk-val"
  }, value)), children);
}

// ── Controls ────────────────────────────────────────────────────────────────

function TweakSlider({
  label,
  value,
  min = 0,
  max = 100,
  step = 1,
  unit = '',
  onChange
}) {
  return /*#__PURE__*/React.createElement(TweakRow, {
    label: label,
    value: `${value}${unit}`
  }, /*#__PURE__*/React.createElement("input", {
    type: "range",
    className: "twk-slider",
    min: min,
    max: max,
    step: step,
    value: value,
    onChange: e => onChange(Number(e.target.value))
  }));
}
function TweakToggle({
  label,
  value,
  onChange
}) {
  return /*#__PURE__*/React.createElement("div", {
    className: "twk-row twk-row-h"
  }, /*#__PURE__*/React.createElement("div", {
    className: "twk-lbl"
  }, /*#__PURE__*/React.createElement("span", null, label)), /*#__PURE__*/React.createElement("button", {
    type: "button",
    className: "twk-toggle",
    "data-on": value ? '1' : '0',
    role: "switch",
    "aria-checked": !!value,
    onClick: () => onChange(!value)
  }, /*#__PURE__*/React.createElement("i", null)));
}
function TweakRadio({
  label,
  value,
  options,
  onChange
}) {
  const trackRef = React.useRef(null);
  const [dragging, setDragging] = React.useState(false);
  // The active value is read by pointer-move handlers attached for the lifetime
  // of a drag — ref it so a stale closure doesn't fire onChange for every move.
  const valueRef = React.useRef(value);
  valueRef.current = value;

  // Segments wrap mid-word once per-segment width runs out. The track is
  // ~248px (280 panel − 28 body pad − 4 seg pad), each button loses 12px
  // to its own padding, and 11.5px system-ui averages ~6.3px/char — so 2
  // options fit ~16 chars each, 3 fit ~10. Past that (or >3 options), fall
  // back to a dropdown rather than wrap.
  const labelLen = o => String(typeof o === 'object' ? o.label : o).length;
  const maxLen = options.reduce((m, o) => Math.max(m, labelLen(o)), 0);
  const fitsAsSegments = maxLen <= ({
    2: 16,
    3: 10
  }[options.length] ?? 0);
  if (!fitsAsSegments) {
    // <select> emits strings — map back to the original option value so the
    // fallback stays type-preserving (numbers, booleans) like the segment path.
    const resolve = s => {
      const m = options.find(o => String(typeof o === 'object' ? o.value : o) === s);
      return m === undefined ? s : typeof m === 'object' ? m.value : m;
    };
    return /*#__PURE__*/React.createElement(TweakSelect, {
      label: label,
      value: value,
      options: options,
      onChange: s => onChange(resolve(s))
    });
  }
  const opts = options.map(o => typeof o === 'object' ? o : {
    value: o,
    label: o
  });
  const idx = Math.max(0, opts.findIndex(o => o.value === value));
  const n = opts.length;
  const segAt = clientX => {
    const r = trackRef.current.getBoundingClientRect();
    const inner = r.width - 4;
    const i = Math.floor((clientX - r.left - 2) / inner * n);
    return opts[Math.max(0, Math.min(n - 1, i))].value;
  };
  const onPointerDown = e => {
    setDragging(true);
    const v0 = segAt(e.clientX);
    if (v0 !== valueRef.current) onChange(v0);
    const move = ev => {
      if (!trackRef.current) return;
      const v = segAt(ev.clientX);
      if (v !== valueRef.current) onChange(v);
    };
    const up = () => {
      setDragging(false);
      window.removeEventListener('pointermove', move);
      window.removeEventListener('pointerup', up);
    };
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', up);
  };
  return /*#__PURE__*/React.createElement(TweakRow, {
    label: label
  }, /*#__PURE__*/React.createElement("div", {
    ref: trackRef,
    role: "radiogroup",
    onPointerDown: onPointerDown,
    className: dragging ? 'twk-seg dragging' : 'twk-seg'
  }, /*#__PURE__*/React.createElement("div", {
    className: "twk-seg-thumb",
    style: {
      left: `calc(2px + ${idx} * (100% - 4px) / ${n})`,
      width: `calc((100% - 4px) / ${n})`
    }
  }), opts.map(o => /*#__PURE__*/React.createElement("button", {
    key: o.value,
    type: "button",
    role: "radio",
    "aria-checked": o.value === value
  }, o.label))));
}
function TweakSelect({
  label,
  value,
  options,
  onChange
}) {
  return /*#__PURE__*/React.createElement(TweakRow, {
    label: label
  }, /*#__PURE__*/React.createElement("select", {
    className: "twk-field",
    value: value,
    onChange: e => onChange(e.target.value)
  }, options.map(o => {
    const v = typeof o === 'object' ? o.value : o;
    const l = typeof o === 'object' ? o.label : o;
    return /*#__PURE__*/React.createElement("option", {
      key: v,
      value: v
    }, l);
  })));
}
function TweakText({
  label,
  value,
  placeholder,
  onChange
}) {
  return /*#__PURE__*/React.createElement(TweakRow, {
    label: label
  }, /*#__PURE__*/React.createElement("input", {
    className: "twk-field",
    type: "text",
    value: value,
    placeholder: placeholder,
    onChange: e => onChange(e.target.value)
  }));
}
function TweakNumber({
  label,
  value,
  min,
  max,
  step = 1,
  unit = '',
  onChange
}) {
  const clamp = n => {
    if (min != null && n < min) return min;
    if (max != null && n > max) return max;
    return n;
  };
  const startRef = React.useRef({
    x: 0,
    val: 0
  });
  const onScrubStart = e => {
    e.preventDefault();
    startRef.current = {
      x: e.clientX,
      val: value
    };
    const decimals = (String(step).split('.')[1] || '').length;
    const move = ev => {
      const dx = ev.clientX - startRef.current.x;
      const raw = startRef.current.val + dx * step;
      const snapped = Math.round(raw / step) * step;
      onChange(clamp(Number(snapped.toFixed(decimals))));
    };
    const up = () => {
      window.removeEventListener('pointermove', move);
      window.removeEventListener('pointerup', up);
    };
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', up);
  };
  return /*#__PURE__*/React.createElement("div", {
    className: "twk-num"
  }, /*#__PURE__*/React.createElement("span", {
    className: "twk-num-lbl",
    onPointerDown: onScrubStart
  }, label), /*#__PURE__*/React.createElement("input", {
    type: "number",
    value: value,
    min: min,
    max: max,
    step: step,
    onChange: e => onChange(clamp(Number(e.target.value)))
  }), unit && /*#__PURE__*/React.createElement("span", {
    className: "twk-num-unit"
  }, unit));
}

// Relative-luminance contrast pick — checkmarks drawn over a swatch need to
// read on both #111 and #fafafa without per-option configuration. Hex input
// only (#rgb / #rrggbb); named or rgb()/hsl() colors fall through to "light".
function __twkIsLight(hex) {
  const h = String(hex).replace('#', '');
  const x = h.length === 3 ? h.replace(/./g, c => c + c) : h.padEnd(6, '0');
  const n = parseInt(x.slice(0, 6), 16);
  if (Number.isNaN(n)) return true;
  const r = n >> 16 & 255,
    g = n >> 8 & 255,
    b = n & 255;
  return r * 299 + g * 587 + b * 114 > 148000;
}
const __TwkCheck = ({
  light
}) => /*#__PURE__*/React.createElement("svg", {
  viewBox: "0 0 14 14",
  "aria-hidden": "true"
}, /*#__PURE__*/React.createElement("path", {
  d: "M3 7.2 5.8 10 11 4.2",
  fill: "none",
  strokeWidth: "2.2",
  strokeLinecap: "round",
  strokeLinejoin: "round",
  stroke: light ? 'rgba(0,0,0,.78)' : '#fff'
}));

// TweakColor — curated color/palette picker. Each option is either a single
// hex string or an array of 1-5 hex strings; the card adapts — a lone color
// renders solid, a palette renders colors[0] as the hero (left ~2/3) with the
// rest stacked in a sharp column on the right. onChange emits the
// option in the shape it was passed (string stays string, array stays array).
// Without options it falls back to the native color input for back-compat.
function TweakColor({
  label,
  value,
  options,
  onChange
}) {
  if (!options || !options.length) {
    return /*#__PURE__*/React.createElement("div", {
      className: "twk-row twk-row-h"
    }, /*#__PURE__*/React.createElement("div", {
      className: "twk-lbl"
    }, /*#__PURE__*/React.createElement("span", null, label)), /*#__PURE__*/React.createElement("input", {
      type: "color",
      className: "twk-swatch",
      value: value,
      onChange: e => onChange(e.target.value)
    }));
  }
  // Native <input type=color> emits lowercase hex per the HTML spec, so
  // compare case-insensitively. String() guards JSON.stringify(undefined),
  // which returns the primitive undefined (no .toLowerCase).
  const key = o => String(JSON.stringify(o)).toLowerCase();
  const cur = key(value);
  return /*#__PURE__*/React.createElement(TweakRow, {
    label: label
  }, /*#__PURE__*/React.createElement("div", {
    className: "twk-chips",
    role: "radiogroup"
  }, options.map((o, i) => {
    const colors = Array.isArray(o) ? o : [o];
    const [hero, ...rest] = colors;
    const sup = rest.slice(0, 4);
    const on = key(o) === cur;
    return /*#__PURE__*/React.createElement("button", {
      key: i,
      type: "button",
      className: "twk-chip",
      role: "radio",
      "aria-checked": on,
      "data-on": on ? '1' : '0',
      "aria-label": colors.join(', '),
      title: colors.join(' · '),
      style: {
        background: hero
      },
      onClick: () => onChange(o)
    }, sup.length > 0 && /*#__PURE__*/React.createElement("span", null, sup.map((c, j) => /*#__PURE__*/React.createElement("i", {
      key: j,
      style: {
        background: c
      }
    }))), on && /*#__PURE__*/React.createElement(__TwkCheck, {
      light: __twkIsLight(hero)
    }));
  })));
}
function TweakButton({
  label,
  onClick,
  secondary = false
}) {
  return /*#__PURE__*/React.createElement("button", {
    type: "button",
    className: secondary ? 'twk-btn secondary' : 'twk-btn',
    onClick: onClick
  }, label);
}
Object.assign(window, {
  useTweaks,
  TweaksPanel,
  TweakSection,
  TweakRow,
  TweakSlider,
  TweakToggle,
  TweakRadio,
  TweakSelect,
  TweakText,
  TweakNumber,
  TweakColor,
  TweakButton
});
})(); } catch (e) { __ds_ns.__errors.push({ path: "exports/design-handoff/redesign/tweaks-panel.jsx", error: String((e && e.message) || e) }); }

// exports/mgcrm-package/design-handoff/redesign/access-section.jsx
try { (() => {
/* Доступ и оргструктура — Настройки → Система.
   Mirrors front/src/pages/AccessControlPage (index + DepartmentsTab, DepartmentTree,
   DepartmentSidePanel, OrgChartView, RolesPermissionsTab, PermissionMatrix,
   VisibilityScopeTab) + entities/accessControl + ru.json. Redesigned look.
   Exposes window.AccessTab. */
(function () {
  const {
    useState,
    useRef,
    useEffect
  } = React;

  /* ── i18n (ru.json → accessControl) ── */
  const A_T = {
    title: 'Доступ и оргструктура',
    subtitle: 'Отделы, роли и видимость записей',
    tabs: {
      departments: 'Отделы',
      roles: 'Роли и права',
      visibility: 'Видимость'
    },
    dep: {
      add: 'Добавить отдел',
      edit: 'Редактировать отдел',
      del: 'Удалить отдел',
      delConfirm: n => 'Удалить отдел «' + n + '»? Дочерние отделы и сотрудники останутся без родителя.',
      name: 'Название',
      parent: 'Родительский отдел',
      root: '— Корень —',
      manager: 'Руководитель',
      members: 'Сотрудники отдела',
      addMember: 'Добавить сотрудника',
      removeMember: 'Убрать из отдела',
      viewTree: 'Дерево',
      viewChart: 'Схема',
      empty: 'Отделы не созданы',
      emptyHint: 'Добавьте первый отдел, чтобы настроить оргструктуру',
      noMembers: 'Нет участников',
      saved: 'Отдел сохранён',
      deleted: 'Отдел удалён'
    },
    roles: {
      title: 'Матрица прав',
      adminNote: 'Роль admin всегда получает все права и не может быть ограничена.',
      permissionLabel: 'Право',
      save: 'Сохранить',
      reset: 'Сбросить',
      saved: 'Права ролей сохранены'
    },
    vis: {
      title: 'Видимость записей',
      warning: 'Настройки видимости влияют на то, какие записи (сделки, контакты, задачи) видит каждая роль. Изменяйте с осторожностью.',
      scopeAll: 'Все',
      scopeDepartment: 'Отдел (+подотделы)',
      scopeOwn: 'Свои',
      roleColumn: 'Роль',
      scopeColumn: 'Видимость записей',
      departmentHint: 'Значение «Отдел» работает только если у пользователя указан отдел.',
      save: 'Сохранить',
      reset: 'Сбросить',
      saved: 'Настройки видимости сохранены'
    }
  };
  const A_ROLES = ['admin', 'director', 'lawyer', 'manager', 'accountant', 'cfo'];
  const A_ROLE_LABEL = {
    admin: 'Администратор',
    director: 'Директор',
    lawyer: 'Юрист',
    manager: 'Менеджер',
    accountant: 'Бухгалтер',
    cfo: 'Финансовый директор'
  };
  const A_ROLE_SHORT = {
    admin: 'Админ',
    director: 'Директор',
    lawyer: 'Юрист',
    manager: 'Менеджер',
    accountant: 'Бухгалтер',
    cfo: 'Фин. дир.'
  };
  const A_ROLE_SEV = {
    admin: 'danger',
    director: 'warn',
    lawyer: 'info',
    manager: 'success',
    accountant: 'secondary',
    cfo: 'secondary'
  };
  const A_GROUPS = [{
    key: 'crm',
    label: 'CRM',
    perms: ['crm.view', 'crm.manage']
  }, {
    key: 'sales',
    label: 'Продажи',
    perms: ['sales.view', 'sales.manage']
  }, {
    key: 'contracts',
    label: 'Договоры',
    perms: ['contracts.view', 'contracts.manage']
  }, {
    key: 'users',
    label: 'Пользователи',
    perms: ['users.view', 'users.manage']
  }, {
    key: 'automation',
    label: 'Автоматизации',
    perms: ['automation.manage']
  }, {
    key: 'analytics',
    label: 'Аналитика',
    perms: ['analytics.view', 'settings.manage']
  }, {
    key: 'finance',
    label: 'Финансы',
    perms: ['finance.view', 'finance.entry', 'finance.posting', 'finance.journals.manual', 'finance.payments.approve', 'finance.period.close', 'finance.settings.manage', 'finance.reports.management']
  }, {
    key: 'system',
    label: 'Системные права',
    perms: ['admin-write', 'dedup-scan-all', 'view-manager-cabinet', 'system-reset']
  }];
  const A_PERM_LABEL = {
    'crm.view': 'CRM — просмотр',
    'crm.manage': 'CRM — управление',
    'sales.view': 'Продажи — просмотр',
    'sales.manage': 'Продажи — управление',
    'contracts.view': 'Договоры — просмотр',
    'contracts.manage': 'Договоры — управление',
    'users.view': 'Пользователи — просмотр',
    'users.manage': 'Пользователи — управление',
    'automation.manage': 'Автоматизации — управление',
    'analytics.view': 'Аналитика — просмотр',
    'settings.manage': 'Настройки системы — управление',
    'finance.view': 'Финансы — просмотр',
    'finance.entry': 'Финансы — ввод операций',
    'finance.posting': 'Финансы — проводки',
    'finance.journals.manual': 'Финансы — ручные журналы',
    'finance.payments.approve': 'Финансы — согласование платежей',
    'finance.period.close': 'Финансы — закрытие периода',
    'finance.settings.manage': 'Финансы — настройки',
    'finance.reports.management': 'Финансы — управленческие отчёты',
    'admin-write': 'Системные изменения (админ)',
    'dedup-scan-all': 'Дедупликация — полное сканирование',
    'view-manager-cabinet': 'Кабинет менеджера — доступ',
    'system-reset': 'Сброс системы'
  };
  /* default role → granted permissions */
  const A_DEFAULT_PERMS = {
    admin: A_GROUPS.flatMap(g => g.perms),
    director: ['crm.view', 'crm.manage', 'sales.view', 'sales.manage', 'contracts.view', 'contracts.manage', 'users.view', 'automation.manage', 'analytics.view', 'settings.manage', 'finance.view', 'view-manager-cabinet'],
    lawyer: ['crm.view', 'contracts.view', 'contracts.manage'],
    manager: ['crm.view', 'sales.view', 'sales.manage', 'contracts.view'],
    accountant: ['crm.view', 'finance.view', 'finance.entry', 'finance.posting', 'finance.journals.manual'],
    cfo: ['crm.view', 'analytics.view', 'finance.view', 'finance.entry', 'finance.posting', 'finance.payments.approve', 'finance.period.close', 'finance.settings.manage', 'finance.reports.management']
  };
  const A_DEFAULT_VIS = {
    admin: 'all',
    director: 'all',
    lawyer: 'department',
    manager: 'own',
    accountant: 'department',
    cfo: 'all'
  };
  const A_M = (id, full_name, email, role) => ({
    id,
    full_name,
    email,
    role
  });
  const A_TREE0 = [{
    id: 1,
    name: 'Руководство',
    manager: 'Директор Петров П.П.',
    members: [A_M(6, 'Директор Петров П.П.', 'director@mgcrm.test', 'director'), A_M(2, 'Bogdan Yadykin', 'b.yadykin@macroglobaltech.com', 'admin')],
    children: [{
      id: 2,
      name: 'Отдел продаж',
      manager: 'Иванов Алексей Сергеевич',
      members: [A_M(7, 'Иванов Алексей Сергеевич', 'manager1@mgcrm.test', 'manager'), A_M(8, 'Илья Рогов', 'ilyarogov.mera@gmail.com', 'manager'), A_M(9, 'Клим Федорин', 'k.fedorin@macroglobaltech.com', 'manager'), A_M(10, 'Олеся Моисеева', 'o.moiseeva@macroglobaltech.com', 'manager'), A_M(5, 'Георгий Некрасов', 'g.nekrasov@macroglobaltech.com', 'manager'), A_M(11, 'Петрова Мария Сергеевна', 'manager2@mgcrm.test', 'manager')],
      children: [{
        id: 5,
        name: 'Группа B2B',
        manager: 'Петрова Мария Сергеевна',
        members: [A_M(7, 'Иванов Алексей Сергеевич', 'manager1@mgcrm.test', 'manager'), A_M(9, 'Клим Федорин', 'k.fedorin@macroglobaltech.com', 'manager'), A_M(5, 'Георгий Некрасов', 'g.nekrasov@macroglobaltech.com', 'manager')],
        children: []
      }]
    }, {
      id: 3,
      name: 'Финансы',
      manager: 'Сергей Казначеев',
      members: [A_M(13, 'Сергей Казначеев', 'cfo@mgcrm.test', 'cfo')],
      children: [{
        id: 6,
        name: 'Бухгалтерия',
        manager: 'Анна Счётова',
        members: [A_M(12, 'Анна Счётова', 'accountant@mgcrm.test', 'accountant')],
        children: []
      }]
    }, {
      id: 4,
      name: 'Юридический отдел',
      manager: 'Lawyer Test',
      members: [A_M(3, 'Lawyer Test', 'lawyer@mgcrm.test', 'lawyer')],
      children: []
    }]
  }];
  const A_ALL_USERS = [A_M(1, 'Admin', 'svkv42@gmail.com', 'admin'), A_M(2, 'Bogdan Yadykin', 'b.yadykin@macroglobaltech.com', 'admin'), A_M(3, 'Lawyer Test', 'lawyer@mgcrm.test', 'lawyer'), A_M(5, 'Георгий Некрасов', 'g.nekrasov@macroglobaltech.com', 'manager'), A_M(6, 'Директор Петров П.П.', 'director@mgcrm.test', 'director'), A_M(7, 'Иванов Алексей Сергеевич', 'manager1@mgcrm.test', 'manager'), A_M(8, 'Илья Рогов', 'ilyarogov.mera@gmail.com', 'manager'), A_M(9, 'Клим Федорин', 'k.fedorin@macroglobaltech.com', 'manager'), A_M(10, 'Олеся Моисеева', 'o.moiseeva@macroglobaltech.com', 'manager'), A_M(11, 'Петрова Мария Сергеевна', 'manager2@mgcrm.test', 'manager'), A_M(12, 'Анна Счётова', 'accountant@mgcrm.test', 'accountant'), A_M(13, 'Сергей Казначеев', 'cfo@mgcrm.test', 'cfo')];

  /* ── shared primitives ── */
  const A_SEV = {
    success: {
      bg: 'var(--mg-status-success-bg)',
      fg: 'var(--mg-status-success-text)'
    },
    danger: {
      bg: 'var(--mg-status-danger-bg)',
      fg: 'var(--mg-status-danger-text)'
    },
    warn: {
      bg: 'var(--mg-status-warning-bg)',
      fg: 'var(--mg-status-warning-text)'
    },
    info: {
      bg: 'var(--mg-status-info-bg)',
      fg: 'var(--mg-status-info-text)'
    },
    secondary: {
      bg: 'var(--c-muted2)',
      fg: 'var(--c-text2)'
    }
  };
  function A_btn(kind) {
    const base = {
      display: 'inline-flex',
      alignItems: 'center',
      gap: 7,
      height: 38,
      padding: '0 15px',
      borderRadius: 'var(--mg-radius-md)',
      fontFamily: 'var(--mg-font-sans)',
      fontSize: 13,
      fontWeight: 600,
      cursor: 'pointer',
      border: 'none',
      whiteSpace: 'nowrap'
    };
    if (kind === 'primary') return {
      ...base,
      background: 'var(--mg-primary-900)',
      color: '#fff'
    };
    if (kind === 'danger') return {
      ...base,
      background: 'var(--mg-status-danger-solid)',
      color: '#fff'
    };
    if (kind === 'text') return {
      ...base,
      background: 'transparent',
      color: 'var(--c-text2)'
    };
    return {
      ...base,
      background: 'transparent',
      color: 'var(--c-text2)',
      border: '1px solid var(--c-border2)'
    };
  }
  function A_sbtn(kind, active) {
    const b = A_btn(kind === 'p' ? 'primary' : 'ghost');
    return {
      ...b,
      height: 34,
      padding: '0 12px',
      fontSize: 12.5,
      ...(active ? {} : {})
    };
  }
  function A_Tag({
    value,
    severity
  }) {
    const c = A_SEV[severity] || A_SEV.secondary;
    return /*#__PURE__*/React.createElement("span", {
      style: {
        display: 'inline-flex',
        alignItems: 'center',
        fontSize: 11.5,
        fontWeight: 600,
        padding: '3px 9px',
        borderRadius: 6,
        background: c.bg,
        color: c.fg,
        whiteSpace: 'nowrap'
      }
    }, value);
  }
  function A_Msg({
    severity,
    children
  }) {
    const map = {
      warn: {
        bg: 'var(--mg-status-warning-bg)',
        bd: 'var(--mg-status-warning-border)',
        fg: 'var(--mg-status-warning-text)',
        ic: 'pi-exclamation-triangle'
      },
      secondary: {
        bg: 'var(--c-hover)',
        bd: 'var(--c-border)',
        fg: 'var(--c-text2)',
        ic: 'pi-info-circle'
      }
    };
    const c = map[severity] || map.secondary;
    return /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'flex-start',
        gap: 10,
        padding: '11px 14px',
        background: c.bg,
        border: '1px solid ' + c.bd,
        borderRadius: 'var(--mg-radius-md)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + c.ic,
      style: {
        fontSize: 14,
        color: c.fg,
        marginTop: 1,
        flexShrink: 0
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 13,
        color: c.fg,
        lineHeight: 1.5
      }
    }, children));
  }
  function A_Check({
    checked,
    disabled,
    onChange
  }) {
    return /*#__PURE__*/React.createElement("span", {
      onClick: () => !disabled && onChange(!checked),
      style: {
        display: 'inline-flex',
        width: 20,
        height: 20,
        borderRadius: 5,
        border: '1.5px solid ' + (checked ? 'var(--mg-primary-900)' : 'var(--c-border2)'),
        background: checked ? 'var(--mg-primary-900)' : 'var(--c-card)',
        alignItems: 'center',
        justifyContent: 'center',
        cursor: disabled ? 'not-allowed' : 'pointer',
        opacity: disabled ? 0.5 : 1
      }
    }, checked && /*#__PURE__*/React.createElement("i", {
      className: "pi pi-check",
      style: {
        fontSize: 11,
        color: '#fff',
        fontWeight: 700
      }
    }));
  }
  function A_Dropdown({
    value,
    onChange,
    options,
    placeholder,
    width,
    filter
  }) {
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const ref = useRef(null);
    useEffect(() => {
      const h = e => {
        if (ref.current && !ref.current.contains(e.target)) setOpen(false);
      };
      document.addEventListener('mousedown', h);
      return () => document.removeEventListener('mousedown', h);
    }, []);
    const sel = options.find(o => o.value === value);
    const list = filter && q ? options.filter(o => o.label.toLowerCase().includes(q.toLowerCase())) : options;
    return /*#__PURE__*/React.createElement("div", {
      ref: ref,
      style: {
        position: 'relative',
        width: width || '100%'
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: () => setOpen(!open),
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        height: 40,
        padding: '0 12px',
        border: '1px solid ' + (open ? 'var(--mg-input-focus-border)' : 'var(--mg-input-border)'),
        borderRadius: 'var(--mg-radius-md)',
        background: 'var(--c-card)',
        cursor: 'pointer',
        boxShadow: open ? 'var(--mg-focus-ring)' : 'none'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 14,
        color: sel ? 'var(--c-text)' : 'var(--c-muted)',
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, sel ? sel.label : placeholder), /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + (open ? 'pi-chevron-up' : 'pi-chevron-down'),
      style: {
        fontSize: 12,
        color: 'var(--c-muted)'
      }
    })), open && /*#__PURE__*/React.createElement("div", {
      style: {
        position: 'absolute',
        top: 44,
        insetInlineStart: 0,
        zIndex: 40,
        width: '100%',
        minWidth: 180,
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-md)',
        boxShadow: 'var(--mg-shadow-lg)',
        padding: 5,
        maxHeight: 260,
        overflowY: 'auto'
      }
    }, filter && /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 7,
        height: 34,
        padding: '0 10px',
        margin: '2px 2px 6px',
        border: '1px solid var(--c-border2)',
        borderRadius: 'var(--mg-radius-sm)',
        background: 'var(--c-page)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-search",
      style: {
        fontSize: 12,
        color: 'var(--c-muted)'
      }
    }), /*#__PURE__*/React.createElement("input", {
      autoFocus: true,
      value: q,
      onChange: e => setQ(e.target.value),
      placeholder: "\u041F\u043E\u0438\u0441\u043A\u2026",
      style: {
        flex: 1,
        border: 'none',
        outline: 'none',
        background: 'transparent',
        fontFamily: 'var(--mg-font-sans)',
        fontSize: 13,
        color: 'var(--c-text)'
      }
    })), list.map(o => {
      const on = o.value === value;
      return /*#__PURE__*/React.createElement("div", {
        key: String(o.value),
        onClick: () => {
          onChange(o.value);
          setOpen(false);
          setQ('');
        },
        style: {
          display: 'flex',
          alignItems: 'center',
          gap: 8,
          padding: '8px 10px',
          borderRadius: 7,
          cursor: 'pointer',
          fontSize: 13.5,
          fontWeight: on ? 600 : 500,
          color: on ? 'var(--mg-primary-900)' : 'var(--c-text)',
          background: on ? 'var(--mg-primary-100)' : 'transparent'
        }
      }, o.label, on && /*#__PURE__*/React.createElement("i", {
        className: "pi pi-check",
        style: {
          marginInlineStart: 'auto',
          fontSize: 12
        }
      }));
    })));
  }
  function A_avatar(name, role, size) {
    const grad = {
      admin: 'linear-gradient(135deg,#4C7DF0,#2B4987)',
      director: 'linear-gradient(135deg,#E6A46E,#C67E3C)',
      cfo: 'linear-gradient(135deg,#E6A46E,#C67E3C)',
      lawyer: 'linear-gradient(135deg,#78B0E0,#4A82BC)',
      manager: 'linear-gradient(135deg,#4FBF8F,#2E9469)',
      accountant: 'linear-gradient(135deg,#9AA3B2,#6C7688)'
    };
    const w = name.trim().split(/\s+/);
    const ini = (w.length >= 2 ? w[0][0] + w[1][0] : w[0].slice(0, 2)).toUpperCase();
    return /*#__PURE__*/React.createElement("span", {
      style: {
        width: size,
        height: size,
        borderRadius: '50%',
        flexShrink: 0,
        background: grad[role] || grad.accountant,
        color: '#fff',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        fontSize: size * 0.36,
        fontWeight: 600
      }
    }, ini);
  }

  /* ── tree helpers ── */
  function A_walkUpdate(nodes, id, fn) {
    return nodes.map(n => n.id === id ? fn(n) : {
      ...n,
      children: A_walkUpdate(n.children, id, fn)
    });
  }
  function A_flatten(nodes, depth, acc) {
    acc = acc || [];
    nodes.forEach(n => {
      acc.push({
        id: n.id,
        name: n.name,
        depth: depth || 0
      });
      A_flatten(n.children, (depth || 0) + 1, acc);
    });
    return acc;
  }
  function A_removeNode(nodes, id) {
    const kept = [];
    let orphans = [];
    nodes.forEach(n => {
      if (n.id === id) {
        orphans = orphans.concat(n.children);
      } else {
        const r = A_removeNode(n.children, id);
        n = {
          ...n,
          children: r.nodes
        };
        orphans = orphans.concat(r.orphans);
        kept.push(n);
      }
    });
    return {
      nodes: kept,
      orphans
    };
  }
  function A_countAll(n) {
    return 1;
  }

  /* ── Department tree (redesigned) ── */
  function A_TreeNode({
    node,
    depth,
    selectedId,
    onSelect,
    onEdit,
    onDelete,
    expanded,
    toggle,
    editing
  }) {
    const isOpen = expanded[node.id] !== false;
    const on = selectedId === node.id;
    return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("div", {
      className: "atnode",
      onClick: () => onSelect(node),
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        padding: '9px 12px',
        paddingInlineStart: 12 + depth * 22,
        borderRadius: 'var(--mg-radius-md)',
        cursor: 'pointer',
        background: on ? 'var(--mg-primary-100)' : 'transparent',
        transition: 'background .12s'
      }
    }, node.children.length > 0 ? /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + (isOpen ? 'pi-chevron-down' : 'pi-chevron-right'),
      onClick: e => {
        e.stopPropagation();
        toggle(node.id);
      },
      style: {
        fontSize: 11,
        color: 'var(--c-muted)',
        width: 14,
        cursor: 'pointer'
      }
    }) : /*#__PURE__*/React.createElement("span", {
      style: {
        width: 14
      }
    }), /*#__PURE__*/React.createElement("i", {
      className: "pi pi-building",
      style: {
        fontSize: 14,
        color: on ? 'var(--mg-primary-900)' : 'var(--c-muted)'
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 13.5,
        fontWeight: on ? 600 : 500,
        color: on ? 'var(--mg-primary-900)' : 'var(--c-text)',
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, node.name), /*#__PURE__*/React.createElement("span", {
      style: {
        display: 'inline-flex',
        alignItems: 'center',
        gap: 4,
        fontSize: 12,
        color: 'var(--c-muted)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-users",
      style: {
        fontSize: 11
      }
    }), node.members.length), editing && /*#__PURE__*/React.createElement("span", {
      className: "atacts",
      style: {
        display: 'inline-flex',
        gap: 2,
        opacity: 0,
        transition: 'opacity .12s'
      }
    }, /*#__PURE__*/React.createElement("button", {
      className: "uicon",
      onClick: e => {
        e.stopPropagation();
        onEdit(node);
      },
      title: "\u0420\u0435\u0434\u0430\u043A\u0442\u0438\u0440\u043E\u0432\u0430\u0442\u044C",
      style: {
        width: 28,
        height: 28,
        borderRadius: 7,
        border: 'none',
        background: 'transparent',
        cursor: 'pointer'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-pencil",
      style: {
        fontSize: 12,
        color: 'var(--c-text2)'
      }
    })), /*#__PURE__*/React.createElement("button", {
      className: "uicon",
      onClick: e => {
        e.stopPropagation();
        onDelete(node);
      },
      title: "\u0423\u0434\u0430\u043B\u0438\u0442\u044C",
      style: {
        width: 28,
        height: 28,
        borderRadius: 7,
        border: 'none',
        background: 'transparent',
        cursor: 'pointer'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-trash",
      style: {
        fontSize: 12,
        color: 'var(--mg-status-danger-text)'
      }
    })))), isOpen && node.children.map(c => /*#__PURE__*/React.createElement(A_TreeNode, {
      key: c.id,
      node: c,
      depth: depth + 1,
      selectedId: selectedId,
      onSelect: onSelect,
      onEdit: onEdit,
      onDelete: onDelete,
      expanded: expanded,
      toggle: toggle,
      editing: editing
    })));
  }

  /* ── Org chart (Схема) ── */
  function A_ChartNode({
    node
  }) {
    const [open, setOpen] = useState(false);
    return /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center'
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: () => setOpen(o => !o),
      style: {
        width: 210,
        background: 'var(--c-card)',
        border: '1px solid ' + (open ? 'var(--mg-primary-900)' : 'var(--c-border2)'),
        borderRadius: 'var(--mg-radius-md)',
        boxShadow: 'var(--mg-shadow-sm)',
        padding: '11px 14px',
        cursor: 'pointer',
        textAlign: 'center'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 13.5,
        fontWeight: 600,
        color: 'var(--c-text)'
      }
    }, node.name), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 12,
        color: 'var(--c-muted)',
        marginTop: 3
      }
    }, node.manager), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'inline-flex',
        alignItems: 'center',
        gap: 5,
        marginTop: 7,
        fontSize: 11.5,
        fontWeight: 600,
        padding: '2px 9px',
        borderRadius: 999,
        background: 'var(--mg-primary-100)',
        color: 'var(--mg-primary-900)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-users",
      style: {
        fontSize: 10
      }
    }), node.members.length, /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + (open ? 'pi-chevron-up' : 'pi-chevron-down'),
      style: {
        fontSize: 9,
        marginInlineStart: 1
      }
    })), open && /*#__PURE__*/React.createElement("div", {
      style: {
        marginTop: 11,
        paddingTop: 11,
        borderTop: '1px solid var(--c-border)',
        display: 'flex',
        flexDirection: 'column',
        gap: 8,
        textAlign: 'start'
      }
    }, node.members.length ? node.members.map(m => /*#__PURE__*/React.createElement("div", {
      key: m.id,
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8
      }
    }, A_avatar(m.full_name, m.role, 24), /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        minWidth: 0,
        fontSize: 12,
        fontWeight: 500,
        color: 'var(--c-text)',
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, m.full_name))) : /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 12,
        color: 'var(--c-muted)'
      }
    }, A_T.dep.noMembers))), node.children.length > 0 && /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("div", {
      style: {
        width: 1,
        height: 20,
        background: 'var(--c-border2)'
      }
    }), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 26,
        position: 'relative',
        paddingTop: 20,
        borderTop: node.children.length > 1 ? '1px solid var(--c-border2)' : 'none',
        alignItems: 'flex-start'
      }
    }, node.children.map(c => /*#__PURE__*/React.createElement("div", {
      key: c.id,
      style: {
        position: 'relative'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        position: 'absolute',
        top: -20,
        insetInlineStart: '50%',
        width: 1,
        height: 20,
        background: 'var(--c-border2)'
      }
    }), /*#__PURE__*/React.createElement(A_ChartNode, {
      node: c
    }))))));
  }

  /* ── Department side panel (drawer) ── */
  function A_SidePanel({
    mode,
    form,
    setForm,
    parentOptions,
    userOptions,
    onClose,
    onSave,
    onOpenPicker,
    onRemoveMember
  }) {
    const set = (k, v) => setForm(s => ({
      ...s,
      [k]: v
    }));
    return /*#__PURE__*/React.createElement("div", {
      onClick: onClose,
      style: {
        position: 'fixed',
        inset: 0,
        zIndex: 1300,
        background: 'rgba(9,16,32,0.45)',
        display: 'flex',
        justifyContent: 'flex-end'
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: e => e.stopPropagation(),
      style: {
        width: 440,
        maxWidth: '92vw',
        height: '100%',
        background: 'var(--c-card)',
        borderInlineStart: '1px solid var(--c-border)',
        boxShadow: 'var(--mg-shadow-lg)',
        display: 'flex',
        flexDirection: 'column'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '16px 20px',
        borderBottom: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 16,
        fontWeight: 600
      }
    }, mode === 'create' ? A_T.dep.add : A_T.dep.edit), /*#__PURE__*/React.createElement("i", {
      className: "pi pi-times",
      onClick: onClose,
      style: {
        fontSize: 14,
        color: 'var(--c-muted)',
        cursor: 'pointer'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        flex: 1,
        overflowY: 'auto',
        padding: 20,
        display: 'flex',
        flexDirection: 'column',
        gap: 16
      }
    }, /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("label", {
      style: A_lbl
    }, A_T.dep.name), /*#__PURE__*/React.createElement("input", {
      className: "inp",
      autoFocus: true,
      value: form.name,
      onChange: e => set('name', e.target.value),
      placeholder: A_T.dep.name,
      style: A_inp
    })), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("label", {
      style: A_lbl
    }, A_T.dep.parent), /*#__PURE__*/React.createElement(A_Dropdown, {
      value: form.parentId,
      onChange: v => set('parentId', v),
      options: [{
        value: null,
        label: A_T.dep.root
      }].concat(parentOptions),
      placeholder: A_T.dep.root
    })), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("label", {
      style: A_lbl
    }, A_T.dep.manager), /*#__PURE__*/React.createElement(A_Dropdown, {
      value: form.managerId,
      onChange: v => set('managerId', v),
      options: userOptions,
      placeholder: "\u2014",
      filter: true
    })), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        marginBottom: 8
      }
    }, /*#__PURE__*/React.createElement("label", {
      style: {
        ...A_lbl,
        margin: 0,
        flex: 1
      }
    }, A_T.dep.members), /*#__PURE__*/React.createElement("button", {
      style: {
        ...A_btn('text'),
        height: 30,
        padding: '0 8px',
        fontSize: 12.5,
        color: 'var(--mg-primary-900)'
      },
      onClick: onOpenPicker
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-plus",
      style: {
        fontSize: 11
      }
    }), A_T.dep.addMember)), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        gap: 6
      }
    }, form.members.length === 0 && /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 13,
        color: 'var(--c-muted)',
        padding: '10px 0'
      }
    }, A_T.dep.noMembers), form.members.map(m => /*#__PURE__*/React.createElement("div", {
      key: m.id,
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '7px 10px',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-md)'
      }
    }, A_avatar(m.full_name, m.role, 28), /*#__PURE__*/React.createElement("div", {
      style: {
        flex: 1,
        minWidth: 0
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 13,
        fontWeight: 500,
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, m.full_name)), /*#__PURE__*/React.createElement("i", {
      className: "pi pi-times",
      onClick: () => onRemoveMember(m.id),
      title: A_T.dep.removeMember,
      style: {
        fontSize: 12,
        color: 'var(--c-muted)',
        cursor: 'pointer'
      }
    })))))), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        justifyContent: 'flex-end',
        gap: 10,
        padding: '14px 20px',
        borderTop: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("button", {
      style: A_btn('ghost'),
      onClick: onClose
    }, "\u041E\u0442\u043C\u0435\u043D\u0430"), /*#__PURE__*/React.createElement("button", {
      style: A_btn('primary'),
      onClick: onSave
    }, "\u0421\u043E\u0445\u0440\u0430\u043D\u0438\u0442\u044C"))));
  }
  const A_lbl = {
    display: 'block',
    fontSize: 13,
    fontWeight: 500,
    color: 'var(--c-text)',
    marginBottom: 6
  };
  const A_inp = {
    width: '100%',
    height: 40,
    padding: '0 13px',
    borderRadius: 'var(--mg-radius-md)',
    border: '1px solid var(--mg-input-border)',
    background: 'var(--c-card)',
    color: 'var(--c-text)',
    fontFamily: 'var(--mg-font-sans)',
    fontSize: 14,
    boxSizing: 'border-box'
  };

  /* ── member picker dialog ── */
  function A_MemberPicker({
    available,
    close,
    onAdd
  }) {
    const [sel, setSel] = useState([]);
    const [q, setQ] = useState('');
    const list = available.filter(u => u.full_name.toLowerCase().includes(q.toLowerCase()));
    const toggle = id => setSel(s => s.includes(id) ? s.filter(x => x !== id) : [...s, id]);
    return /*#__PURE__*/React.createElement("div", {
      onClick: close,
      style: {
        position: 'fixed',
        inset: 0,
        zIndex: 1400,
        background: 'rgba(9,16,32,0.55)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: 20
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: e => e.stopPropagation(),
      style: {
        width: 440,
        maxWidth: '92vw',
        maxHeight: 'calc(100vh - 40px)',
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-lg)',
        display: 'flex',
        flexDirection: 'column'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '16px 20px',
        borderBottom: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 16,
        fontWeight: 600
      }
    }, A_T.dep.addMember), /*#__PURE__*/React.createElement("i", {
      className: "pi pi-times",
      onClick: close,
      style: {
        fontSize: 14,
        color: 'var(--c-muted)',
        cursor: 'pointer'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        padding: '14px 20px'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        height: 40,
        padding: '0 12px',
        border: '1px solid var(--mg-input-border)',
        borderRadius: 'var(--mg-radius-md)',
        background: 'var(--c-page)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-search",
      style: {
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }), /*#__PURE__*/React.createElement("input", {
      autoFocus: true,
      value: q,
      onChange: e => setQ(e.target.value),
      placeholder: "\u041F\u043E\u0438\u0441\u043A\u2026",
      style: {
        flex: 1,
        border: 'none',
        outline: 'none',
        background: 'transparent',
        fontFamily: 'var(--mg-font-sans)',
        fontSize: 14,
        color: 'var(--c-text)'
      }
    }))), /*#__PURE__*/React.createElement("div", {
      style: {
        flex: 1,
        overflowY: 'auto',
        padding: '0 12px 8px'
      }
    }, list.map(u => {
      const on = sel.includes(u.id);
      return /*#__PURE__*/React.createElement("div", {
        key: u.id,
        className: "urow",
        onClick: () => toggle(u.id),
        style: {
          display: 'flex',
          alignItems: 'center',
          gap: 11,
          padding: '8px 10px',
          borderRadius: 8,
          cursor: 'pointer'
        }
      }, /*#__PURE__*/React.createElement(A_Check, {
        checked: on,
        onChange: () => toggle(u.id)
      }), A_avatar(u.full_name, u.role, 30), /*#__PURE__*/React.createElement("div", {
        style: {
          flex: 1,
          minWidth: 0
        }
      }, /*#__PURE__*/React.createElement("div", {
        style: {
          fontSize: 13.5,
          fontWeight: 500
        }
      }, u.full_name), /*#__PURE__*/React.createElement("div", {
        style: {
          fontSize: 12,
          color: 'var(--c-muted)'
        }
      }, u.email)));
    }), list.length === 0 && /*#__PURE__*/React.createElement("div", {
      style: {
        padding: '20px 10px',
        fontSize: 13,
        color: 'var(--c-muted)',
        textAlign: 'center'
      }
    }, "\u041D\u0438\u0447\u0435\u0433\u043E \u043D\u0435 \u043D\u0430\u0439\u0434\u0435\u043D\u043E")), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        justifyContent: 'flex-end',
        gap: 10,
        padding: '14px 20px',
        borderTop: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("button", {
      style: A_btn('ghost'),
      onClick: close
    }, "\u041E\u0442\u043C\u0435\u043D\u0430"), /*#__PURE__*/React.createElement("button", {
      style: {
        ...A_btn('primary'),
        opacity: sel.length ? 1 : 0.5,
        pointerEvents: sel.length ? 'auto' : 'none'
      },
      onClick: () => onAdd(sel)
    }, "\u0414\u043E\u0431\u0430\u0432\u0438\u0442\u044C"))));
  }

  /* ── confirm delete ── */
  function A_Confirm({
    message,
    onAccept,
    close
  }) {
    return /*#__PURE__*/React.createElement("div", {
      onClick: close,
      style: {
        position: 'fixed',
        inset: 0,
        zIndex: 1500,
        background: 'rgba(9,16,32,0.55)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: 20
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: e => e.stopPropagation(),
      style: {
        width: 430,
        maxWidth: '92vw',
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-lg)'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '16px 20px',
        borderBottom: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 16,
        fontWeight: 600
      }
    }, A_T.dep.del), /*#__PURE__*/React.createElement("i", {
      className: "pi pi-times",
      onClick: close,
      style: {
        fontSize: 14,
        color: 'var(--c-muted)',
        cursor: 'pointer'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        padding: 20,
        display: 'flex',
        gap: 14,
        alignItems: 'flex-start'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        width: 40,
        height: 40,
        borderRadius: '50%',
        background: 'var(--mg-status-danger-bg)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        flexShrink: 0
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-exclamation-triangle",
      style: {
        fontSize: 17,
        color: 'var(--mg-status-danger-text)'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 14,
        color: 'var(--c-text2)',
        lineHeight: 1.55,
        paddingTop: 3
      }
    }, message)), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        justifyContent: 'flex-end',
        gap: 10,
        padding: '14px 20px',
        borderTop: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("button", {
      style: A_btn('ghost'),
      onClick: close
    }, "\u041E\u0442\u043C\u0435\u043D\u0430"), /*#__PURE__*/React.createElement("button", {
      style: A_btn('danger'),
      onClick: onAccept
    }, "\u0423\u0434\u0430\u043B\u0438\u0442\u044C"))));
  }
  function A_Toasts({
    items
  }) {
    return /*#__PURE__*/React.createElement("div", {
      style: {
        position: 'fixed',
        insetBlockEnd: 20,
        insetInlineEnd: 20,
        zIndex: 2000,
        display: 'flex',
        flexDirection: 'column',
        gap: 10
      }
    }, items.map(t => /*#__PURE__*/React.createElement("div", {
      key: t.id,
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        minWidth: 240,
        maxWidth: 360,
        padding: '12px 14px',
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderInlineStart: '3px solid var(--mg-status-success-text)',
        borderRadius: 'var(--mg-radius-md)',
        boxShadow: 'var(--mg-shadow-lg)',
        animation: 'toastIn .2s ease'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-check-circle",
      style: {
        fontSize: 16,
        color: 'var(--mg-status-success-text)'
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 13.5,
        fontWeight: 500
      }
    }, t.summary))));
  }

  /* ═══════════════ TABS ═══════════════ */
  function A_DepartmentsTab({
    pushToast
  }) {
    const [tree, setTree] = useState(A_TREE0);
    const [search, setSearch] = useState('');
    const [viewMode, setViewMode] = useState('tree');
    const [selectedId, setSelectedId] = useState(2);
    const [expanded, setExpanded] = useState({});
    const [panel, setPanel] = useState(null);
    const [form, setForm] = useState({
      name: '',
      parentId: null,
      managerId: null,
      members: []
    });
    const [picker, setPicker] = useState(false);
    const [confirm, setConfirm] = useState(null);
    const [editing, setEditing] = useState(false);
    const toggle = id => setExpanded(e => ({
      ...e,
      [id]: e[id] === false ? true : false
    }));
    const findNode = (nodes, id) => {
      for (const n of nodes) {
        if (n.id === id) return n;
        const f = findNode(n.children, id);
        if (f) return f;
      }
      return null;
    };
    const selected = findNode(tree, selectedId);
    const parentOptions = A_flatten(tree).map(d => ({
      value: d.id,
      label: '— '.repeat(d.depth) + d.name
    }));
    const userOptions = A_ALL_USERS.map(u => ({
      value: u.id,
      label: u.full_name
    }));
    function openCreate() {
      setForm({
        name: '',
        parentId: selectedId || null,
        managerId: null,
        members: []
      });
      setPanel('create');
    }
    function openEdit(n) {
      setForm({
        id: n.id,
        name: n.name,
        parentId: null,
        managerId: (A_ALL_USERS.find(u => u.full_name === n.manager) || {}).id ?? null,
        members: n.members.slice()
      });
      setPanel('edit');
    }
    function savePanel() {
      if (!form.name.trim()) return;
      const mgr = (A_ALL_USERS.find(u => u.id === form.managerId) || {}).full_name || '—';
      if (panel === 'edit') {
        setTree(t => A_walkUpdate(t, form.id, n => ({
          ...n,
          name: form.name.trim(),
          manager: mgr,
          members: form.members
        })));
      } else {
        const id = Date.now();
        const node = {
          id,
          name: form.name.trim(),
          manager: mgr,
          members: form.members,
          children: []
        };
        setTree(t => form.parentId ? A_walkUpdate(t, form.parentId, n => ({
          ...n,
          children: [...n.children, node]
        })) : [...t, node]);
        if (form.parentId) setExpanded(e => ({
          ...e,
          [form.parentId]: false
        }));
      }
      pushToast(A_T.dep.saved);
      setPanel(null);
    }
    function doDelete(n) {
      setTree(t => A_removeNode(t, n.id).nodes);
      if (selectedId === n.id) setSelectedId(null);
      pushToast(A_T.dep.deleted);
      setConfirm(null);
    }
    function addMembers(ids) {
      const add = A_ALL_USERS.filter(u => ids.includes(u.id) && !form.members.some(m => m.id === u.id));
      setForm(f => ({
        ...f,
        members: [...f.members, ...add]
      }));
      setPicker(false);
    }
    function removeMember(id) {
      setForm(f => ({
        ...f,
        members: f.members.filter(m => m.id !== id)
      }));
    }
    const q = search.trim().toLowerCase();
    const filterTree = nodes => nodes.map(n => ({
      ...n,
      children: filterTree(n.children)
    })).filter(n => !q || n.name.toLowerCase().includes(q) || n.children.length);
    const shown = filterTree(tree);
    return /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        gap: 16
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        flexWrap: 'wrap'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        height: 40,
        padding: '0 12px',
        flex: 1,
        minWidth: 180,
        maxWidth: 280,
        border: '1px solid var(--mg-input-border)',
        borderRadius: 'var(--mg-radius-md)',
        background: 'var(--c-card)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-search",
      style: {
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }), /*#__PURE__*/React.createElement("input", {
      value: search,
      onChange: e => setSearch(e.target.value),
      placeholder: "\u041F\u043E\u0438\u0441\u043A\u2026",
      style: {
        flex: 1,
        border: 'none',
        outline: 'none',
        background: 'transparent',
        fontFamily: 'var(--mg-font-sans)',
        fontSize: 14,
        color: 'var(--c-text)'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'inline-flex',
        padding: 3,
        gap: 2,
        background: 'var(--c-muted2)',
        borderRadius: 'var(--mg-radius-md)'
      }
    }, [['tree', A_T.dep.viewTree, 'pi-sitemap'], ['chart', A_T.dep.viewChart, 'pi-share-alt']].map(([k, l, ic]) => /*#__PURE__*/React.createElement("button", {
      key: k,
      onClick: () => setViewMode(k),
      style: {
        display: 'inline-flex',
        alignItems: 'center',
        gap: 6,
        height: 32,
        padding: '0 12px',
        border: 'none',
        borderRadius: 'var(--mg-radius-sm)',
        cursor: 'pointer',
        fontFamily: 'var(--mg-font-sans)',
        fontSize: 12.5,
        fontWeight: 600,
        background: viewMode === k ? 'var(--c-card)' : 'transparent',
        color: viewMode === k ? 'var(--mg-primary-900)' : 'var(--c-text2)',
        boxShadow: viewMode === k ? 'var(--mg-shadow-sm)' : 'none'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + ic,
      style: {
        fontSize: 12
      }
    }), l))), /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1
      }
    }), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 10
      }
    }, /*#__PURE__*/React.createElement("button", {
      style: editing ? {
        ...A_btn('ghost'),
        color: 'var(--mg-primary-900)',
        borderColor: 'var(--mg-primary-900)'
      } : A_btn('ghost'),
      onClick: () => setEditing(e => !e)
    }, /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + (editing ? 'pi-check' : 'pi-pencil'),
      style: {
        fontSize: 12
      }
    }), editing ? 'Завершить редактирование' : 'Редактировать'), /*#__PURE__*/React.createElement("button", {
      style: A_btn('primary'),
      onClick: openCreate
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-plus",
      style: {
        fontSize: 12
      }
    }), A_T.dep.add))), viewMode === 'tree' ? /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'grid',
        gridTemplateColumns: '1fr 340px',
        gap: 16,
        alignItems: 'start'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-sm)',
        padding: 8
      }
    }, shown.length ? shown.map(n => /*#__PURE__*/React.createElement(A_TreeNode, {
      key: n.id,
      node: n,
      depth: 0,
      selectedId: selectedId,
      onSelect: d => setSelectedId(d.id),
      onEdit: openEdit,
      onDelete: d => setConfirm(d),
      expanded: expanded,
      toggle: toggle,
      editing: editing
    })) : /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        gap: 10,
        padding: '48px 20px',
        color: 'var(--c-muted)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-building",
      style: {
        fontSize: 28,
        opacity: 0.3
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 14,
        color: 'var(--c-text2)',
        fontWeight: 500
      }
    }, A_T.dep.empty), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 13,
        textAlign: 'center',
        maxWidth: 240
      }
    }, A_T.dep.emptyHint), /*#__PURE__*/React.createElement("button", {
      style: {
        ...A_btn('ghost'),
        marginTop: 4
      },
      onClick: openCreate
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-plus",
      style: {
        fontSize: 12
      }
    }), A_T.dep.add))), selected ? /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-sm)',
        overflow: 'hidden'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '14px 16px',
        borderBottom: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        flex: 1,
        minWidth: 0
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 15,
        fontWeight: 600,
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, selected.name), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 12,
        color: 'var(--c-muted)',
        marginTop: 2
      }
    }, "\u0420\u0443\u043A\u043E\u0432\u043E\u0434\u0438\u0442\u0435\u043B\u044C: ", selected.manager)), editing && /*#__PURE__*/React.createElement("button", {
      className: "uicon",
      onClick: () => openEdit(selected),
      title: "\u0420\u0435\u0434\u0430\u043A\u0442\u0438\u0440\u043E\u0432\u0430\u0442\u044C",
      style: {
        width: 30,
        height: 30,
        borderRadius: 7,
        border: 'none',
        background: 'transparent',
        cursor: 'pointer'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-pencil",
      style: {
        fontSize: 13,
        color: 'var(--c-text2)'
      }
    }))), /*#__PURE__*/React.createElement("div", {
      style: {
        padding: 4
      }
    }, selected.members.length ? selected.members.map(m => /*#__PURE__*/React.createElement("div", {
      key: m.id,
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '8px 12px'
      }
    }, A_avatar(m.full_name, m.role, 30), /*#__PURE__*/React.createElement("div", {
      style: {
        flex: 1,
        minWidth: 0
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 13,
        fontWeight: 500,
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, m.full_name), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 11.5,
        color: 'var(--c-muted)',
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, m.email)), /*#__PURE__*/React.createElement(A_Tag, {
      value: A_ROLE_LABEL[m.role],
      severity: A_ROLE_SEV[m.role]
    }))) : /*#__PURE__*/React.createElement("div", {
      style: {
        padding: '28px 12px',
        fontSize: 13,
        color: 'var(--c-muted)',
        textAlign: 'center'
      }
    }, A_T.dep.noMembers))) : /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px dashed var(--c-border2)',
        borderRadius: 'var(--mg-radius-lg)',
        padding: '40px 20px',
        textAlign: 'center',
        color: 'var(--c-muted)',
        fontSize: 13
      }
    }, "\u0412\u044B\u0431\u0435\u0440\u0438\u0442\u0435 \u043E\u0442\u0434\u0435\u043B, \u0447\u0442\u043E\u0431\u044B \u0443\u0432\u0438\u0434\u0435\u0442\u044C \u0441\u043E\u0442\u0440\u0443\u0434\u043D\u0438\u043A\u043E\u0432")) : /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-page)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        padding: '32px 20px',
        overflowX: 'auto'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 40,
        justifyContent: 'center',
        minWidth: 'min-content',
        alignItems: 'flex-start'
      }
    }, shown.map(n => /*#__PURE__*/React.createElement(A_ChartNode, {
      key: n.id,
      node: n
    })))), panel && /*#__PURE__*/React.createElement(A_SidePanel, {
      mode: panel,
      form: form,
      setForm: setForm,
      parentOptions: parentOptions.filter(o => o.value !== form.id),
      userOptions: userOptions,
      onClose: () => setPanel(null),
      onSave: savePanel,
      onOpenPicker: () => setPicker(true),
      onRemoveMember: removeMember
    }), picker && /*#__PURE__*/React.createElement(A_MemberPicker, {
      available: A_ALL_USERS.filter(u => !form.members.some(m => m.id === u.id)),
      close: () => setPicker(false),
      onAdd: addMembers
    }), confirm && /*#__PURE__*/React.createElement(A_Confirm, {
      message: A_T.dep.delConfirm(confirm.name),
      onAccept: () => doDelete(confirm),
      close: () => setConfirm(null)
    }));
  }
  function A_RolesTab({
    pushToast
  }) {
    const [perms, setPerms] = useState(() => {
      const m = {};
      A_ROLES.forEach(r => {
        m[r] = new Set(A_DEFAULT_PERMS[r]);
      });
      return m;
    });
    const [collapsed, setCollapsed] = useState({});
    const [dirty, setDirty] = useState(false);
    const has = (r, p) => perms[r].has(p);
    function toggle(p, r, v) {
      if (r === 'admin') return;
      setPerms(m => {
        const s = new Set(m[r]);
        v ? s.add(p) : s.delete(p);
        return {
          ...m,
          [r]: s
        };
      });
      setDirty(true);
    }
    function reset() {
      const m = {};
      A_ROLES.forEach(r => {
        m[r] = new Set(A_DEFAULT_PERMS[r]);
      });
      setPerms(m);
      setDirty(false);
    }
    function save() {
      pushToast(A_T.roles.saved);
      setDirty(false);
    }
    const th = {
      padding: '9px 12px',
      fontSize: 11,
      fontWeight: 600,
      letterSpacing: '.02em',
      textTransform: 'uppercase',
      color: 'var(--c-muted)',
      borderBottom: '1px solid var(--c-border)'
    };
    return /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        gap: 16
      }
    }, /*#__PURE__*/React.createElement(A_Msg, {
      severity: "secondary"
    }, A_T.roles.adminNote), A_GROUPS.map(g => {
      const open = !collapsed[g.key];
      return /*#__PURE__*/React.createElement("div", {
        key: g.key,
        style: {
          background: 'var(--c-card)',
          border: '1px solid var(--c-border)',
          borderRadius: 'var(--mg-radius-lg)',
          boxShadow: 'var(--mg-shadow-sm)',
          overflow: 'hidden'
        }
      }, /*#__PURE__*/React.createElement("div", {
        onClick: () => setCollapsed(c => ({
          ...c,
          [g.key]: !c[g.key]
        })),
        style: {
          display: 'flex',
          alignItems: 'center',
          gap: 10,
          padding: '12px 16px',
          cursor: 'pointer',
          background: 'var(--c-hover)',
          borderBottom: open ? '1px solid var(--c-border)' : 'none'
        }
      }, /*#__PURE__*/React.createElement("i", {
        className: 'pi ' + (open ? 'pi-chevron-down' : 'pi-chevron-right'),
        style: {
          fontSize: 12,
          color: 'var(--c-muted)'
        }
      }), /*#__PURE__*/React.createElement("span", {
        style: {
          flex: 1,
          fontSize: 14,
          fontWeight: 600
        }
      }, g.label), /*#__PURE__*/React.createElement("span", {
        style: {
          fontSize: 11.5,
          fontWeight: 600,
          padding: '2px 8px',
          borderRadius: 999,
          background: 'var(--c-muted2)',
          color: 'var(--c-text2)'
        }
      }, g.perms.length)), open && /*#__PURE__*/React.createElement("div", {
        style: {
          overflowX: 'auto'
        }
      }, /*#__PURE__*/React.createElement("table", {
        style: {
          width: '100%',
          borderCollapse: 'collapse',
          minWidth: 760
        }
      }, /*#__PURE__*/React.createElement("thead", null, /*#__PURE__*/React.createElement("tr", null, /*#__PURE__*/React.createElement("th", {
        style: {
          ...th,
          textAlign: 'start',
          minWidth: 260
        }
      }, A_T.roles.permissionLabel), A_ROLES.map(r => /*#__PURE__*/React.createElement("th", {
        key: r,
        style: {
          ...th,
          textAlign: 'center',
          width: 92
        }
      }, A_ROLE_SHORT[r])))), /*#__PURE__*/React.createElement("tbody", null, g.perms.map(p => /*#__PURE__*/React.createElement("tr", {
        key: p,
        className: "urow"
      }, /*#__PURE__*/React.createElement("td", {
        style: {
          padding: '10px 12px',
          borderBottom: '1px solid var(--c-border)'
        }
      }, /*#__PURE__*/React.createElement("div", {
        style: {
          display: 'flex',
          flexDirection: 'column',
          gap: 3
        }
      }, /*#__PURE__*/React.createElement("span", {
        style: {
          fontSize: 13.5,
          color: 'var(--c-text)'
        }
      }, A_PERM_LABEL[p] || p), /*#__PURE__*/React.createElement("code", {
        style: {
          fontFamily: 'ui-monospace,monospace',
          fontSize: 11,
          color: 'var(--c-muted)',
          background: 'var(--c-muted2)',
          padding: '1px 6px',
          borderRadius: 4,
          alignSelf: 'flex-start'
        }
      }, p))), A_ROLES.map(r => /*#__PURE__*/React.createElement("td", {
        key: r,
        style: {
          padding: '10px 12px',
          borderBottom: '1px solid var(--c-border)',
          textAlign: 'center'
        }
      }, /*#__PURE__*/React.createElement("div", {
        style: {
          display: 'flex',
          justifyContent: 'center'
        }
      }, /*#__PURE__*/React.createElement(A_Check, {
        checked: r === 'admin' ? true : has(r, p),
        disabled: r === 'admin',
        onChange: v => toggle(p, r, v)
      }))))))))));
    }), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        justifyContent: 'flex-end',
        gap: 10,
        paddingTop: 2
      }
    }, /*#__PURE__*/React.createElement("button", {
      style: {
        ...A_btn('ghost'),
        opacity: dirty ? 1 : 0.5,
        pointerEvents: dirty ? 'auto' : 'none'
      },
      onClick: reset
    }, A_T.roles.reset), /*#__PURE__*/React.createElement("button", {
      style: {
        ...A_btn('primary'),
        opacity: dirty ? 1 : 0.5,
        pointerEvents: dirty ? 'auto' : 'none'
      },
      onClick: save
    }, A_T.roles.save)));
  }
  function A_VisibilityTab({
    pushToast
  }) {
    const [vis, setVis] = useState(() => ({
      ...A_DEFAULT_VIS
    }));
    const [dirty, setDirty] = useState(false);
    const opts = [{
      value: 'all',
      label: A_T.vis.scopeAll
    }, {
      value: 'department',
      label: A_T.vis.scopeDepartment
    }, {
      value: 'own',
      label: A_T.vis.scopeOwn
    }];
    function setScope(r, v) {
      setVis(m => ({
        ...m,
        [r]: v
      }));
      setDirty(true);
    }
    function reset() {
      setVis({
        ...A_DEFAULT_VIS
      });
      setDirty(false);
    }
    function save() {
      pushToast(A_T.vis.saved);
      setDirty(false);
    }
    const th = {
      padding: '11px 16px',
      fontSize: 11.5,
      fontWeight: 600,
      letterSpacing: '.03em',
      textTransform: 'uppercase',
      color: 'var(--c-muted)',
      textAlign: 'start',
      borderBottom: '1px solid var(--c-border)'
    };
    return /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        gap: 16,
        maxWidth: 640
      }
    }, /*#__PURE__*/React.createElement(A_Msg, {
      severity: "warn"
    }, A_T.vis.warning), /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-sm)',
        overflow: 'hidden'
      }
    }, /*#__PURE__*/React.createElement("table", {
      style: {
        width: '100%',
        borderCollapse: 'collapse'
      }
    }, /*#__PURE__*/React.createElement("thead", null, /*#__PURE__*/React.createElement("tr", null, /*#__PURE__*/React.createElement("th", {
      style: {
        ...th,
        width: 200
      }
    }, A_T.vis.roleColumn), /*#__PURE__*/React.createElement("th", {
      style: th
    }, A_T.vis.scopeColumn))), /*#__PURE__*/React.createElement("tbody", null, A_ROLES.map(r => /*#__PURE__*/React.createElement("tr", {
      key: r,
      className: "urow"
    }, /*#__PURE__*/React.createElement("td", {
      style: {
        padding: '12px 16px',
        borderBottom: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement(A_Tag, {
      value: A_ROLE_LABEL[r],
      severity: A_ROLE_SEV[r]
    })), /*#__PURE__*/React.createElement("td", {
      style: {
        padding: '10px 16px',
        borderBottom: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement(A_Dropdown, {
      value: vis[r],
      onChange: v => setScope(r, v),
      options: opts,
      width: 240
    }))))))), /*#__PURE__*/React.createElement(A_Msg, {
      severity: "secondary"
    }, A_T.vis.departmentHint), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        justifyContent: 'flex-end',
        gap: 10
      }
    }, /*#__PURE__*/React.createElement("button", {
      style: {
        ...A_btn('ghost'),
        opacity: dirty ? 1 : 0.5,
        pointerEvents: dirty ? 'auto' : 'none'
      },
      onClick: reset
    }, A_T.vis.reset), /*#__PURE__*/React.createElement("button", {
      style: {
        ...A_btn('primary'),
        opacity: dirty ? 1 : 0.5,
        pointerEvents: dirty ? 'auto' : 'none'
      },
      onClick: save
    }, A_T.vis.save)));
  }
  function AccessTab() {
    const [tab, setTab] = useState('departments');
    const [toasts, setToasts] = useState([]);
    const pushToast = summary => {
      const id = Date.now() + Math.random();
      setToasts(t => [...t, {
        id,
        summary
      }]);
      setTimeout(() => setToasts(t => t.filter(x => x.id !== id)), 2600);
    };
    const TABS = [['departments', A_T.tabs.departments], ['roles', A_T.tabs.roles], ['visibility', A_T.tabs.visibility]];
    return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("div", {
      style: {
        marginBottom: 18
      }
    }, /*#__PURE__*/React.createElement("h2", {
      style: {
        margin: 0,
        fontSize: 20,
        fontWeight: 600
      }
    }, A_T.title), /*#__PURE__*/React.createElement("p", {
      style: {
        margin: '5px 0 0',
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }, A_T.subtitle)), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 4,
        borderBottom: '1px solid var(--c-border)',
        marginBottom: 20
      }
    }, TABS.map(([k, l]) => {
      const on = tab === k;
      return /*#__PURE__*/React.createElement("button", {
        key: k,
        onClick: () => setTab(k),
        style: {
          background: 'transparent',
          border: 'none',
          cursor: 'pointer',
          padding: '10px 14px',
          marginBottom: -1,
          whiteSpace: 'nowrap',
          fontFamily: 'var(--mg-font-sans)',
          fontSize: 14,
          fontWeight: on ? 600 : 500,
          color: on ? 'var(--mg-primary-900)' : 'var(--c-muted)',
          borderBottom: '2px solid ' + (on ? 'var(--mg-primary-900)' : 'transparent')
        }
      }, l);
    })), tab === 'departments' && /*#__PURE__*/React.createElement(A_DepartmentsTab, {
      pushToast: pushToast
    }), tab === 'roles' && /*#__PURE__*/React.createElement(A_RolesTab, {
      pushToast: pushToast
    }), tab === 'visibility' && /*#__PURE__*/React.createElement(A_VisibilityTab, {
      pushToast: pushToast
    }), /*#__PURE__*/React.createElement(A_Toasts, {
      items: toasts
    }));
  }
  window.AccessTab = AccessTab;
})();
})(); } catch (e) { __ds_ns.__errors.push({ path: "exports/mgcrm-package/design-handoff/redesign/access-section.jsx", error: String((e && e.message) || e) }); }

// exports/mgcrm-package/design-handoff/redesign/system-section.jsx
try { (() => {
/* System sections for Настройки → Система:
   - Журнал автоматизаций (AutomationsTab): read-only execution log of automation rules.
   - Сброс системы (ResetTab): selective data wipe — checkbox choice of which datasets to delete
     (unlike the product's full-wipe behaviour, per request).
   Built to match the fresh MACRO CRM look (shared --c-* / --mg-* tokens, table th/td, tags).
   Exposes window.AutomationsTab and window.ResetTab. */
(function () {
  const {
    useState
  } = React;

  /* ── shared button/tag helpers (mirror settings.html btn()) ── */
  function sBtn(kind) {
    const base = {
      display: 'inline-flex',
      alignItems: 'center',
      gap: 7,
      height: 38,
      padding: '0 15px',
      borderRadius: 'var(--mg-radius-md)',
      fontFamily: 'var(--mg-font-sans)',
      fontSize: 13,
      fontWeight: 600,
      cursor: 'pointer',
      border: 'none',
      whiteSpace: 'nowrap'
    };
    if (kind === 'primary') return {
      ...base,
      background: 'var(--mg-primary-900)',
      color: '#fff'
    };
    if (kind === 'danger') return {
      ...base,
      background: 'var(--mg-status-danger-solid)',
      color: '#fff'
    };
    return {
      ...base,
      background: 'transparent',
      color: 'var(--c-text2)',
      border: '1px solid var(--c-border2)'
    };
  }
  const sTh = {
    padding: '11px 16px',
    fontSize: 12,
    fontWeight: 600,
    color: 'var(--c-text2)',
    textAlign: 'start',
    borderBottom: '1px solid var(--c-border)',
    whiteSpace: 'nowrap',
    background: 'var(--c-card)'
  };
  const sTd = {
    padding: '12px 16px',
    fontSize: 13.5,
    color: 'var(--c-text)',
    borderBottom: '1px solid var(--c-border)',
    verticalAlign: 'middle'
  };
  function SCheck({
    checked,
    onChange,
    disabled,
    indeterminate
  }) {
    const active = checked || indeterminate;
    return /*#__PURE__*/React.createElement("span", {
      onClick: disabled ? undefined : e => {
        e.stopPropagation();
        onChange();
      },
      role: "checkbox",
      "aria-checked": checked,
      style: {
        width: 20,
        height: 20,
        borderRadius: 6,
        border: '1.5px solid ' + (active ? 'var(--mg-primary-900)' : 'var(--c-border2)'),
        background: active ? 'var(--mg-primary-900)' : 'var(--c-card)',
        display: 'inline-flex',
        alignItems: 'center',
        justifyContent: 'center',
        cursor: disabled ? 'not-allowed' : 'pointer',
        flexShrink: 0,
        opacity: disabled ? 0.45 : 1,
        transition: 'background .12s, border-color .12s'
      }
    }, checked ? /*#__PURE__*/React.createElement("i", {
      className: "pi pi-check",
      style: {
        fontSize: 11,
        color: '#fff'
      }
    }) : indeterminate ? /*#__PURE__*/React.createElement("span", {
      style: {
        width: 9,
        height: 2,
        borderRadius: 1,
        background: '#fff'
      }
    }) : null);
  }

  /* ═══════════════ Журнал автоматизаций ═══════════════ */
  const S_TRIG = {
    created: {
      label: 'Сделка создана',
      ic: 'pi-plus-circle'
    },
    stage: {
      label: 'Этап изменён',
      ic: 'pi-sort-alt'
    },
    overdue: {
      label: 'Задача просрочена',
      ic: 'pi-exclamation-circle'
    },
    webform: {
      label: 'Заявка с сайта',
      ic: 'pi-globe'
    },
    lost: {
      label: 'Статус «Отказ»',
      ic: 'pi-times-circle'
    },
    product: {
      label: 'Товар добавлен',
      ic: 'pi-box'
    },
    contact: {
      label: 'Контакт создан',
      ic: 'pi-user-plus'
    },
    noreply: {
      label: 'Нет ответа 24ч',
      ic: 'pi-clock'
    },
    won: {
      label: 'Сделка выиграна',
      ic: 'pi-check-circle'
    },
    birthday: {
      label: 'Дата рождения',
      ic: 'pi-calendar'
    }
  };
  const S_LOG = [{
    time: '02.07 · 09:41',
    name: 'Приветственное сообщение',
    trig: 'created',
    obj: 'Сделка #10485',
    link: true,
    action: 'Отправлено сообщение в Telegram',
    status: 'ok'
  }, {
    time: '02.07 · 09:38',
    name: 'Задача при смене этапа',
    trig: 'stage',
    obj: 'Сделка #10477',
    link: true,
    action: 'Создана задача «Позвонить клиенту»',
    status: 'ok'
  }, {
    time: '02.07 · 09:36',
    name: 'Автоназначение ответственного',
    trig: 'webform',
    obj: 'Сделка #10484',
    link: true,
    action: 'Назначен Иванов А. С.',
    status: 'ok'
  }, {
    time: '02.07 · 09:30',
    name: 'Уведомление при отказе',
    trig: 'lost',
    obj: 'Сделка #10460',
    link: true,
    action: 'Email руководителю — SMTP timeout',
    status: 'err'
  }, {
    time: '02.07 · 09:24',
    name: 'Пересчёт суммы сделки',
    trig: 'product',
    obj: 'Сделка #10483',
    link: true,
    action: 'Обновлено поле «Сумма» → 480 000 ₽',
    status: 'ok'
  }, {
    time: '02.07 · 09:19',
    name: 'Напоминание о просрочке',
    trig: 'overdue',
    obj: 'Задача #3391',
    link: false,
    action: 'Уведомление ответственному',
    status: 'ok'
  }, {
    time: '02.07 · 09:12',
    name: 'Дедупликация контактов',
    trig: 'contact',
    obj: 'Контакт «Петров И.»',
    link: true,
    action: 'Найден дубль — объединено',
    status: 'ok'
  }, {
    time: '02.07 · 08:58',
    name: 'SLA-эскалация',
    trig: 'noreply',
    obj: 'Сделка #10471',
    link: true,
    action: 'Правило отключено — пропущено',
    status: 'skip'
  }, {
    time: '02.07 · 08:45',
    name: 'Синхронизация с 1С',
    trig: 'won',
    obj: 'Сделка #10455',
    link: true,
    action: 'Выгрузка в 1С — нет связи с сервером',
    status: 'err'
  }, {
    time: '02.07 · 08:30',
    name: 'Поздравление с днём рождения',
    trig: 'birthday',
    obj: 'Контакт «Сидорова М.»',
    link: true,
    action: 'Email отправлен',
    status: 'ok'
  }];
  const S_STATUS = {
    ok: {
      label: 'Успешно',
      bg: 'var(--mg-status-success-bg)',
      fg: 'var(--mg-status-success-text)',
      ic: 'pi-check-circle'
    },
    err: {
      label: 'Ошибка',
      bg: 'var(--mg-status-danger-bg)',
      fg: 'var(--mg-status-danger-text)',
      ic: 'pi-times-circle'
    },
    skip: {
      label: 'Пропущено',
      bg: 'var(--c-muted2)',
      fg: 'var(--c-text2)',
      ic: 'pi-minus-circle'
    }
  };
  function AutomationsTab() {
    const [q, setQ] = useState('');
    const [f, setF] = useState('all');
    const counts = {
      all: S_LOG.length,
      ok: 0,
      err: 0,
      skip: 0
    };
    S_LOG.forEach(r => counts[r.status]++);
    const ql = q.trim().toLowerCase();
    const rows = S_LOG.filter(r => (f === 'all' || r.status === f) && (!ql || r.name.toLowerCase().includes(ql) || r.obj.toLowerCase().includes(ql) || S_TRIG[r.trig].label.toLowerCase().includes(ql)));
    return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("h2", {
      style: {
        margin: '2px 0 4px',
        fontSize: 22,
        fontWeight: 600
      }
    }, "\u0416\u0443\u0440\u043D\u0430\u043B \u0430\u0432\u0442\u043E\u043C\u0430\u0442\u0438\u0437\u0430\u0446\u0438\u0439"), /*#__PURE__*/React.createElement("p", {
      style: {
        margin: '0 0 20px',
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }, "\u0418\u0441\u0442\u043E\u0440\u0438\u044F \u0441\u0440\u0430\u0431\u0430\u0442\u044B\u0432\u0430\u043D\u0438\u044F \u043F\u0440\u0430\u0432\u0438\u043B \u0430\u0432\u0442\u043E\u043C\u0430\u0442\u0438\u0437\u0430\u0446\u0438\u0438 \u0437\u0430 \u043F\u043E\u0441\u043B\u0435\u0434\u043D\u0438\u0435 24 \u0447\u0430\u0441\u0430"), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        flexWrap: 'wrap',
        marginBottom: 14
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        height: 40,
        padding: '0 12px',
        flex: 1,
        minWidth: 200,
        maxWidth: 300,
        border: '1px solid var(--mg-input-border)',
        borderRadius: 'var(--mg-radius-md)',
        background: 'var(--c-card)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-search",
      style: {
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }), /*#__PURE__*/React.createElement("input", {
      value: q,
      onChange: e => setQ(e.target.value),
      placeholder: "\u041F\u043E\u0438\u0441\u043A \u043F\u043E \u043F\u0440\u0430\u0432\u0438\u043B\u0443 \u0438\u043B\u0438 \u043E\u0431\u044A\u0435\u043A\u0442\u0443\u2026",
      style: {
        flex: 1,
        border: 'none',
        outline: 'none',
        background: 'transparent',
        fontFamily: 'var(--mg-font-sans)',
        fontSize: 14,
        color: 'var(--c-text)'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'inline-flex',
        padding: 3,
        gap: 2,
        background: 'var(--c-muted2)',
        borderRadius: 'var(--mg-radius-md)'
      }
    }, [['all', 'Все'], ['ok', 'Успешно'], ['err', 'Ошибка'], ['skip', 'Пропущено']].map(([k, l]) => /*#__PURE__*/React.createElement("button", {
      key: k,
      onClick: () => setF(k),
      style: {
        display: 'inline-flex',
        alignItems: 'center',
        gap: 6,
        height: 32,
        padding: '0 12px',
        border: 'none',
        borderRadius: 'var(--mg-radius-sm)',
        cursor: 'pointer',
        fontFamily: 'var(--mg-font-sans)',
        fontSize: 12.5,
        fontWeight: 600,
        background: f === k ? 'var(--c-card)' : 'transparent',
        color: f === k ? 'var(--mg-primary-900)' : 'var(--c-text2)',
        boxShadow: f === k ? 'var(--mg-shadow-sm)' : 'none'
      }
    }, l, /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 11,
        color: 'var(--c-muted)'
      }
    }, counts[k])))), /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1
      }
    }), /*#__PURE__*/React.createElement("button", {
      style: sBtn('ghost')
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-refresh",
      style: {
        fontSize: 12
      }
    }), "\u041E\u0431\u043D\u043E\u0432\u0438\u0442\u044C")), /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-sm)',
        overflow: 'hidden'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        overflowX: 'auto'
      }
    }, /*#__PURE__*/React.createElement("table", {
      style: {
        width: '100%',
        borderCollapse: 'collapse',
        minWidth: 860
      }
    }, /*#__PURE__*/React.createElement("thead", null, /*#__PURE__*/React.createElement("tr", null, /*#__PURE__*/React.createElement("th", {
      style: {
        ...sTh,
        width: 108
      }
    }, "\u0412\u0440\u0435\u043C\u044F"), /*#__PURE__*/React.createElement("th", {
      style: sTh
    }, "\u0410\u0432\u0442\u043E\u043C\u0430\u0442\u0438\u0437\u0430\u0446\u0438\u044F"), /*#__PURE__*/React.createElement("th", {
      style: sTh
    }, "\u0422\u0440\u0438\u0433\u0433\u0435\u0440"), /*#__PURE__*/React.createElement("th", {
      style: sTh
    }, "\u041E\u0431\u044A\u0435\u043A\u0442"), /*#__PURE__*/React.createElement("th", {
      style: sTh
    }, "\u0414\u0435\u0439\u0441\u0442\u0432\u0438\u0435"), /*#__PURE__*/React.createElement("th", {
      style: {
        ...sTh,
        width: 130
      }
    }, "\u0421\u0442\u0430\u0442\u0443\u0441"))), /*#__PURE__*/React.createElement("tbody", null, rows.map((r, i) => {
      const st = S_STATUS[r.status];
      const tg = S_TRIG[r.trig];
      return /*#__PURE__*/React.createElement("tr", {
        key: i,
        className: "urow",
        style: {
          background: i % 2 ? 'var(--c-hover)' : 'var(--c-card)'
        }
      }, /*#__PURE__*/React.createElement("td", {
        style: {
          ...sTd,
          fontFamily: 'ui-monospace,monospace',
          fontSize: 12,
          color: 'var(--c-muted)',
          whiteSpace: 'nowrap'
        }
      }, r.time), /*#__PURE__*/React.createElement("td", {
        style: {
          ...sTd,
          fontWeight: 600
        }
      }, r.name), /*#__PURE__*/React.createElement("td", {
        style: sTd
      }, /*#__PURE__*/React.createElement("span", {
        style: {
          display: 'inline-flex',
          alignItems: 'center',
          gap: 6,
          fontSize: 12.5,
          fontWeight: 500,
          padding: '3px 9px',
          borderRadius: 6,
          background: 'var(--c-muted2)',
          color: 'var(--c-text2)',
          whiteSpace: 'nowrap'
        }
      }, /*#__PURE__*/React.createElement("i", {
        className: 'pi ' + tg.ic,
        style: {
          fontSize: 11
        }
      }), tg.label)), /*#__PURE__*/React.createElement("td", {
        style: {
          ...sTd,
          whiteSpace: 'nowrap'
        }
      }, /*#__PURE__*/React.createElement("span", {
        style: {
          color: r.link ? 'var(--mg-primary-900)' : 'var(--c-text)',
          fontWeight: r.link ? 500 : 400,
          cursor: r.link ? 'pointer' : 'default'
        }
      }, r.obj)), /*#__PURE__*/React.createElement("td", {
        style: {
          ...sTd,
          color: 'var(--c-text2)'
        }
      }, r.action), /*#__PURE__*/React.createElement("td", {
        style: sTd
      }, /*#__PURE__*/React.createElement("span", {
        style: {
          display: 'inline-flex',
          alignItems: 'center',
          gap: 6,
          fontSize: 12,
          fontWeight: 600,
          padding: '3px 10px',
          borderRadius: 999,
          background: st.bg,
          color: st.fg,
          whiteSpace: 'nowrap'
        }
      }, /*#__PURE__*/React.createElement("i", {
        className: 'pi ' + st.ic,
        style: {
          fontSize: 11
        }
      }), st.label)));
    })))), rows.length === 0 && /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        gap: 8,
        padding: '48px 20px',
        color: 'var(--c-muted)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-clock",
      style: {
        fontSize: 26,
        opacity: 0.4
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 14,
        color: 'var(--c-text2)',
        fontWeight: 500
      }
    }, "\u0417\u0430\u043F\u0438\u0441\u0438 \u043D\u0435 \u043D\u0430\u0439\u0434\u0435\u043D\u044B"))));
  }

  /* ═══════════════ Сброс системы ═══════════════ */
  const S_CATS = [{
    key: 'deals',
    icon: 'pi-briefcase',
    name: 'Сделки',
    desc: 'Все сделки и их история изменений',
    count: '1 248 записей'
  }, {
    key: 'contacts',
    icon: 'pi-user',
    name: 'Контакты',
    desc: 'Контактные лица и их данные',
    count: '3 972 записи'
  }, {
    key: 'companies',
    icon: 'pi-building',
    name: 'Компании',
    desc: 'Организации-клиенты',
    count: '864 записи'
  }, {
    key: 'tasks',
    icon: 'pi-check-square',
    name: 'Задачи и активности',
    desc: 'Задачи, звонки, встречи, заметки',
    count: '5 310 записей'
  }, {
    key: 'docs',
    icon: 'pi-file',
    name: 'Документы и файлы',
    desc: 'Договоры и вложения',
    count: '2 140 файлов'
  }, {
    key: 'finance',
    icon: 'pi-wallet',
    name: 'Финансовые операции',
    desc: 'Платежи, счета, проводки',
    count: '918 записей'
  }, {
    key: 'logs',
    icon: 'pi-history',
    name: 'Журналы и история',
    desc: 'Журнал автоматизаций и аудита',
    count: '42 300 событий'
  }, {
    key: 'automations',
    icon: 'pi-bolt',
    name: 'Правила автоматизаций',
    desc: 'Настроенные сценарии',
    count: '36 правил'
  }, {
    key: 'directories',
    icon: 'pi-folder-open',
    name: 'Справочники',
    desc: 'Товары, теги, поля, курсы валют',
    count: '512 записей'
  }];
  const S_CONFIRM_WORD = 'СБРОСИТЬ';
  function ResetTab() {
    const [sel, setSel] = useState({});
    const [word, setWord] = useState('');
    const [confirm, setConfirm] = useState(false);
    const [done, setDone] = useState(false);
    const selKeys = S_CATS.filter(c => sel[c.key]).map(c => c.key);
    const n = selKeys.length;
    const allOn = n === S_CATS.length;
    const toggle = k => setSel(s => ({
      ...s,
      [k]: !s[k]
    }));
    const toggleAll = () => {
      const v = !allOn;
      const m = {};
      S_CATS.forEach(c => {
        m[c.key] = v;
      });
      setSel(m);
    };
    const canReset = n > 0 && word.trim().toUpperCase() === S_CONFIRM_WORD;
    function doReset() {
      setConfirm(false);
      setDone(true);
      setSel({});
      setWord('');
      setTimeout(() => setDone(false), 3600);
    }
    return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("h2", {
      style: {
        margin: '2px 0 4px',
        fontSize: 22,
        fontWeight: 600
      }
    }, "\u0421\u0431\u0440\u043E\u0441 \u0441\u0438\u0441\u0442\u0435\u043C\u044B"), /*#__PURE__*/React.createElement("p", {
      style: {
        margin: '0 0 20px',
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }, "\u0412\u044B\u0431\u043E\u0440\u043E\u0447\u043D\u043E\u0435 \u0443\u0434\u0430\u043B\u0435\u043D\u0438\u0435 \u0434\u0430\u043D\u043D\u044B\u0445 \u0438\u0437 \u0441\u0438\u0441\u0442\u0435\u043C\u044B. \u041D\u0430\u0441\u0442\u0440\u043E\u0439\u043A\u0438 \u0430\u043A\u043A\u0430\u0443\u043D\u0442\u0430 \u0438 \u0443\u0447\u0451\u0442\u043D\u044B\u0435 \u0437\u0430\u043F\u0438\u0441\u0438 \u0441\u043E\u0445\u0440\u0430\u043D\u044F\u044E\u0442\u0441\u044F."), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 12,
        alignItems: 'flex-start',
        padding: '14px 16px',
        marginBottom: 16,
        background: 'var(--mg-status-danger-bg)',
        border: '1px solid var(--mg-status-danger-border)',
        borderRadius: 'var(--mg-radius-lg)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-exclamation-triangle",
      style: {
        fontSize: 18,
        color: 'var(--mg-status-danger-text)',
        marginTop: 1,
        flexShrink: 0
      }
    }), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 13.5,
        color: 'var(--c-text2)',
        lineHeight: 1.55
      }
    }, /*#__PURE__*/React.createElement("b", {
      style: {
        color: 'var(--mg-status-danger-text)',
        fontWeight: 600
      }
    }, "\u041E\u043F\u0435\u0440\u0430\u0446\u0438\u044F \u043D\u0435\u043E\u0431\u0440\u0430\u0442\u0438\u043C\u0430."), " \u0412\u044B\u0431\u0440\u0430\u043D\u043D\u044B\u0435 \u0434\u0430\u043D\u043D\u044B\u0435 \u0431\u0443\u0434\u0443\u0442 \u0443\u0434\u0430\u043B\u0435\u043D\u044B \u0431\u0435\u0437\u0432\u043E\u0437\u0432\u0440\u0430\u0442\u043D\u043E. \u041F\u0435\u0440\u0435\u0434 \u0441\u0431\u0440\u043E\u0441\u043E\u043C \u0440\u0435\u043A\u043E\u043C\u0435\u043D\u0434\u0443\u0435\u043C \u0432\u044B\u0433\u0440\u0443\u0437\u0438\u0442\u044C \u0440\u0435\u0437\u0435\u0440\u0432\u043D\u0443\u044E \u043A\u043E\u043F\u0438\u044E.")), /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-sm)',
        overflow: 'hidden',
        marginBottom: 16
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: toggleAll,
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 12,
        padding: '13px 16px',
        borderBottom: '1px solid var(--c-border)',
        background: 'var(--c-hover)',
        cursor: 'pointer'
      }
    }, /*#__PURE__*/React.createElement(SCheck, {
      checked: allOn,
      indeterminate: n > 0 && !allOn,
      onChange: toggleAll
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 13.5,
        fontWeight: 600
      }
    }, "\u0412\u044B\u0431\u0440\u0430\u0442\u044C \u0432\u0441\u0451"), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 12.5,
        color: 'var(--c-muted)',
        whiteSpace: 'nowrap',
        flexShrink: 0
      }
    }, "\u0412\u044B\u0431\u0440\u0430\u043D\u043E: ", n, " \u0438\u0437 ", S_CATS.length)), S_CATS.map((c, i) => {
      const on = !!sel[c.key];
      return /*#__PURE__*/React.createElement("div", {
        key: c.key,
        onClick: () => toggle(c.key),
        style: {
          display: 'flex',
          alignItems: 'center',
          gap: 13,
          padding: '13px 16px',
          borderBottom: i < S_CATS.length - 1 ? '1px solid var(--c-border)' : 'none',
          cursor: 'pointer',
          background: on ? 'var(--mg-primary-100)' : 'transparent',
          transition: 'background .12s'
        }
      }, /*#__PURE__*/React.createElement(SCheck, {
        checked: on,
        onChange: () => toggle(c.key)
      }), /*#__PURE__*/React.createElement("span", {
        style: {
          width: 36,
          height: 36,
          borderRadius: 'var(--mg-radius-md)',
          background: 'var(--c-muted2)',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          flexShrink: 0
        }
      }, /*#__PURE__*/React.createElement("i", {
        className: 'pi ' + c.icon,
        style: {
          fontSize: 15,
          color: 'var(--c-text2)'
        }
      })), /*#__PURE__*/React.createElement("div", {
        style: {
          flex: 1,
          minWidth: 0
        }
      }, /*#__PURE__*/React.createElement("div", {
        style: {
          fontSize: 14,
          fontWeight: 600,
          color: 'var(--c-text)'
        }
      }, c.name), /*#__PURE__*/React.createElement("div", {
        style: {
          fontSize: 12.5,
          color: 'var(--c-muted)',
          marginTop: 2
        }
      }, c.desc)), /*#__PURE__*/React.createElement("span", {
        style: {
          fontSize: 12,
          fontWeight: 600,
          padding: '3px 10px',
          borderRadius: 999,
          background: 'var(--c-muted2)',
          color: 'var(--c-text2)',
          whiteSpace: 'nowrap'
        }
      }, c.count));
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-sm)',
        padding: '18px 20px'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 14,
        fontWeight: 600,
        marginBottom: 4
      }
    }, "\u041F\u043E\u0434\u0442\u0432\u0435\u0440\u0436\u0434\u0435\u043D\u0438\u0435"), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 13,
        color: 'var(--c-muted)',
        marginBottom: 12
      }
    }, "\u0427\u0442\u043E\u0431\u044B \u0443\u0434\u0430\u043B\u0438\u0442\u044C ", n > 0 ? 'выбранные данные (' + n + ')' : 'выбранные данные', ", \u0432\u0432\u0435\u0434\u0438\u0442\u0435 ", /*#__PURE__*/React.createElement("b", {
      style: {
        color: 'var(--c-text2)',
        fontFamily: 'ui-monospace,monospace'
      }
    }, S_CONFIRM_WORD), " \u0432 \u043F\u043E\u043B\u0435 \u043D\u0438\u0436\u0435."), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 12,
        alignItems: 'center',
        flexWrap: 'wrap'
      }
    }, /*#__PURE__*/React.createElement("input", {
      className: "inp",
      value: word,
      onChange: e => setWord(e.target.value),
      placeholder: S_CONFIRM_WORD,
      style: {
        flex: 1,
        minWidth: 200,
        maxWidth: 280,
        height: 40,
        padding: '0 13px',
        borderRadius: 'var(--mg-radius-md)',
        border: '1px solid ' + (word && !canReset && n > 0 ? 'var(--mg-status-danger-border)' : 'var(--mg-input-border)'),
        background: 'var(--c-card)',
        color: 'var(--c-text)',
        fontFamily: 'ui-monospace,monospace',
        fontSize: 14,
        letterSpacing: '0.05em'
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1
      }
    }), /*#__PURE__*/React.createElement("button", {
      disabled: !canReset,
      onClick: () => setConfirm(true),
      style: {
        ...sBtn('danger'),
        opacity: canReset ? 1 : 0.45,
        pointerEvents: canReset ? 'auto' : 'none'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-trash",
      style: {
        fontSize: 13
      }
    }), "\u0423\u0434\u0430\u043B\u0438\u0442\u044C \u0432\u044B\u0431\u0440\u0430\u043D\u043D\u043E\u0435", n > 0 ? ' (' + n + ')' : ''))), confirm && /*#__PURE__*/React.createElement("div", {
      onClick: () => setConfirm(false),
      style: {
        position: 'fixed',
        inset: 0,
        zIndex: 1500,
        background: 'rgba(9,16,32,0.55)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: 20
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: e => e.stopPropagation(),
      style: {
        width: 460,
        maxWidth: '92vw',
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-lg)'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '16px 20px',
        borderBottom: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 16,
        fontWeight: 600
      }
    }, "\u0423\u0434\u0430\u043B\u0438\u0442\u044C \u0434\u0430\u043D\u043D\u044B\u0435?"), /*#__PURE__*/React.createElement("i", {
      className: "pi pi-times",
      onClick: () => setConfirm(false),
      style: {
        fontSize: 14,
        color: 'var(--c-muted)',
        cursor: 'pointer'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        padding: 20,
        display: 'flex',
        gap: 14,
        alignItems: 'flex-start'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        width: 42,
        height: 42,
        borderRadius: '50%',
        background: 'var(--mg-status-danger-bg)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        flexShrink: 0
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-exclamation-triangle",
      style: {
        fontSize: 18,
        color: 'var(--mg-status-danger-text)'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 14,
        color: 'var(--c-text2)',
        lineHeight: 1.55,
        paddingTop: 2
      }
    }, "\u0411\u0443\u0434\u0435\u0442 \u0431\u0435\u0437\u0432\u043E\u0437\u0432\u0440\u0430\u0442\u043D\u043E \u0443\u0434\u0430\u043B\u0435\u043D\u043E ", /*#__PURE__*/React.createElement("b", {
      style: {
        color: 'var(--c-text)'
      }
    }, n), " ", n === 1 ? 'категория' : n < 5 ? 'категории' : 'категорий', " \u0434\u0430\u043D\u043D\u044B\u0445: ", selKeys.map(k => (S_CATS.find(c => c.key === k) || {}).name).join(', '), ". \u041F\u0440\u043E\u0434\u043E\u043B\u0436\u0438\u0442\u044C?")), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        justifyContent: 'flex-end',
        gap: 10,
        padding: '14px 20px',
        borderTop: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("button", {
      style: sBtn('ghost'),
      onClick: () => setConfirm(false)
    }, "\u041E\u0442\u043C\u0435\u043D\u0430"), /*#__PURE__*/React.createElement("button", {
      style: sBtn('danger'),
      onClick: doReset
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-trash",
      style: {
        fontSize: 13
      }
    }), "\u0423\u0434\u0430\u043B\u0438\u0442\u044C \u0431\u0435\u0437\u0432\u043E\u0437\u0432\u0440\u0430\u0442\u043D\u043E")))), done && /*#__PURE__*/React.createElement("div", {
      style: {
        position: 'fixed',
        insetBlockEnd: 20,
        insetInlineEnd: 20,
        zIndex: 2000,
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        minWidth: 260,
        padding: '12px 14px',
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderInlineStart: '3px solid var(--mg-status-success-text)',
        borderRadius: 'var(--mg-radius-md)',
        boxShadow: 'var(--mg-shadow-lg)',
        animation: 'toastIn .2s ease'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-check-circle",
      style: {
        fontSize: 16,
        color: 'var(--mg-status-success-text)'
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 13.5,
        fontWeight: 500
      }
    }, "\u0412\u044B\u0431\u0440\u0430\u043D\u043D\u044B\u0435 \u0434\u0430\u043D\u043D\u044B\u0435 \u0443\u0434\u0430\u043B\u0435\u043D\u044B")));
  }
  window.AutomationsTab = AutomationsTab;
  window.ResetTab = ResetTab;
})();
})(); } catch (e) { __ds_ns.__errors.push({ path: "exports/mgcrm-package/design-handoff/redesign/system-section.jsx", error: String((e && e.message) || e) }); }

// exports/mgcrm-package/design-handoff/redesign/tweaks-panel.jsx
try { (() => {
// @ds-adherence-ignore -- omelette starter scaffold (raw elements/hex/px by design)

/* BEGIN USAGE */
// tweaks-panel.jsx
// Reusable Tweaks shell + form-control helpers.
// Exports (to window): useTweaks, TweaksPanel, TweakSection, TweakRow, TweakSlider,
//   TweakToggle, TweakRadio, TweakSelect, TweakText, TweakNumber, TweakColor, TweakButton.
//
// Owns the host protocol (listens for __activate_edit_mode / __deactivate_edit_mode,
// posts __edit_mode_available / __edit_mode_set_keys / __edit_mode_dismissed) so
// individual prototypes don't re-roll it. Ships a consistent set of controls so you
// don't hand-draw <input type="range">, segmented radios, steppers, etc.
//
// Usage (in an HTML file that loads React + Babel):
//
//   const TWEAK_DEFAULTS = /*EDITMODE-BEGIN*/{
//     "primaryColor": "#D97757",
//     "palette": ["#D97757", "#29261b", "#f6f4ef"],
//     "fontSize": 16,
//     "density": "regular",
//     "dark": false
//   }/*EDITMODE-END*/;
//
//   function App() {
//     const [t, setTweak] = useTweaks(TWEAK_DEFAULTS);
//     return (
//       <div style={{ fontSize: t.fontSize, color: t.primaryColor }}>
//         Hello
//         <TweaksPanel>
//           <TweakSection label="Typography" />
//           <TweakSlider label="Font size" value={t.fontSize} min={10} max={32} unit="px"
//                        onChange={(v) => setTweak('fontSize', v)} />
//           <TweakRadio  label="Density" value={t.density}
//                        options={['compact', 'regular', 'comfy']}
//                        onChange={(v) => setTweak('density', v)} />
//           <TweakSection label="Theme" />
//           <TweakColor  label="Primary" value={t.primaryColor}
//                        options={['#D97757', '#2A6FDB', '#1F8A5B', '#7A5AE0']}
//                        onChange={(v) => setTweak('primaryColor', v)} />
//           <TweakColor  label="Palette" value={t.palette}
//                        options={[['#D97757', '#29261b', '#f6f4ef'],
//                                  ['#475569', '#0f172a', '#f1f5f9']]}
//                        onChange={(v) => setTweak('palette', v)} />
//           <TweakToggle label="Dark mode" value={t.dark}
//                        onChange={(v) => setTweak('dark', v)} />
//         </TweaksPanel>
//       </div>
//     );
//   }
//
// TweakRadio is the segmented control for 2–3 short options (auto-falls-back to
// TweakSelect past ~16/~10 chars per label); reach for TweakSelect directly when
// options are many or long. For color tweaks always curate 3-4 options rather than
// a free picker; an option can also be a whole 2–5 color palette (the stored value
// is the array). The Tweak* controls are a floor, not a ceiling — build custom
// controls inside the panel if a tweak calls for UI they don't cover.
/* END USAGE */
// ─────────────────────────────────────────────────────────────────────────────

const __TWEAKS_STYLE = `
  .twk-panel{position:fixed;right:16px;bottom:16px;z-index:2147483646;width:280px;
    max-height:calc(100vh - 32px);display:flex;flex-direction:column;
    transform:scale(var(--dc-inv-zoom,1));transform-origin:bottom right;
    background:rgba(250,249,247,.78);color:#29261b;
    -webkit-backdrop-filter:blur(24px) saturate(160%);backdrop-filter:blur(24px) saturate(160%);
    border:.5px solid rgba(255,255,255,.6);border-radius:14px;
    box-shadow:0 1px 0 rgba(255,255,255,.5) inset,0 12px 40px rgba(0,0,0,.18);
    font:11.5px/1.4 ui-sans-serif,system-ui,-apple-system,sans-serif;overflow:hidden}
  .twk-hd{display:flex;align-items:center;justify-content:space-between;
    padding:10px 8px 10px 14px;cursor:move;user-select:none}
  .twk-hd b{font-size:12px;font-weight:600;letter-spacing:.01em}
  .twk-x{appearance:none;border:0;background:transparent;color:rgba(41,38,27,.55);
    width:22px;height:22px;border-radius:6px;cursor:default;font-size:13px;line-height:1}
  .twk-x:hover{background:rgba(0,0,0,.06);color:#29261b}
  .twk-body{padding:2px 14px 14px;display:flex;flex-direction:column;gap:10px;
    overflow-y:auto;overflow-x:hidden;min-height:0;
    scrollbar-width:thin;scrollbar-color:rgba(0,0,0,.15) transparent}
  .twk-body::-webkit-scrollbar{width:8px}
  .twk-body::-webkit-scrollbar-track{background:transparent;margin:2px}
  .twk-body::-webkit-scrollbar-thumb{background:rgba(0,0,0,.15);border-radius:4px;
    border:2px solid transparent;background-clip:content-box}
  .twk-body::-webkit-scrollbar-thumb:hover{background:rgba(0,0,0,.25);
    border:2px solid transparent;background-clip:content-box}
  .twk-row{display:flex;flex-direction:column;gap:5px}
  .twk-row-h{flex-direction:row;align-items:center;justify-content:space-between;gap:10px}
  .twk-lbl{display:flex;justify-content:space-between;align-items:baseline;
    color:rgba(41,38,27,.72)}
  .twk-lbl>span:first-child{font-weight:500}
  .twk-val{color:rgba(41,38,27,.5);font-variant-numeric:tabular-nums}

  .twk-sect{font-size:10px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;
    color:rgba(41,38,27,.45);padding:10px 0 0}
  .twk-sect:first-child{padding-top:0}

  .twk-field{appearance:none;box-sizing:border-box;width:100%;min-width:0;height:26px;padding:0 8px;
    border:.5px solid rgba(0,0,0,.1);border-radius:7px;
    background:rgba(255,255,255,.6);color:inherit;font:inherit;outline:none}
  .twk-field:focus{border-color:rgba(0,0,0,.25);background:rgba(255,255,255,.85)}
  select.twk-field{padding-right:22px;
    background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'><path fill='rgba(0,0,0,.5)' d='M0 0h10L5 6z'/></svg>");
    background-repeat:no-repeat;background-position:right 8px center}

  .twk-slider{appearance:none;-webkit-appearance:none;width:100%;height:4px;margin:6px 0;
    border-radius:999px;background:rgba(0,0,0,.12);outline:none}
  .twk-slider::-webkit-slider-thumb{-webkit-appearance:none;appearance:none;
    width:14px;height:14px;border-radius:50%;background:#fff;
    border:.5px solid rgba(0,0,0,.12);box-shadow:0 1px 3px rgba(0,0,0,.2);cursor:default}
  .twk-slider::-moz-range-thumb{width:14px;height:14px;border-radius:50%;
    background:#fff;border:.5px solid rgba(0,0,0,.12);box-shadow:0 1px 3px rgba(0,0,0,.2);cursor:default}

  .twk-seg{position:relative;display:flex;padding:2px;border-radius:8px;
    background:rgba(0,0,0,.06);user-select:none}
  .twk-seg-thumb{position:absolute;top:2px;bottom:2px;border-radius:6px;
    background:rgba(255,255,255,.9);box-shadow:0 1px 2px rgba(0,0,0,.12);
    transition:left .15s cubic-bezier(.3,.7,.4,1),width .15s}
  .twk-seg.dragging .twk-seg-thumb{transition:none}
  .twk-seg button{appearance:none;position:relative;z-index:1;flex:1;border:0;
    background:transparent;color:inherit;font:inherit;font-weight:500;min-height:22px;
    border-radius:6px;cursor:default;padding:4px 6px;line-height:1.2;
    overflow-wrap:anywhere}

  .twk-toggle{position:relative;width:32px;height:18px;border:0;border-radius:999px;
    background:rgba(0,0,0,.15);transition:background .15s;cursor:default;padding:0}
  .twk-toggle[data-on="1"]{background:#34c759}
  .twk-toggle i{position:absolute;top:2px;left:2px;width:14px;height:14px;border-radius:50%;
    background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.25);transition:transform .15s}
  .twk-toggle[data-on="1"] i{transform:translateX(14px)}

  .twk-num{display:flex;align-items:center;box-sizing:border-box;min-width:0;height:26px;padding:0 0 0 8px;
    border:.5px solid rgba(0,0,0,.1);border-radius:7px;background:rgba(255,255,255,.6)}
  .twk-num-lbl{font-weight:500;color:rgba(41,38,27,.6);cursor:ew-resize;
    user-select:none;padding-right:8px}
  .twk-num input{flex:1;min-width:0;height:100%;border:0;background:transparent;
    font:inherit;font-variant-numeric:tabular-nums;text-align:right;padding:0 8px 0 0;
    outline:none;color:inherit;-moz-appearance:textfield}
  .twk-num input::-webkit-inner-spin-button,.twk-num input::-webkit-outer-spin-button{
    -webkit-appearance:none;margin:0}
  .twk-num-unit{padding-right:8px;color:rgba(41,38,27,.45)}

  .twk-btn{appearance:none;height:26px;padding:0 12px;border:0;border-radius:7px;
    background:rgba(0,0,0,.78);color:#fff;font:inherit;font-weight:500;cursor:default}
  .twk-btn:hover{background:rgba(0,0,0,.88)}
  .twk-btn.secondary{background:rgba(0,0,0,.06);color:inherit}
  .twk-btn.secondary:hover{background:rgba(0,0,0,.1)}

  .twk-swatch{appearance:none;-webkit-appearance:none;width:56px;height:22px;
    border:.5px solid rgba(0,0,0,.1);border-radius:6px;padding:0;cursor:default;
    background:transparent;flex-shrink:0}
  .twk-swatch::-webkit-color-swatch-wrapper{padding:0}
  .twk-swatch::-webkit-color-swatch{border:0;border-radius:5.5px}
  .twk-swatch::-moz-color-swatch{border:0;border-radius:5.5px}

  .twk-chips{display:flex;gap:6px}
  .twk-chip{position:relative;appearance:none;flex:1;min-width:0;height:46px;
    padding:0;border:0;border-radius:6px;overflow:hidden;cursor:default;
    box-shadow:0 0 0 .5px rgba(0,0,0,.12),0 1px 2px rgba(0,0,0,.06);
    transition:transform .12s cubic-bezier(.3,.7,.4,1),box-shadow .12s}
  .twk-chip:hover{transform:translateY(-1px);
    box-shadow:0 0 0 .5px rgba(0,0,0,.18),0 4px 10px rgba(0,0,0,.12)}
  .twk-chip[data-on="1"]{box-shadow:0 0 0 1.5px rgba(0,0,0,.85),
    0 2px 6px rgba(0,0,0,.15)}
  .twk-chip>span{position:absolute;top:0;bottom:0;right:0;width:34%;
    display:flex;flex-direction:column;box-shadow:-1px 0 0 rgba(0,0,0,.1)}
  .twk-chip>span>i{flex:1;box-shadow:0 -1px 0 rgba(0,0,0,.1)}
  .twk-chip>span>i:first-child{box-shadow:none}
  .twk-chip svg{position:absolute;top:6px;left:6px;width:13px;height:13px;
    filter:drop-shadow(0 1px 1px rgba(0,0,0,.3))}
`;

// ── useTweaks ───────────────────────────────────────────────────────────────
// Single source of truth for tweak values. setTweak persists via the host
// (__edit_mode_set_keys → host rewrites the EDITMODE block on disk).
function useTweaks(defaults) {
  const [values, setValues] = React.useState(defaults);
  // Accepts either setTweak('key', value) or setTweak({ key: value, ... }) so a
  // useState-style call doesn't write a "[object Object]" key into the persisted
  // JSON block.
  const setTweak = React.useCallback((keyOrEdits, val) => {
    const edits = typeof keyOrEdits === 'object' && keyOrEdits !== null ? keyOrEdits : {
      [keyOrEdits]: val
    };
    setValues(prev => ({
      ...prev,
      ...edits
    }));
    window.parent.postMessage({
      type: '__edit_mode_set_keys',
      edits
    }, '*');
    // Same-window signal so in-page listeners (deck-stage rail thumbnails)
    // can react — the parent message only reaches the host, not peers.
    window.dispatchEvent(new CustomEvent('tweakchange', {
      detail: edits
    }));
  }, []);
  return [values, setTweak];
}

// ── TweaksPanel ─────────────────────────────────────────────────────────────
// Floating shell. Registers the protocol listener BEFORE announcing
// availability — if the announce ran first, the host's activate could land
// before our handler exists and the toolbar toggle would silently no-op.
// The close button posts __edit_mode_dismissed so the host's toolbar toggle
// flips off in lockstep; the host echoes __deactivate_edit_mode back which
// is what actually hides the panel.
function TweaksPanel({
  title = 'Tweaks',
  children
}) {
  const [open, setOpen] = React.useState(false);
  const dragRef = React.useRef(null);
  const offsetRef = React.useRef({
    x: 16,
    y: 16
  });
  const PAD = 16;
  const clampToViewport = React.useCallback(() => {
    const panel = dragRef.current;
    if (!panel) return;
    const w = panel.offsetWidth,
      h = panel.offsetHeight;
    const maxRight = Math.max(PAD, window.innerWidth - w - PAD);
    const maxBottom = Math.max(PAD, window.innerHeight - h - PAD);
    offsetRef.current = {
      x: Math.min(maxRight, Math.max(PAD, offsetRef.current.x)),
      y: Math.min(maxBottom, Math.max(PAD, offsetRef.current.y))
    };
    panel.style.right = offsetRef.current.x + 'px';
    panel.style.bottom = offsetRef.current.y + 'px';
  }, []);
  React.useEffect(() => {
    if (!open) return;
    clampToViewport();
    if (typeof ResizeObserver === 'undefined') {
      window.addEventListener('resize', clampToViewport);
      return () => window.removeEventListener('resize', clampToViewport);
    }
    const ro = new ResizeObserver(clampToViewport);
    ro.observe(document.documentElement);
    return () => ro.disconnect();
  }, [open, clampToViewport]);
  React.useEffect(() => {
    const onMsg = e => {
      const t = e?.data?.type;
      if (t === '__activate_edit_mode') setOpen(true);else if (t === '__deactivate_edit_mode') setOpen(false);
    };
    window.addEventListener('message', onMsg);
    window.parent.postMessage({
      type: '__edit_mode_available'
    }, '*');
    return () => window.removeEventListener('message', onMsg);
  }, []);
  const dismiss = () => {
    setOpen(false);
    window.parent.postMessage({
      type: '__edit_mode_dismissed'
    }, '*');
  };
  const onDragStart = e => {
    const panel = dragRef.current;
    if (!panel) return;
    const r = panel.getBoundingClientRect();
    const sx = e.clientX,
      sy = e.clientY;
    const startRight = window.innerWidth - r.right;
    const startBottom = window.innerHeight - r.bottom;
    const move = ev => {
      offsetRef.current = {
        x: startRight - (ev.clientX - sx),
        y: startBottom - (ev.clientY - sy)
      };
      clampToViewport();
    };
    const up = () => {
      window.removeEventListener('mousemove', move);
      window.removeEventListener('mouseup', up);
    };
    window.addEventListener('mousemove', move);
    window.addEventListener('mouseup', up);
  };
  if (!open) return null;
  return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("style", null, __TWEAKS_STYLE), /*#__PURE__*/React.createElement("div", {
    ref: dragRef,
    className: "twk-panel",
    "data-omelette-chrome": "",
    style: {
      right: offsetRef.current.x,
      bottom: offsetRef.current.y
    }
  }, /*#__PURE__*/React.createElement("div", {
    className: "twk-hd",
    onMouseDown: onDragStart
  }, /*#__PURE__*/React.createElement("b", null, title), /*#__PURE__*/React.createElement("button", {
    className: "twk-x",
    "aria-label": "Close tweaks",
    onMouseDown: e => e.stopPropagation(),
    onClick: dismiss
  }, "\u2715")), /*#__PURE__*/React.createElement("div", {
    className: "twk-body"
  }, children)));
}

// ── Layout helpers ──────────────────────────────────────────────────────────

function TweakSection({
  label,
  children
}) {
  return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("div", {
    className: "twk-sect"
  }, label), children);
}
function TweakRow({
  label,
  value,
  children,
  inline = false
}) {
  return /*#__PURE__*/React.createElement("div", {
    className: inline ? 'twk-row twk-row-h' : 'twk-row'
  }, /*#__PURE__*/React.createElement("div", {
    className: "twk-lbl"
  }, /*#__PURE__*/React.createElement("span", null, label), value != null && /*#__PURE__*/React.createElement("span", {
    className: "twk-val"
  }, value)), children);
}

// ── Controls ────────────────────────────────────────────────────────────────

function TweakSlider({
  label,
  value,
  min = 0,
  max = 100,
  step = 1,
  unit = '',
  onChange
}) {
  return /*#__PURE__*/React.createElement(TweakRow, {
    label: label,
    value: `${value}${unit}`
  }, /*#__PURE__*/React.createElement("input", {
    type: "range",
    className: "twk-slider",
    min: min,
    max: max,
    step: step,
    value: value,
    onChange: e => onChange(Number(e.target.value))
  }));
}
function TweakToggle({
  label,
  value,
  onChange
}) {
  return /*#__PURE__*/React.createElement("div", {
    className: "twk-row twk-row-h"
  }, /*#__PURE__*/React.createElement("div", {
    className: "twk-lbl"
  }, /*#__PURE__*/React.createElement("span", null, label)), /*#__PURE__*/React.createElement("button", {
    type: "button",
    className: "twk-toggle",
    "data-on": value ? '1' : '0',
    role: "switch",
    "aria-checked": !!value,
    onClick: () => onChange(!value)
  }, /*#__PURE__*/React.createElement("i", null)));
}
function TweakRadio({
  label,
  value,
  options,
  onChange
}) {
  const trackRef = React.useRef(null);
  const [dragging, setDragging] = React.useState(false);
  // The active value is read by pointer-move handlers attached for the lifetime
  // of a drag — ref it so a stale closure doesn't fire onChange for every move.
  const valueRef = React.useRef(value);
  valueRef.current = value;

  // Segments wrap mid-word once per-segment width runs out. The track is
  // ~248px (280 panel − 28 body pad − 4 seg pad), each button loses 12px
  // to its own padding, and 11.5px system-ui averages ~6.3px/char — so 2
  // options fit ~16 chars each, 3 fit ~10. Past that (or >3 options), fall
  // back to a dropdown rather than wrap.
  const labelLen = o => String(typeof o === 'object' ? o.label : o).length;
  const maxLen = options.reduce((m, o) => Math.max(m, labelLen(o)), 0);
  const fitsAsSegments = maxLen <= ({
    2: 16,
    3: 10
  }[options.length] ?? 0);
  if (!fitsAsSegments) {
    // <select> emits strings — map back to the original option value so the
    // fallback stays type-preserving (numbers, booleans) like the segment path.
    const resolve = s => {
      const m = options.find(o => String(typeof o === 'object' ? o.value : o) === s);
      return m === undefined ? s : typeof m === 'object' ? m.value : m;
    };
    return /*#__PURE__*/React.createElement(TweakSelect, {
      label: label,
      value: value,
      options: options,
      onChange: s => onChange(resolve(s))
    });
  }
  const opts = options.map(o => typeof o === 'object' ? o : {
    value: o,
    label: o
  });
  const idx = Math.max(0, opts.findIndex(o => o.value === value));
  const n = opts.length;
  const segAt = clientX => {
    const r = trackRef.current.getBoundingClientRect();
    const inner = r.width - 4;
    const i = Math.floor((clientX - r.left - 2) / inner * n);
    return opts[Math.max(0, Math.min(n - 1, i))].value;
  };
  const onPointerDown = e => {
    setDragging(true);
    const v0 = segAt(e.clientX);
    if (v0 !== valueRef.current) onChange(v0);
    const move = ev => {
      if (!trackRef.current) return;
      const v = segAt(ev.clientX);
      if (v !== valueRef.current) onChange(v);
    };
    const up = () => {
      setDragging(false);
      window.removeEventListener('pointermove', move);
      window.removeEventListener('pointerup', up);
    };
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', up);
  };
  return /*#__PURE__*/React.createElement(TweakRow, {
    label: label
  }, /*#__PURE__*/React.createElement("div", {
    ref: trackRef,
    role: "radiogroup",
    onPointerDown: onPointerDown,
    className: dragging ? 'twk-seg dragging' : 'twk-seg'
  }, /*#__PURE__*/React.createElement("div", {
    className: "twk-seg-thumb",
    style: {
      left: `calc(2px + ${idx} * (100% - 4px) / ${n})`,
      width: `calc((100% - 4px) / ${n})`
    }
  }), opts.map(o => /*#__PURE__*/React.createElement("button", {
    key: o.value,
    type: "button",
    role: "radio",
    "aria-checked": o.value === value
  }, o.label))));
}
function TweakSelect({
  label,
  value,
  options,
  onChange
}) {
  return /*#__PURE__*/React.createElement(TweakRow, {
    label: label
  }, /*#__PURE__*/React.createElement("select", {
    className: "twk-field",
    value: value,
    onChange: e => onChange(e.target.value)
  }, options.map(o => {
    const v = typeof o === 'object' ? o.value : o;
    const l = typeof o === 'object' ? o.label : o;
    return /*#__PURE__*/React.createElement("option", {
      key: v,
      value: v
    }, l);
  })));
}
function TweakText({
  label,
  value,
  placeholder,
  onChange
}) {
  return /*#__PURE__*/React.createElement(TweakRow, {
    label: label
  }, /*#__PURE__*/React.createElement("input", {
    className: "twk-field",
    type: "text",
    value: value,
    placeholder: placeholder,
    onChange: e => onChange(e.target.value)
  }));
}
function TweakNumber({
  label,
  value,
  min,
  max,
  step = 1,
  unit = '',
  onChange
}) {
  const clamp = n => {
    if (min != null && n < min) return min;
    if (max != null && n > max) return max;
    return n;
  };
  const startRef = React.useRef({
    x: 0,
    val: 0
  });
  const onScrubStart = e => {
    e.preventDefault();
    startRef.current = {
      x: e.clientX,
      val: value
    };
    const decimals = (String(step).split('.')[1] || '').length;
    const move = ev => {
      const dx = ev.clientX - startRef.current.x;
      const raw = startRef.current.val + dx * step;
      const snapped = Math.round(raw / step) * step;
      onChange(clamp(Number(snapped.toFixed(decimals))));
    };
    const up = () => {
      window.removeEventListener('pointermove', move);
      window.removeEventListener('pointerup', up);
    };
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', up);
  };
  return /*#__PURE__*/React.createElement("div", {
    className: "twk-num"
  }, /*#__PURE__*/React.createElement("span", {
    className: "twk-num-lbl",
    onPointerDown: onScrubStart
  }, label), /*#__PURE__*/React.createElement("input", {
    type: "number",
    value: value,
    min: min,
    max: max,
    step: step,
    onChange: e => onChange(clamp(Number(e.target.value)))
  }), unit && /*#__PURE__*/React.createElement("span", {
    className: "twk-num-unit"
  }, unit));
}

// Relative-luminance contrast pick — checkmarks drawn over a swatch need to
// read on both #111 and #fafafa without per-option configuration. Hex input
// only (#rgb / #rrggbb); named or rgb()/hsl() colors fall through to "light".
function __twkIsLight(hex) {
  const h = String(hex).replace('#', '');
  const x = h.length === 3 ? h.replace(/./g, c => c + c) : h.padEnd(6, '0');
  const n = parseInt(x.slice(0, 6), 16);
  if (Number.isNaN(n)) return true;
  const r = n >> 16 & 255,
    g = n >> 8 & 255,
    b = n & 255;
  return r * 299 + g * 587 + b * 114 > 148000;
}
const __TwkCheck = ({
  light
}) => /*#__PURE__*/React.createElement("svg", {
  viewBox: "0 0 14 14",
  "aria-hidden": "true"
}, /*#__PURE__*/React.createElement("path", {
  d: "M3 7.2 5.8 10 11 4.2",
  fill: "none",
  strokeWidth: "2.2",
  strokeLinecap: "round",
  strokeLinejoin: "round",
  stroke: light ? 'rgba(0,0,0,.78)' : '#fff'
}));

// TweakColor — curated color/palette picker. Each option is either a single
// hex string or an array of 1-5 hex strings; the card adapts — a lone color
// renders solid, a palette renders colors[0] as the hero (left ~2/3) with the
// rest stacked in a sharp column on the right. onChange emits the
// option in the shape it was passed (string stays string, array stays array).
// Without options it falls back to the native color input for back-compat.
function TweakColor({
  label,
  value,
  options,
  onChange
}) {
  if (!options || !options.length) {
    return /*#__PURE__*/React.createElement("div", {
      className: "twk-row twk-row-h"
    }, /*#__PURE__*/React.createElement("div", {
      className: "twk-lbl"
    }, /*#__PURE__*/React.createElement("span", null, label)), /*#__PURE__*/React.createElement("input", {
      type: "color",
      className: "twk-swatch",
      value: value,
      onChange: e => onChange(e.target.value)
    }));
  }
  // Native <input type=color> emits lowercase hex per the HTML spec, so
  // compare case-insensitively. String() guards JSON.stringify(undefined),
  // which returns the primitive undefined (no .toLowerCase).
  const key = o => String(JSON.stringify(o)).toLowerCase();
  const cur = key(value);
  return /*#__PURE__*/React.createElement(TweakRow, {
    label: label
  }, /*#__PURE__*/React.createElement("div", {
    className: "twk-chips",
    role: "radiogroup"
  }, options.map((o, i) => {
    const colors = Array.isArray(o) ? o : [o];
    const [hero, ...rest] = colors;
    const sup = rest.slice(0, 4);
    const on = key(o) === cur;
    return /*#__PURE__*/React.createElement("button", {
      key: i,
      type: "button",
      className: "twk-chip",
      role: "radio",
      "aria-checked": on,
      "data-on": on ? '1' : '0',
      "aria-label": colors.join(', '),
      title: colors.join(' · '),
      style: {
        background: hero
      },
      onClick: () => onChange(o)
    }, sup.length > 0 && /*#__PURE__*/React.createElement("span", null, sup.map((c, j) => /*#__PURE__*/React.createElement("i", {
      key: j,
      style: {
        background: c
      }
    }))), on && /*#__PURE__*/React.createElement(__TwkCheck, {
      light: __twkIsLight(hero)
    }));
  })));
}
function TweakButton({
  label,
  onClick,
  secondary = false
}) {
  return /*#__PURE__*/React.createElement("button", {
    type: "button",
    className: secondary ? 'twk-btn secondary' : 'twk-btn',
    onClick: onClick
  }, label);
}
Object.assign(window, {
  useTweaks,
  TweaksPanel,
  TweakSection,
  TweakRow,
  TweakSlider,
  TweakToggle,
  TweakRadio,
  TweakSelect,
  TweakText,
  TweakNumber,
  TweakColor,
  TweakButton
});
})(); } catch (e) { __ds_ns.__errors.push({ path: "exports/mgcrm-package/design-handoff/redesign/tweaks-panel.jsx", error: String((e && e.message) || e) }); }

// exports/mgcrm-package/design-handoff/redesign/users-section.jsx
try { (() => {
/* Users section for Настройки → Система → Пользователи
   Mirrors front/src/pages/UsersPage (index.vue + composable + dialogs, ru.json),
   redesigned to the fresh MACRO CRM look. Exposes window.UsersTab. */
(function () {
  const {
    useState,
    useRef,
    useEffect
  } = React;
  const U_T = {
    title: 'Пользователи',
    subtitle: 'Управление учётными записями сотрудников',
    addUser: 'Добавить пользователя',
    editUser: 'Редактировать пользователя',
    active: 'Активен',
    inactive: 'Неактивен',
    passwordHint: 'Если не задать пароль, он будет сгенерирован автоматически. Сообщите учётные данные сотруднику.',
    fields: {
      full_name: 'ФИО',
      full_name_ph: 'Иванов Иван Иванович',
      email: 'Email',
      email_ph: 'user@company.com',
      phone: 'Телефон',
      phone_ph: '+7 (999) 000-00-00',
      job_title: 'Должность',
      job_title_ph: 'Менеджер по продажам',
      department: 'Отдел',
      department_ph: 'Выберите отдел',
      manager: 'Руководитель',
      manager_ph: 'Выберите руководителя',
      role: 'Роль',
      role_ph: 'Выберите роль (по умолчанию: Менеджер)',
      password: 'Пароль',
      password_ph: 'Оставьте пустым для автогенерации',
      password_edit_ph: 'Оставьте пустым, чтобы не менять'
    },
    filters: {
      search: 'Поиск по имени, email…',
      role: 'Роль',
      department: 'Отдел',
      status: 'Статус',
      active: 'Активен',
      inactive: 'Неактивен'
    },
    deactivate: 'Деактивировать',
    deactivateTitle: 'Деактивация пользователя',
    deactivateConfirm: n => 'Деактивировать пользователя «' + n + '»? Он не сможет войти в систему, но останется в истории.',
    activate: 'Активировать',
    reset: {
      action: 'Сбросить пароль',
      title: 'Сброс пароля',
      confirm: n => 'Сбросить пароль пользователя «' + n + '»? Текущий пароль перестанет работать, будет сгенерирован новый.',
      resultTitle: 'Новый пароль сгенерирован',
      oneTimeWarning: 'Сохраните пароль — он показывается только один раз. Передайте его пользователю по защищённому каналу.',
      newPassword: 'Новый пароль',
      copy: 'Копировать пароль'
    }
  };
  const U_ROLE_LABEL = {
    admin: 'Администратор',
    director: 'Директор',
    manager: 'Менеджер',
    lawyer: 'Юрист',
    accountant: 'Бухгалтер',
    cfo: 'Финансовый директор'
  };
  const U_ROLE_SEV = {
    admin: 'danger',
    director: 'warn',
    lawyer: 'info',
    cfo: 'warn',
    manager: 'secondary',
    accountant: 'secondary'
  };
  const U_ROLE_OPTS = ['admin', 'director', 'manager', 'lawyer', 'accountant', 'cfo'].map(v => ({
    value: v,
    label: U_ROLE_LABEL[v]
  }));
  const U_ROLE_GRAD = {
    admin: 'linear-gradient(135deg,#4C7DF0,#2B4987)',
    director: 'linear-gradient(135deg,#E6A46E,#C67E3C)',
    cfo: 'linear-gradient(135deg,#E6A46E,#C67E3C)',
    lawyer: 'linear-gradient(135deg,#78B0E0,#4A82BC)',
    manager: 'linear-gradient(135deg,#4FBF8F,#2E9469)',
    accountant: 'linear-gradient(135deg,#9AA3B2,#6C7688)'
  };
  const U_DEPTS = ['Отдел продаж', 'Бухгалтерия', 'Финансы', 'Юридический отдел', 'Руководство'].map((n, i) => ({
    id: i + 1,
    name: n
  }));
  const U_ME = 2;
  const U_USERS = [{
    id: 1,
    full_name: 'Admin',
    email: 'svkv42@gmail.com',
    phone: '+7 (495) 120-33-01',
    job_title: null,
    department_name: null,
    role: 'admin',
    is_active: true
  }, {
    id: 2,
    full_name: 'Bogdan Yadykin',
    email: 'b.yadykin@macroglobaltech.com',
    phone: '+7 (495) 120-33-02',
    job_title: null,
    department_name: null,
    role: 'admin',
    is_active: true
  }, {
    id: 3,
    full_name: 'Lawyer Test',
    email: 'lawyer@mgcrm.test',
    phone: '+7 (495) 120-33-03',
    job_title: null,
    department_name: null,
    role: 'lawyer',
    is_active: true
  }, {
    id: 4,
    full_name: 'MG CRM Admin',
    email: 'admin@mgcrm.test',
    phone: '+7 (495) 120-33-04',
    job_title: null,
    department_name: null,
    role: 'admin',
    is_active: true
  }, {
    id: 5,
    full_name: 'Георгий Некрасов',
    email: 'g.nekrasov@macroglobaltech.com',
    phone: '+7 (701) 555-21-05',
    job_title: 'Менеджер по продажам',
    department_name: 'Отдел продаж',
    role: 'manager',
    is_active: true
  }, {
    id: 6,
    full_name: 'Директор Петров П.П.',
    email: 'director@mgcrm.test',
    phone: '+7 (701) 555-21-06',
    job_title: 'Директор по продажам',
    department_name: 'Отдел продаж',
    role: 'director',
    is_active: true
  }, {
    id: 7,
    full_name: 'Иванов Алексей Сергеевич',
    email: 'manager1@mgcrm.test',
    phone: '+7 (701) 555-21-07',
    job_title: 'Менеджер по продажам',
    department_name: 'Отдел продаж',
    role: 'manager',
    is_active: true
  }, {
    id: 8,
    full_name: 'Илья Рогов',
    email: 'ilyarogov.mera@gmail.com',
    phone: '+7 (701) 555-21-08',
    job_title: 'Менеджер по продажам',
    department_name: 'Отдел продаж',
    role: 'manager',
    is_active: true
  }, {
    id: 9,
    full_name: 'Клим Федорин',
    email: 'k.fedorin@macroglobaltech.com',
    phone: '+7 (701) 555-21-09',
    job_title: 'Менеджер по продажам',
    department_name: 'Отдел продаж',
    role: 'manager',
    is_active: true
  }, {
    id: 10,
    full_name: 'Олеся Моисеева',
    email: 'o.moiseeva@macroglobaltech.com',
    phone: '+7 (701) 555-21-10',
    job_title: 'Менеджер по продажам',
    department_name: 'Отдел продаж',
    role: 'manager',
    is_active: true
  }, {
    id: 11,
    full_name: 'Петрова Мария Сергеевна',
    email: 'manager2@mgcrm.test',
    phone: '+7 (701) 555-21-11',
    job_title: 'Старший менеджер',
    department_name: 'Отдел продаж',
    role: 'manager',
    is_active: true
  }, {
    id: 12,
    full_name: 'Анна Счётова',
    email: 'accountant@mgcrm.test',
    phone: '+7 (701) 340-11-22',
    job_title: 'Главный бухгалтер',
    department_name: 'Бухгалтерия',
    role: 'accountant',
    is_active: true
  }, {
    id: 13,
    full_name: 'Сергей Казначеев',
    email: 'cfo@mgcrm.test',
    phone: '+7 (701) 555-21-13',
    job_title: 'Финансовый директор',
    department_name: 'Финансы',
    role: 'cfo',
    is_active: true
  }, {
    id: 14,
    full_name: 'Роман Уваров',
    email: 'roman.old@mgcrm.test',
    phone: '+7 (701) 555-21-14',
    job_title: 'Менеджер по продажам',
    department_name: 'Отдел продаж',
    role: 'manager',
    is_active: false
  }];
  const U_SEV = {
    success: {
      bg: 'var(--mg-status-success-bg)',
      fg: 'var(--mg-status-success-text)'
    },
    danger: {
      bg: 'var(--mg-status-danger-bg)',
      fg: 'var(--mg-status-danger-text)'
    },
    warn: {
      bg: 'var(--mg-status-warning-bg)',
      fg: 'var(--mg-status-warning-text)'
    },
    info: {
      bg: 'var(--mg-status-info-bg)',
      fg: 'var(--mg-status-info-text)'
    },
    secondary: {
      bg: 'var(--c-muted2)',
      fg: 'var(--c-text2)'
    }
  };
  const uInp = {
    width: '100%',
    height: 40,
    padding: '0 13px',
    borderRadius: 'var(--mg-radius-md)',
    border: '1px solid var(--mg-input-border)',
    background: 'var(--c-card)',
    color: 'var(--c-text)',
    fontFamily: 'var(--mg-font-sans)',
    fontSize: 14,
    boxSizing: 'border-box'
  };
  function uBtn(kind) {
    const base = {
      display: 'inline-flex',
      alignItems: 'center',
      gap: 7,
      height: 38,
      padding: '0 15px',
      borderRadius: 'var(--mg-radius-md)',
      fontFamily: 'var(--mg-font-sans)',
      fontSize: 13,
      fontWeight: 600,
      cursor: 'pointer',
      border: 'none',
      whiteSpace: 'nowrap'
    };
    if (kind === 'primary') return {
      ...base,
      background: 'var(--mg-primary-900)',
      color: '#fff'
    };
    if (kind === 'danger') return {
      ...base,
      background: 'var(--mg-status-danger-solid)',
      color: '#fff'
    };
    if (kind === 'warn') return {
      ...base,
      background: 'var(--mg-status-warning-solid)',
      color: '#3A2A12'
    };
    if (kind === 'text') return {
      ...base,
      background: 'transparent',
      color: 'var(--c-text2)'
    };
    return {
      ...base,
      background: 'transparent',
      color: 'var(--c-text2)',
      border: '1px solid var(--c-border2)'
    };
  }
  function uInitials(name) {
    const w = String(name).trim().split(/\s+/);
    return (w.length >= 2 ? w[0][0] + w[1][0] : w[0].slice(0, 2)).toUpperCase();
  }
  function UTag({
    value,
    severity
  }) {
    const c = U_SEV[severity] || U_SEV.secondary;
    return /*#__PURE__*/React.createElement("span", {
      style: {
        display: 'inline-flex',
        alignItems: 'center',
        fontSize: 11.5,
        fontWeight: 600,
        padding: '3px 9px',
        borderRadius: 6,
        background: c.bg,
        color: c.fg,
        whiteSpace: 'nowrap'
      }
    }, value);
  }
  function UStatus({
    active
  }) {
    const c = active ? U_SEV.success : U_SEV.secondary;
    return /*#__PURE__*/React.createElement("span", {
      style: {
        display: 'inline-flex',
        alignItems: 'center',
        gap: 6,
        fontSize: 12,
        fontWeight: 500,
        color: active ? 'var(--mg-status-success-text)' : 'var(--c-muted)'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        width: 7,
        height: 7,
        borderRadius: '50%',
        background: active ? 'var(--mg-status-success-solid, var(--mg-status-success-text))' : 'var(--c-border2)'
      }
    }), active ? U_T.active : U_T.inactive);
  }
  function UDropdown({
    value,
    onChange,
    options,
    placeholder,
    width,
    filter
  }) {
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const ref = useRef(null);
    useEffect(() => {
      const h = e => {
        if (ref.current && !ref.current.contains(e.target)) setOpen(false);
      };
      document.addEventListener('mousedown', h);
      return () => document.removeEventListener('mousedown', h);
    }, []);
    const sel = options.find(o => o.value === value);
    const list = filter && q ? options.filter(o => o.label.toLowerCase().includes(q.toLowerCase())) : options;
    return /*#__PURE__*/React.createElement("div", {
      ref: ref,
      style: {
        position: 'relative',
        width: width || '100%'
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: () => setOpen(!open),
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        height: 40,
        padding: '0 12px',
        border: '1px solid ' + (open ? 'var(--mg-input-focus-border)' : 'var(--mg-input-border)'),
        borderRadius: 'var(--mg-radius-md)',
        background: 'var(--c-card)',
        cursor: 'pointer',
        boxShadow: open ? 'var(--mg-focus-ring)' : 'none'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 14,
        color: sel ? 'var(--c-text)' : 'var(--c-muted)',
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, sel ? sel.label : placeholder), sel && /*#__PURE__*/React.createElement("i", {
      className: "pi pi-times",
      onClick: e => {
        e.stopPropagation();
        onChange(null);
        setOpen(false);
      },
      style: {
        fontSize: 12,
        color: 'var(--c-muted)',
        cursor: 'pointer'
      }
    }), /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + (open ? 'pi-chevron-up' : 'pi-chevron-down'),
      style: {
        fontSize: 12,
        color: 'var(--c-muted)'
      }
    })), open && /*#__PURE__*/React.createElement("div", {
      style: {
        position: 'absolute',
        top: 44,
        insetInlineStart: 0,
        zIndex: 40,
        width: '100%',
        minWidth: 180,
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-md)',
        boxShadow: 'var(--mg-shadow-lg)',
        padding: 5,
        maxHeight: 280,
        overflowY: 'auto'
      }
    }, filter && /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 7,
        height: 34,
        padding: '0 10px',
        margin: '2px 2px 6px',
        border: '1px solid var(--c-border2)',
        borderRadius: 'var(--mg-radius-sm)',
        background: 'var(--c-page)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-search",
      style: {
        fontSize: 12,
        color: 'var(--c-muted)'
      }
    }), /*#__PURE__*/React.createElement("input", {
      autoFocus: true,
      value: q,
      onChange: e => setQ(e.target.value),
      placeholder: "\u041F\u043E\u0438\u0441\u043A\u2026",
      style: {
        flex: 1,
        border: 'none',
        outline: 'none',
        background: 'transparent',
        fontFamily: 'var(--mg-font-sans)',
        fontSize: 13,
        color: 'var(--c-text)'
      }
    })), list.map(o => {
      const on = o.value === value;
      return /*#__PURE__*/React.createElement("div", {
        key: String(o.value),
        onClick: () => {
          onChange(o.value);
          setOpen(false);
          setQ('');
        },
        style: {
          display: 'flex',
          alignItems: 'center',
          gap: 8,
          padding: '8px 10px',
          borderRadius: 7,
          cursor: 'pointer',
          fontSize: 13.5,
          fontWeight: on ? 600 : 500,
          color: on ? 'var(--mg-primary-900)' : 'var(--c-text)',
          background: on ? 'var(--mg-primary-100)' : 'transparent'
        }
      }, o.label, on && /*#__PURE__*/React.createElement("i", {
        className: "pi pi-check",
        style: {
          marginInlineStart: 'auto',
          fontSize: 12
        }
      }));
    }), list.length === 0 && /*#__PURE__*/React.createElement("div", {
      style: {
        padding: '8px 10px',
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }, "\u041D\u0438\u0447\u0435\u0433\u043E \u043D\u0435 \u043D\u0430\u0439\u0434\u0435\u043D\u043E")));
  }
  function UModal({
    title,
    close,
    footer,
    width,
    children
  }) {
    return /*#__PURE__*/React.createElement("div", {
      onClick: close,
      style: {
        position: 'fixed',
        inset: 0,
        zIndex: 1300,
        background: 'rgba(9,16,32,0.55)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: 20
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: e => e.stopPropagation(),
      style: {
        width: '100%',
        maxWidth: width || 544,
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-lg)',
        overflow: 'hidden',
        maxHeight: 'calc(100vh - 40px)',
        display: 'flex',
        flexDirection: 'column'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '16px 20px',
        borderBottom: '1px solid var(--c-border)',
        flexShrink: 0
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 16,
        fontWeight: 600,
        color: 'var(--c-text)'
      }
    }, title), /*#__PURE__*/React.createElement("i", {
      className: "pi pi-times",
      onClick: close,
      style: {
        fontSize: 14,
        color: 'var(--c-muted)',
        cursor: 'pointer'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        padding: 20,
        overflowY: 'auto'
      }
    }, children), footer && /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'flex-end',
        gap: 10,
        padding: '14px 20px',
        borderTop: '1px solid var(--c-border)',
        flexShrink: 0
      }
    }, footer)));
  }
  function ULabel({
    children
  }) {
    return /*#__PURE__*/React.createElement("label", {
      style: {
        display: 'block',
        fontSize: 13,
        fontWeight: 500,
        color: 'var(--c-text)',
        marginBottom: 6
      }
    }, children);
  }
  function UUserDialog({
    editing,
    close,
    onSave
  }) {
    const isEdit = !!editing;
    const [f, setF] = useState(editing ? {
      full_name: editing.full_name,
      email: editing.email,
      phone: editing.phone || '',
      job_title: editing.job_title || '',
      department_id: (U_DEPTS.find(d => d.name === editing.department_name) || {}).id ?? null,
      manager_id: null,
      role: editing.role,
      password: ''
    } : {
      full_name: '',
      email: '',
      phone: '',
      job_title: '',
      department_id: null,
      manager_id: null,
      role: null,
      password: ''
    });
    const [err, setErr] = useState({});
    const [showPw, setShowPw] = useState(false);
    const set = (k, v) => setF(s => ({
      ...s,
      [k]: v
    }));
    const managers = U_USERS.filter(u => u.id !== (editing || {}).id).map(u => ({
      value: u.id,
      label: u.full_name
    }));
    function submit() {
      const e = {};
      if (!f.full_name.trim()) e.full_name = 'Обязательное поле';
      if (!f.email.trim()) e.email = 'Обязательное поле';else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(f.email.trim())) e.email = 'Некорректный email';
      if (f.password && f.password.length < 8) e.password = 'Минимум 8 символов';
      setErr(e);
      if (Object.keys(e).length) return;
      onSave(f, isEdit);
    }
    const half = {
      flex: 1,
      minWidth: 0
    };
    return /*#__PURE__*/React.createElement(UModal, {
      title: isEdit ? U_T.editUser : U_T.addUser,
      close: close,
      width: 544,
      footer: /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("button", {
        style: uBtn('text'),
        onClick: close
      }, "\u041E\u0442\u043C\u0435\u043D\u0430"), /*#__PURE__*/React.createElement("button", {
        style: uBtn('primary'),
        onClick: submit
      }, isEdit ? 'Сохранить' : 'Создать'))
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        gap: 14
      }
    }, /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement(ULabel, null, U_T.fields.full_name, " *"), /*#__PURE__*/React.createElement("input", {
      className: "inp",
      autoFocus: true,
      value: f.full_name,
      onChange: e => set('full_name', e.target.value),
      placeholder: U_T.fields.full_name_ph,
      style: {
        ...uInp,
        borderColor: err.full_name ? 'var(--mg-status-danger-border)' : 'var(--mg-input-border)'
      }
    }), err.full_name && /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 12,
        color: 'var(--mg-status-danger-text)',
        marginTop: 5
      }
    }, err.full_name)), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement(ULabel, null, U_T.fields.email, " *"), /*#__PURE__*/React.createElement("input", {
      className: "inp",
      type: "email",
      value: f.email,
      onChange: e => set('email', e.target.value),
      placeholder: U_T.fields.email_ph,
      style: {
        ...uInp,
        borderColor: err.email ? 'var(--mg-status-danger-border)' : 'var(--mg-input-border)'
      }
    }), err.email && /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 12,
        color: 'var(--mg-status-danger-text)',
        marginTop: 5
      }
    }, err.email)), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 14
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: half
    }, /*#__PURE__*/React.createElement(ULabel, null, U_T.fields.phone), /*#__PURE__*/React.createElement("input", {
      className: "inp",
      value: f.phone,
      onChange: e => set('phone', e.target.value),
      placeholder: U_T.fields.phone_ph,
      style: uInp
    })), /*#__PURE__*/React.createElement("div", {
      style: half
    }, /*#__PURE__*/React.createElement(ULabel, null, U_T.fields.job_title), /*#__PURE__*/React.createElement("input", {
      className: "inp",
      value: f.job_title,
      onChange: e => set('job_title', e.target.value),
      placeholder: U_T.fields.job_title_ph,
      style: uInp
    }))), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 14
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: half
    }, /*#__PURE__*/React.createElement(ULabel, null, U_T.fields.department), /*#__PURE__*/React.createElement(UDropdown, {
      value: f.department_id,
      onChange: v => set('department_id', v),
      options: U_DEPTS.map(d => ({
        value: d.id,
        label: d.name
      })),
      placeholder: U_T.fields.department_ph
    })), /*#__PURE__*/React.createElement("div", {
      style: half
    }, /*#__PURE__*/React.createElement(ULabel, null, U_T.fields.role), /*#__PURE__*/React.createElement(UDropdown, {
      value: f.role,
      onChange: v => set('role', v),
      options: U_ROLE_OPTS,
      placeholder: U_T.fields.role_ph
    }))), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement(ULabel, null, U_T.fields.manager), /*#__PURE__*/React.createElement(UDropdown, {
      value: f.manager_id,
      onChange: v => set('manager_id', v),
      options: managers,
      placeholder: U_T.fields.manager_ph,
      filter: true
    })), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement(ULabel, null, U_T.fields.password), /*#__PURE__*/React.createElement("div", {
      style: {
        position: 'relative'
      }
    }, /*#__PURE__*/React.createElement("input", {
      className: "inp",
      type: showPw ? 'text' : 'password',
      value: f.password,
      onChange: e => set('password', e.target.value),
      placeholder: isEdit ? U_T.fields.password_edit_ph : U_T.fields.password_ph,
      style: {
        ...uInp,
        paddingInlineEnd: 42,
        borderColor: err.password ? 'var(--mg-status-danger-border)' : 'var(--mg-input-border)'
      }
    }), /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + (showPw ? 'pi-eye-slash' : 'pi-eye'),
      onClick: () => setShowPw(!showPw),
      style: {
        position: 'absolute',
        insetInlineEnd: 13,
        top: 13,
        fontSize: 15,
        color: 'var(--c-muted)',
        cursor: 'pointer'
      }
    })), err.password && /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 12,
        color: 'var(--mg-status-danger-text)',
        marginTop: 5
      }
    }, err.password)), !isEdit && /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 9,
        alignItems: 'flex-start',
        padding: '11px 13px',
        background: 'var(--c-hover)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-md)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-info-circle",
      style: {
        fontSize: 14,
        color: 'var(--c-muted)',
        marginTop: 1
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 12.5,
        color: 'var(--c-text2)',
        lineHeight: 1.5
      }
    }, U_T.passwordHint))));
  }
  function UConfirm({
    header,
    icon,
    iconColor,
    message,
    acceptLabel,
    acceptKind,
    onAccept,
    close
  }) {
    return /*#__PURE__*/React.createElement(UModal, {
      title: header,
      close: close,
      width: 430,
      footer: /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("button", {
        style: uBtn('ghost'),
        onClick: close
      }, "\u041E\u0442\u043C\u0435\u043D\u0430"), /*#__PURE__*/React.createElement("button", {
        style: uBtn(acceptKind),
        onClick: onAccept
      }, acceptLabel))
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 14,
        alignItems: 'flex-start'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        width: 40,
        height: 40,
        borderRadius: '50%',
        background: U_SEV[acceptKind === 'danger' ? 'danger' : 'warn'].bg,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        flexShrink: 0
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + icon,
      style: {
        fontSize: 17,
        color: iconColor
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 14,
        color: 'var(--c-text2)',
        lineHeight: 1.55,
        paddingTop: 3
      }
    }, message)));
  }
  function UResetResult({
    password,
    close
  }) {
    const [copied, setCopied] = useState(false);
    return /*#__PURE__*/React.createElement(UModal, {
      title: U_T.reset.resultTitle,
      close: close,
      width: 448,
      footer: /*#__PURE__*/React.createElement("button", {
        style: uBtn('ghost'),
        onClick: close
      }, "\u0417\u0430\u043A\u0440\u044B\u0442\u044C")
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'flex-start',
        gap: 10,
        padding: 13,
        marginBottom: 18,
        background: 'var(--mg-status-warning-bg)',
        border: '1px solid var(--mg-status-warning-border)',
        borderRadius: 'var(--mg-radius-md)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-exclamation-triangle",
      style: {
        fontSize: 15,
        color: 'var(--mg-status-warning-solid)',
        marginTop: 1,
        flexShrink: 0
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 13,
        color: 'var(--mg-status-warning-text)',
        lineHeight: 1.5
      }
    }, U_T.reset.oneTimeWarning)), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        gap: 8
      }
    }, /*#__PURE__*/React.createElement("label", {
      style: {
        fontSize: 13,
        fontWeight: 500,
        color: 'var(--c-text)'
      }
    }, U_T.reset.newPassword), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        padding: '8px 12px',
        background: 'var(--c-hover)',
        border: '1px solid var(--c-border2)',
        borderRadius: 'var(--mg-radius-md)'
      }
    }, /*#__PURE__*/React.createElement("code", {
      style: {
        flex: 1,
        fontFamily: 'ui-monospace,monospace',
        fontSize: 15,
        fontWeight: 500,
        color: 'var(--c-text)',
        letterSpacing: '0.04em',
        wordBreak: 'break-all'
      }
    }, password), /*#__PURE__*/React.createElement("button", {
      onClick: () => {
        navigator.clipboard && navigator.clipboard.writeText(password);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
      },
      title: U_T.reset.copy,
      style: {
        width: 32,
        height: 32,
        borderRadius: '50%',
        border: 'none',
        background: 'transparent',
        cursor: 'pointer',
        display: 'inline-flex',
        alignItems: 'center',
        justifyContent: 'center'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + (copied ? 'pi-check' : 'pi-copy'),
      style: {
        fontSize: 14,
        color: copied ? 'var(--mg-status-success-text)' : 'var(--c-muted)'
      }
    })))));
  }
  function UToasts({
    items
  }) {
    return /*#__PURE__*/React.createElement("div", {
      style: {
        position: 'fixed',
        insetBlockEnd: 20,
        insetInlineEnd: 20,
        zIndex: 2000,
        display: 'flex',
        flexDirection: 'column',
        gap: 10
      }
    }, items.map(t => {
      const c = U_SEV[t.severity] || U_SEV.success;
      return /*#__PURE__*/React.createElement("div", {
        key: t.id,
        style: {
          display: 'flex',
          alignItems: 'center',
          gap: 10,
          minWidth: 260,
          maxWidth: 360,
          padding: '12px 14px',
          background: 'var(--c-card)',
          border: '1px solid var(--c-border)',
          borderInlineStart: '3px solid ' + c.fg,
          borderRadius: 'var(--mg-radius-md)',
          boxShadow: 'var(--mg-shadow-lg)',
          animation: 'toastIn .2s ease'
        }
      }, /*#__PURE__*/React.createElement("i", {
        className: "pi pi-check-circle",
        style: {
          fontSize: 16,
          color: c.fg
        }
      }), /*#__PURE__*/React.createElement("span", {
        style: {
          fontSize: 13.5,
          fontWeight: 500,
          color: 'var(--c-text)'
        }
      }, t.summary));
    }));
  }
  function UIconBtn({
    icon,
    color,
    title,
    onClick
  }) {
    return /*#__PURE__*/React.createElement("button", {
      className: "uicon",
      onClick: onClick,
      title: title,
      "aria-label": title,
      style: {
        width: 32,
        height: 32,
        borderRadius: 8,
        border: 'none',
        background: 'transparent',
        cursor: 'pointer',
        display: 'inline-flex',
        alignItems: 'center',
        justifyContent: 'center'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + icon,
      style: {
        fontSize: 14,
        color
      }
    }));
  }
  function UsersTab() {
    const [rows, setRows] = useState(U_USERS);
    const [search, setSearch] = useState('');
    const [roleF, setRoleF] = useState(null);
    const [deptF, setDeptF] = useState(null);
    const [activeF, setActiveF] = useState(null);
    const [dialog, setDialog] = useState(null);
    const [toasts, setToasts] = useState([]);
    const [editing, setEditing] = useState(false);
    const pushToast = summary => {
      const id = Date.now() + Math.random();
      setToasts(t => [...t, {
        id,
        summary,
        severity: 'success'
      }]);
      setTimeout(() => setToasts(t => t.filter(x => x.id !== id)), 2600);
    };
    const th = {
      padding: '11px 16px',
      fontSize: 11.5,
      fontWeight: 600,
      letterSpacing: '.03em',
      textTransform: 'uppercase',
      color: 'var(--c-muted)',
      textAlign: 'start',
      borderBottom: '1px solid var(--c-border)',
      whiteSpace: 'nowrap'
    };
    const td = {
      padding: '10px 16px',
      fontSize: 13.5,
      color: 'var(--c-text)',
      borderBottom: '1px solid var(--c-border)',
      verticalAlign: 'middle'
    };
    const filtered = rows.filter(u => {
      if (search.trim()) {
        const q = search.trim().toLowerCase();
        if (!(u.full_name.toLowerCase().includes(q) || u.email.toLowerCase().includes(q))) return false;
      }
      if (roleF && u.role !== roleF) return false;
      if (deptF !== null && (U_DEPTS.find(d => d.id === deptF) || {}).name !== u.department_name) return false;
      return true;
    });
    const active = filtered.filter(u => u.is_active);
    const inactive = filtered.filter(u => !u.is_active);
    const activeCount = rows.filter(u => u.is_active).length;
    function saveUser(form, isEdit) {
      if (isEdit) {
        setRows(rs => rs.map(u => u.id === dialog.editing.id ? {
          ...u,
          full_name: form.full_name.trim(),
          email: form.email.trim(),
          phone: form.phone.trim() || null,
          job_title: form.job_title.trim() || null,
          department_name: (U_DEPTS.find(d => d.id === form.department_id) || {}).name ?? null,
          role: form.role
        } : u));
        pushToast('Изменения сохранены');
      } else {
        const id = Math.max(...rows.map(r => r.id)) + 1;
        setRows(rs => [...rs, {
          id,
          full_name: form.full_name.trim(),
          email: form.email.trim(),
          phone: form.phone.trim() || null,
          job_title: form.job_title.trim() || null,
          department_name: (U_DEPTS.find(d => d.id === form.department_id) || {}).name ?? null,
          role: form.role || 'manager',
          is_active: true
        }]);
        pushToast('Пользователь создан');
      }
      setDialog(null);
    }
    function doDeactivate(u) {
      setRows(rs => rs.map(x => x.id === u.id ? {
        ...x,
        is_active: false
      } : x));
      pushToast('Пользователь деактивирован');
      setDialog(null);
    }
    function doReactivate(u) {
      setRows(rs => rs.map(x => x.id === u.id ? {
        ...x,
        is_active: true
      } : x));
      pushToast('Пользователь активирован');
    }
    function doReset() {
      setDialog({
        type: 'resetResult',
        pw: uGenPw()
      });
    }
    const columns = /*#__PURE__*/React.createElement("thead", null, /*#__PURE__*/React.createElement("tr", null, /*#__PURE__*/React.createElement("th", {
      style: th
    }, "\u0421\u043E\u0442\u0440\u0443\u0434\u043D\u0438\u043A"), /*#__PURE__*/React.createElement("th", {
      style: {
        ...th,
        width: 170
      }
    }, "\u0422\u0435\u043B\u0435\u0444\u043E\u043D"), /*#__PURE__*/React.createElement("th", {
      style: {
        ...th,
        width: 180
      }
    }, "\u0414\u043E\u043B\u0436\u043D\u043E\u0441\u0442\u044C"), /*#__PURE__*/React.createElement("th", {
      style: {
        ...th,
        width: 150
      }
    }, "\u041E\u0442\u0434\u0435\u043B"), /*#__PURE__*/React.createElement("th", {
      style: {
        ...th,
        width: 150
      }
    }, "\u0420\u043E\u043B\u044C"), editing && /*#__PURE__*/React.createElement("th", {
      style: {
        ...th,
        width: 120,
        textAlign: 'end'
      }
    }, "\u0414\u0435\u0439\u0441\u0442\u0432\u0438\u044F")));
    const renderRow = (u, archive) => /*#__PURE__*/React.createElement("tr", {
      key: u.id,
      className: "urow",
      style: {
        transition: 'background .12s',
        opacity: archive ? 0.72 : 1
      }
    }, /*#__PURE__*/React.createElement("td", {
      style: td
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 11
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        width: 36,
        height: 36,
        borderRadius: '50%',
        flexShrink: 0,
        background: archive ? 'var(--c-muted2)' : U_ROLE_GRAD[u.role] || U_ROLE_GRAD.accountant,
        color: archive ? 'var(--c-muted)' : '#fff',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        fontSize: 12.5,
        fontWeight: 600,
        letterSpacing: '.02em'
      }
    }, uInitials(u.full_name)), /*#__PURE__*/React.createElement("div", {
      style: {
        minWidth: 0
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        fontWeight: 600,
        fontSize: 13.5,
        color: 'var(--c-text)',
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, u.full_name), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 12,
        color: 'var(--c-muted)',
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, u.email)))), /*#__PURE__*/React.createElement("td", {
      style: {
        ...td,
        color: u.phone ? 'var(--c-text2)' : 'var(--c-muted)',
        whiteSpace: 'nowrap',
        fontVariantNumeric: 'tabular-nums'
      }
    }, u.phone ?? '—'), /*#__PURE__*/React.createElement("td", {
      style: {
        ...td,
        color: u.job_title ? 'var(--c-text2)' : 'var(--c-muted)'
      }
    }, u.job_title ?? '—'), /*#__PURE__*/React.createElement("td", {
      style: {
        ...td,
        color: u.department_name ? 'var(--c-text2)' : 'var(--c-muted)'
      }
    }, u.department_name ?? '—'), /*#__PURE__*/React.createElement("td", {
      style: td
    }, u.role ? /*#__PURE__*/React.createElement(UTag, {
      value: U_ROLE_LABEL[u.role],
      severity: U_ROLE_SEV[u.role]
    }) : /*#__PURE__*/React.createElement("span", {
      style: {
        color: 'var(--c-muted)'
      }
    }, "\u2014")), editing && /*#__PURE__*/React.createElement("td", {
      style: td
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 2,
        justifyContent: 'flex-end'
      }
    }, /*#__PURE__*/React.createElement(UIconBtn, {
      icon: "pi-pencil",
      color: "var(--c-text2)",
      title: "\u0420\u0435\u0434\u0430\u043A\u0442\u0438\u0440\u043E\u0432\u0430\u0442\u044C",
      onClick: () => setDialog({
        type: 'form',
        editing: u
      })
    }), u.id !== U_ME && /*#__PURE__*/React.createElement(UIconBtn, {
      icon: "pi-key",
      color: "var(--c-text2)",
      title: U_T.reset.action,
      onClick: () => setDialog({
        type: 'confirm',
        kind: 'reset',
        user: u
      })
    }), archive ? /*#__PURE__*/React.createElement(UIconBtn, {
      icon: "pi-replay",
      color: "var(--mg-status-success-text)",
      title: U_T.activate,
      onClick: () => doReactivate(u)
    }) : /*#__PURE__*/React.createElement(UIconBtn, {
      icon: "pi-ban",
      color: "var(--mg-status-danger-text)",
      title: U_T.deactivate,
      onClick: () => setDialog({
        type: 'confirm',
        kind: 'deactivate',
        user: u
      })
    }))));
    return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'flex-start',
        gap: 16,
        marginBottom: 20
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        flex: 1,
        minWidth: 0
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10
      }
    }, /*#__PURE__*/React.createElement("h2", {
      style: {
        margin: 0,
        fontSize: 20,
        fontWeight: 600
      }
    }, U_T.title), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 12,
        fontWeight: 600,
        padding: '2px 9px',
        borderRadius: 999,
        background: 'var(--mg-primary-100)',
        color: 'var(--mg-primary-900)'
      }
    }, rows.length)), /*#__PURE__*/React.createElement("p", {
      style: {
        margin: '5px 0 0',
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }, U_T.subtitle, " \xB7 ", activeCount, " \u0430\u043A\u0442\u0438\u0432\u043D\u044B\u0445")), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 10,
        flexShrink: 0
      }
    }, /*#__PURE__*/React.createElement("button", {
      style: editing ? {
        ...uBtn('ghost'),
        color: 'var(--mg-primary-900)',
        borderColor: 'var(--mg-primary-900)'
      } : uBtn('ghost'),
      onClick: () => setEditing(e => !e)
    }, /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + (editing ? 'pi-check' : 'pi-pencil'),
      style: {
        fontSize: 12
      }
    }), editing ? 'Завершить редактирование' : 'Редактировать'), /*#__PURE__*/React.createElement("button", {
      style: uBtn('primary'),
      onClick: () => setDialog({
        type: 'form',
        editing: null
      })
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-plus",
      style: {
        fontSize: 12
      }
    }), U_T.addUser))), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        flexWrap: 'wrap',
        marginBottom: 14
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        height: 40,
        padding: '0 12px',
        flex: 1,
        minWidth: 200,
        maxWidth: 320,
        border: '1px solid var(--mg-input-border)',
        borderRadius: 'var(--mg-radius-md)',
        background: 'var(--c-card)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-search",
      style: {
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }), /*#__PURE__*/React.createElement("input", {
      value: search,
      onChange: e => setSearch(e.target.value),
      placeholder: U_T.filters.search,
      style: {
        flex: 1,
        border: 'none',
        outline: 'none',
        background: 'transparent',
        fontFamily: 'var(--mg-font-sans)',
        fontSize: 14,
        color: 'var(--c-text)'
      }
    })), /*#__PURE__*/React.createElement(UDropdown, {
      value: roleF,
      onChange: setRoleF,
      options: U_ROLE_OPTS,
      placeholder: U_T.filters.role,
      width: 150
    }), /*#__PURE__*/React.createElement(UDropdown, {
      value: deptF,
      onChange: setDeptF,
      options: U_DEPTS.map(d => ({
        value: d.id,
        label: d.name
      })),
      placeholder: U_T.filters.department,
      width: 168
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-sm)',
        overflow: 'hidden'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        overflowX: 'auto'
      }
    }, /*#__PURE__*/React.createElement("table", {
      style: {
        width: '100%',
        borderCollapse: 'collapse',
        minWidth: 820
      }
    }, columns, /*#__PURE__*/React.createElement("tbody", null, active.map(u => renderRow(u, false))))), active.length === 0 && /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        gap: 12,
        padding: '52px 20px',
        color: 'var(--c-muted)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-users",
      style: {
        fontSize: 30,
        opacity: 0.3
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 14,
        color: 'var(--c-text2)'
      }
    }, "\u0410\u043A\u0442\u0438\u0432\u043D\u044B\u0435 \u043F\u043E\u043B\u044C\u0437\u043E\u0432\u0430\u0442\u0435\u043B\u0438 \u043D\u0435 \u043D\u0430\u0439\u0434\u0435\u043D\u044B"))), inactive.length > 0 && /*#__PURE__*/React.createElement("div", {
      style: {
        marginTop: 28
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 9,
        marginBottom: 12
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-user-minus",
      style: {
        fontSize: 14,
        color: 'var(--c-muted)'
      }
    }), /*#__PURE__*/React.createElement("h3", {
      style: {
        margin: 0,
        fontSize: 15,
        fontWeight: 600,
        color: 'var(--c-text2)'
      }
    }, "\u0423\u0432\u043E\u043B\u0435\u043D\u043D\u044B\u0435"), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 11.5,
        fontWeight: 600,
        padding: '2px 8px',
        borderRadius: 999,
        background: 'var(--c-muted2)',
        color: 'var(--c-text2)'
      }
    }, inactive.length)), /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-sm)',
        overflow: 'hidden'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        overflowX: 'auto'
      }
    }, /*#__PURE__*/React.createElement("table", {
      style: {
        width: '100%',
        borderCollapse: 'collapse',
        minWidth: 820
      }
    }, columns, /*#__PURE__*/React.createElement("tbody", null, inactive.map(u => renderRow(u, true))))))), dialog && dialog.type === 'form' && /*#__PURE__*/React.createElement(UUserDialog, {
      key: dialog.editing ? 'edit-' + dialog.editing.id : 'new',
      editing: dialog.editing,
      close: () => setDialog(null),
      onSave: saveUser
    }), dialog && dialog.type === 'confirm' && dialog.kind === 'deactivate' && /*#__PURE__*/React.createElement(UConfirm, {
      header: U_T.deactivateTitle,
      icon: "pi-exclamation-triangle",
      iconColor: "var(--mg-status-warning-solid)",
      message: U_T.deactivateConfirm(dialog.user.full_name),
      acceptLabel: U_T.deactivate,
      acceptKind: "danger",
      onAccept: () => doDeactivate(dialog.user),
      close: () => setDialog(null)
    }), dialog && dialog.type === 'confirm' && dialog.kind === 'reset' && /*#__PURE__*/React.createElement(UConfirm, {
      header: U_T.reset.title,
      icon: "pi-key",
      iconColor: "var(--mg-status-warning-solid)",
      message: U_T.reset.confirm(dialog.user.full_name),
      acceptLabel: U_T.reset.action,
      acceptKind: "warn",
      onAccept: doReset,
      close: () => setDialog(null)
    }), dialog && dialog.type === 'resetResult' && /*#__PURE__*/React.createElement(UResetResult, {
      password: dialog.pw,
      close: () => setDialog(null)
    }), /*#__PURE__*/React.createElement(UToasts, {
      items: toasts
    }));
  }
  function uGenPw() {
    const a = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    let s = '';
    for (let i = 0; i < 12; i++) s += a[Math.floor(Math.random() * a.length)];
    return s;
  }
  window.UsersTab = UsersTab;
})();
})(); } catch (e) { __ds_ns.__errors.push({ path: "exports/mgcrm-package/design-handoff/redesign/users-section.jsx", error: String((e && e.message) || e) }); }

// redesign/_backup_2026-07-04/tweaks-panel.jsx
try { (() => {
// @ds-adherence-ignore -- omelette starter scaffold (raw elements/hex/px by design)

/* BEGIN USAGE */
// tweaks-panel.jsx
// Reusable Tweaks shell + form-control helpers.
// Exports (to window): useTweaks, TweaksPanel, TweakSection, TweakRow, TweakSlider,
//   TweakToggle, TweakRadio, TweakSelect, TweakText, TweakNumber, TweakColor, TweakButton.
//
// Owns the host protocol (listens for __activate_edit_mode / __deactivate_edit_mode,
// posts __edit_mode_available / __edit_mode_set_keys / __edit_mode_dismissed) so
// individual prototypes don't re-roll it. Ships a consistent set of controls so you
// don't hand-draw <input type="range">, segmented radios, steppers, etc.
//
// Usage (in an HTML file that loads React + Babel):
//
//   const TWEAK_DEFAULTS = /*EDITMODE-BEGIN*/{
//     "primaryColor": "#D97757",
//     "palette": ["#D97757", "#29261b", "#f6f4ef"],
//     "fontSize": 16,
//     "density": "regular",
//     "dark": false
//   }/*EDITMODE-END*/;
//
//   function App() {
//     const [t, setTweak] = useTweaks(TWEAK_DEFAULTS);
//     return (
//       <div style={{ fontSize: t.fontSize, color: t.primaryColor }}>
//         Hello
//         <TweaksPanel>
//           <TweakSection label="Typography" />
//           <TweakSlider label="Font size" value={t.fontSize} min={10} max={32} unit="px"
//                        onChange={(v) => setTweak('fontSize', v)} />
//           <TweakRadio  label="Density" value={t.density}
//                        options={['compact', 'regular', 'comfy']}
//                        onChange={(v) => setTweak('density', v)} />
//           <TweakSection label="Theme" />
//           <TweakColor  label="Primary" value={t.primaryColor}
//                        options={['#D97757', '#2A6FDB', '#1F8A5B', '#7A5AE0']}
//                        onChange={(v) => setTweak('primaryColor', v)} />
//           <TweakColor  label="Palette" value={t.palette}
//                        options={[['#D97757', '#29261b', '#f6f4ef'],
//                                  ['#475569', '#0f172a', '#f1f5f9']]}
//                        onChange={(v) => setTweak('palette', v)} />
//           <TweakToggle label="Dark mode" value={t.dark}
//                        onChange={(v) => setTweak('dark', v)} />
//         </TweaksPanel>
//       </div>
//     );
//   }
//
// TweakRadio is the segmented control for 2–3 short options (auto-falls-back to
// TweakSelect past ~16/~10 chars per label); reach for TweakSelect directly when
// options are many or long. For color tweaks always curate 3-4 options rather than
// a free picker; an option can also be a whole 2–5 color palette (the stored value
// is the array). The Tweak* controls are a floor, not a ceiling — build custom
// controls inside the panel if a tweak calls for UI they don't cover.
/* END USAGE */
// ─────────────────────────────────────────────────────────────────────────────

const __TWEAKS_STYLE = `
  .twk-panel{position:fixed;right:16px;bottom:16px;z-index:2147483646;width:280px;
    max-height:calc(100vh - 32px);display:flex;flex-direction:column;
    transform:scale(var(--dc-inv-zoom,1));transform-origin:bottom right;
    background:rgba(250,249,247,.78);color:#29261b;
    -webkit-backdrop-filter:blur(24px) saturate(160%);backdrop-filter:blur(24px) saturate(160%);
    border:.5px solid rgba(255,255,255,.6);border-radius:14px;
    box-shadow:0 1px 0 rgba(255,255,255,.5) inset,0 12px 40px rgba(0,0,0,.18);
    font:11.5px/1.4 ui-sans-serif,system-ui,-apple-system,sans-serif;overflow:hidden}
  .twk-hd{display:flex;align-items:center;justify-content:space-between;
    padding:10px 8px 10px 14px;cursor:move;user-select:none}
  .twk-hd b{font-size:12px;font-weight:600;letter-spacing:.01em}
  .twk-x{appearance:none;border:0;background:transparent;color:rgba(41,38,27,.55);
    width:22px;height:22px;border-radius:6px;cursor:default;font-size:13px;line-height:1}
  .twk-x:hover{background:rgba(0,0,0,.06);color:#29261b}
  .twk-body{padding:2px 14px 14px;display:flex;flex-direction:column;gap:10px;
    overflow-y:auto;overflow-x:hidden;min-height:0;
    scrollbar-width:thin;scrollbar-color:rgba(0,0,0,.15) transparent}
  .twk-body::-webkit-scrollbar{width:8px}
  .twk-body::-webkit-scrollbar-track{background:transparent;margin:2px}
  .twk-body::-webkit-scrollbar-thumb{background:rgba(0,0,0,.15);border-radius:4px;
    border:2px solid transparent;background-clip:content-box}
  .twk-body::-webkit-scrollbar-thumb:hover{background:rgba(0,0,0,.25);
    border:2px solid transparent;background-clip:content-box}
  .twk-row{display:flex;flex-direction:column;gap:5px}
  .twk-row-h{flex-direction:row;align-items:center;justify-content:space-between;gap:10px}
  .twk-lbl{display:flex;justify-content:space-between;align-items:baseline;
    color:rgba(41,38,27,.72)}
  .twk-lbl>span:first-child{font-weight:500}
  .twk-val{color:rgba(41,38,27,.5);font-variant-numeric:tabular-nums}

  .twk-sect{font-size:10px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;
    color:rgba(41,38,27,.45);padding:10px 0 0}
  .twk-sect:first-child{padding-top:0}

  .twk-field{appearance:none;box-sizing:border-box;width:100%;min-width:0;height:26px;padding:0 8px;
    border:.5px solid rgba(0,0,0,.1);border-radius:7px;
    background:rgba(255,255,255,.6);color:inherit;font:inherit;outline:none}
  .twk-field:focus{border-color:rgba(0,0,0,.25);background:rgba(255,255,255,.85)}
  select.twk-field{padding-right:22px;
    background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'><path fill='rgba(0,0,0,.5)' d='M0 0h10L5 6z'/></svg>");
    background-repeat:no-repeat;background-position:right 8px center}

  .twk-slider{appearance:none;-webkit-appearance:none;width:100%;height:4px;margin:6px 0;
    border-radius:999px;background:rgba(0,0,0,.12);outline:none}
  .twk-slider::-webkit-slider-thumb{-webkit-appearance:none;appearance:none;
    width:14px;height:14px;border-radius:50%;background:#fff;
    border:.5px solid rgba(0,0,0,.12);box-shadow:0 1px 3px rgba(0,0,0,.2);cursor:default}
  .twk-slider::-moz-range-thumb{width:14px;height:14px;border-radius:50%;
    background:#fff;border:.5px solid rgba(0,0,0,.12);box-shadow:0 1px 3px rgba(0,0,0,.2);cursor:default}

  .twk-seg{position:relative;display:flex;padding:2px;border-radius:8px;
    background:rgba(0,0,0,.06);user-select:none}
  .twk-seg-thumb{position:absolute;top:2px;bottom:2px;border-radius:6px;
    background:rgba(255,255,255,.9);box-shadow:0 1px 2px rgba(0,0,0,.12);
    transition:left .15s cubic-bezier(.3,.7,.4,1),width .15s}
  .twk-seg.dragging .twk-seg-thumb{transition:none}
  .twk-seg button{appearance:none;position:relative;z-index:1;flex:1;border:0;
    background:transparent;color:inherit;font:inherit;font-weight:500;min-height:22px;
    border-radius:6px;cursor:default;padding:4px 6px;line-height:1.2;
    overflow-wrap:anywhere}

  .twk-toggle{position:relative;width:32px;height:18px;border:0;border-radius:999px;
    background:rgba(0,0,0,.15);transition:background .15s;cursor:default;padding:0}
  .twk-toggle[data-on="1"]{background:#34c759}
  .twk-toggle i{position:absolute;top:2px;left:2px;width:14px;height:14px;border-radius:50%;
    background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.25);transition:transform .15s}
  .twk-toggle[data-on="1"] i{transform:translateX(14px)}

  .twk-num{display:flex;align-items:center;box-sizing:border-box;min-width:0;height:26px;padding:0 0 0 8px;
    border:.5px solid rgba(0,0,0,.1);border-radius:7px;background:rgba(255,255,255,.6)}
  .twk-num-lbl{font-weight:500;color:rgba(41,38,27,.6);cursor:ew-resize;
    user-select:none;padding-right:8px}
  .twk-num input{flex:1;min-width:0;height:100%;border:0;background:transparent;
    font:inherit;font-variant-numeric:tabular-nums;text-align:right;padding:0 8px 0 0;
    outline:none;color:inherit;-moz-appearance:textfield}
  .twk-num input::-webkit-inner-spin-button,.twk-num input::-webkit-outer-spin-button{
    -webkit-appearance:none;margin:0}
  .twk-num-unit{padding-right:8px;color:rgba(41,38,27,.45)}

  .twk-btn{appearance:none;height:26px;padding:0 12px;border:0;border-radius:7px;
    background:rgba(0,0,0,.78);color:#fff;font:inherit;font-weight:500;cursor:default}
  .twk-btn:hover{background:rgba(0,0,0,.88)}
  .twk-btn.secondary{background:rgba(0,0,0,.06);color:inherit}
  .twk-btn.secondary:hover{background:rgba(0,0,0,.1)}

  .twk-swatch{appearance:none;-webkit-appearance:none;width:56px;height:22px;
    border:.5px solid rgba(0,0,0,.1);border-radius:6px;padding:0;cursor:default;
    background:transparent;flex-shrink:0}
  .twk-swatch::-webkit-color-swatch-wrapper{padding:0}
  .twk-swatch::-webkit-color-swatch{border:0;border-radius:5.5px}
  .twk-swatch::-moz-color-swatch{border:0;border-radius:5.5px}

  .twk-chips{display:flex;gap:6px}
  .twk-chip{position:relative;appearance:none;flex:1;min-width:0;height:46px;
    padding:0;border:0;border-radius:6px;overflow:hidden;cursor:default;
    box-shadow:0 0 0 .5px rgba(0,0,0,.12),0 1px 2px rgba(0,0,0,.06);
    transition:transform .12s cubic-bezier(.3,.7,.4,1),box-shadow .12s}
  .twk-chip:hover{transform:translateY(-1px);
    box-shadow:0 0 0 .5px rgba(0,0,0,.18),0 4px 10px rgba(0,0,0,.12)}
  .twk-chip[data-on="1"]{box-shadow:0 0 0 1.5px rgba(0,0,0,.85),
    0 2px 6px rgba(0,0,0,.15)}
  .twk-chip>span{position:absolute;top:0;bottom:0;right:0;width:34%;
    display:flex;flex-direction:column;box-shadow:-1px 0 0 rgba(0,0,0,.1)}
  .twk-chip>span>i{flex:1;box-shadow:0 -1px 0 rgba(0,0,0,.1)}
  .twk-chip>span>i:first-child{box-shadow:none}
  .twk-chip svg{position:absolute;top:6px;left:6px;width:13px;height:13px;
    filter:drop-shadow(0 1px 1px rgba(0,0,0,.3))}
`;

// ── useTweaks ───────────────────────────────────────────────────────────────
// Single source of truth for tweak values. setTweak persists via the host
// (__edit_mode_set_keys → host rewrites the EDITMODE block on disk).
function useTweaks(defaults) {
  const [values, setValues] = React.useState(defaults);
  // Accepts either setTweak('key', value) or setTweak({ key: value, ... }) so a
  // useState-style call doesn't write a "[object Object]" key into the persisted
  // JSON block.
  const setTweak = React.useCallback((keyOrEdits, val) => {
    const edits = typeof keyOrEdits === 'object' && keyOrEdits !== null ? keyOrEdits : {
      [keyOrEdits]: val
    };
    setValues(prev => ({
      ...prev,
      ...edits
    }));
    window.parent.postMessage({
      type: '__edit_mode_set_keys',
      edits
    }, '*');
    // Same-window signal so in-page listeners (deck-stage rail thumbnails)
    // can react — the parent message only reaches the host, not peers.
    window.dispatchEvent(new CustomEvent('tweakchange', {
      detail: edits
    }));
  }, []);
  return [values, setTweak];
}

// ── TweaksPanel ─────────────────────────────────────────────────────────────
// Floating shell. Registers the protocol listener BEFORE announcing
// availability — if the announce ran first, the host's activate could land
// before our handler exists and the toolbar toggle would silently no-op.
// The close button posts __edit_mode_dismissed so the host's toolbar toggle
// flips off in lockstep; the host echoes __deactivate_edit_mode back which
// is what actually hides the panel.
function TweaksPanel({
  title = 'Tweaks',
  children
}) {
  const [open, setOpen] = React.useState(false);
  const dragRef = React.useRef(null);
  const offsetRef = React.useRef({
    x: 16,
    y: 16
  });
  const PAD = 16;
  const clampToViewport = React.useCallback(() => {
    const panel = dragRef.current;
    if (!panel) return;
    const w = panel.offsetWidth,
      h = panel.offsetHeight;
    const maxRight = Math.max(PAD, window.innerWidth - w - PAD);
    const maxBottom = Math.max(PAD, window.innerHeight - h - PAD);
    offsetRef.current = {
      x: Math.min(maxRight, Math.max(PAD, offsetRef.current.x)),
      y: Math.min(maxBottom, Math.max(PAD, offsetRef.current.y))
    };
    panel.style.right = offsetRef.current.x + 'px';
    panel.style.bottom = offsetRef.current.y + 'px';
  }, []);
  React.useEffect(() => {
    if (!open) return;
    clampToViewport();
    if (typeof ResizeObserver === 'undefined') {
      window.addEventListener('resize', clampToViewport);
      return () => window.removeEventListener('resize', clampToViewport);
    }
    const ro = new ResizeObserver(clampToViewport);
    ro.observe(document.documentElement);
    return () => ro.disconnect();
  }, [open, clampToViewport]);
  React.useEffect(() => {
    const onMsg = e => {
      const t = e?.data?.type;
      if (t === '__activate_edit_mode') setOpen(true);else if (t === '__deactivate_edit_mode') setOpen(false);
    };
    window.addEventListener('message', onMsg);
    window.parent.postMessage({
      type: '__edit_mode_available'
    }, '*');
    return () => window.removeEventListener('message', onMsg);
  }, []);
  const dismiss = () => {
    setOpen(false);
    window.parent.postMessage({
      type: '__edit_mode_dismissed'
    }, '*');
  };
  const onDragStart = e => {
    const panel = dragRef.current;
    if (!panel) return;
    const r = panel.getBoundingClientRect();
    const sx = e.clientX,
      sy = e.clientY;
    const startRight = window.innerWidth - r.right;
    const startBottom = window.innerHeight - r.bottom;
    const move = ev => {
      offsetRef.current = {
        x: startRight - (ev.clientX - sx),
        y: startBottom - (ev.clientY - sy)
      };
      clampToViewport();
    };
    const up = () => {
      window.removeEventListener('mousemove', move);
      window.removeEventListener('mouseup', up);
    };
    window.addEventListener('mousemove', move);
    window.addEventListener('mouseup', up);
  };
  if (!open) return null;
  return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("style", null, __TWEAKS_STYLE), /*#__PURE__*/React.createElement("div", {
    ref: dragRef,
    className: "twk-panel",
    "data-omelette-chrome": "",
    style: {
      right: offsetRef.current.x,
      bottom: offsetRef.current.y
    }
  }, /*#__PURE__*/React.createElement("div", {
    className: "twk-hd",
    onMouseDown: onDragStart
  }, /*#__PURE__*/React.createElement("b", null, title), /*#__PURE__*/React.createElement("button", {
    className: "twk-x",
    "aria-label": "Close tweaks",
    onMouseDown: e => e.stopPropagation(),
    onClick: dismiss
  }, "\u2715")), /*#__PURE__*/React.createElement("div", {
    className: "twk-body"
  }, children)));
}

// ── Layout helpers ──────────────────────────────────────────────────────────

function TweakSection({
  label,
  children
}) {
  return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("div", {
    className: "twk-sect"
  }, label), children);
}
function TweakRow({
  label,
  value,
  children,
  inline = false
}) {
  return /*#__PURE__*/React.createElement("div", {
    className: inline ? 'twk-row twk-row-h' : 'twk-row'
  }, /*#__PURE__*/React.createElement("div", {
    className: "twk-lbl"
  }, /*#__PURE__*/React.createElement("span", null, label), value != null && /*#__PURE__*/React.createElement("span", {
    className: "twk-val"
  }, value)), children);
}

// ── Controls ────────────────────────────────────────────────────────────────

function TweakSlider({
  label,
  value,
  min = 0,
  max = 100,
  step = 1,
  unit = '',
  onChange
}) {
  return /*#__PURE__*/React.createElement(TweakRow, {
    label: label,
    value: `${value}${unit}`
  }, /*#__PURE__*/React.createElement("input", {
    type: "range",
    className: "twk-slider",
    min: min,
    max: max,
    step: step,
    value: value,
    onChange: e => onChange(Number(e.target.value))
  }));
}
function TweakToggle({
  label,
  value,
  onChange
}) {
  return /*#__PURE__*/React.createElement("div", {
    className: "twk-row twk-row-h"
  }, /*#__PURE__*/React.createElement("div", {
    className: "twk-lbl"
  }, /*#__PURE__*/React.createElement("span", null, label)), /*#__PURE__*/React.createElement("button", {
    type: "button",
    className: "twk-toggle",
    "data-on": value ? '1' : '0',
    role: "switch",
    "aria-checked": !!value,
    onClick: () => onChange(!value)
  }, /*#__PURE__*/React.createElement("i", null)));
}
function TweakRadio({
  label,
  value,
  options,
  onChange
}) {
  const trackRef = React.useRef(null);
  const [dragging, setDragging] = React.useState(false);
  // The active value is read by pointer-move handlers attached for the lifetime
  // of a drag — ref it so a stale closure doesn't fire onChange for every move.
  const valueRef = React.useRef(value);
  valueRef.current = value;

  // Segments wrap mid-word once per-segment width runs out. The track is
  // ~248px (280 panel − 28 body pad − 4 seg pad), each button loses 12px
  // to its own padding, and 11.5px system-ui averages ~6.3px/char — so 2
  // options fit ~16 chars each, 3 fit ~10. Past that (or >3 options), fall
  // back to a dropdown rather than wrap.
  const labelLen = o => String(typeof o === 'object' ? o.label : o).length;
  const maxLen = options.reduce((m, o) => Math.max(m, labelLen(o)), 0);
  const fitsAsSegments = maxLen <= ({
    2: 16,
    3: 10
  }[options.length] ?? 0);
  if (!fitsAsSegments) {
    // <select> emits strings — map back to the original option value so the
    // fallback stays type-preserving (numbers, booleans) like the segment path.
    const resolve = s => {
      const m = options.find(o => String(typeof o === 'object' ? o.value : o) === s);
      return m === undefined ? s : typeof m === 'object' ? m.value : m;
    };
    return /*#__PURE__*/React.createElement(TweakSelect, {
      label: label,
      value: value,
      options: options,
      onChange: s => onChange(resolve(s))
    });
  }
  const opts = options.map(o => typeof o === 'object' ? o : {
    value: o,
    label: o
  });
  const idx = Math.max(0, opts.findIndex(o => o.value === value));
  const n = opts.length;
  const segAt = clientX => {
    const r = trackRef.current.getBoundingClientRect();
    const inner = r.width - 4;
    const i = Math.floor((clientX - r.left - 2) / inner * n);
    return opts[Math.max(0, Math.min(n - 1, i))].value;
  };
  const onPointerDown = e => {
    setDragging(true);
    const v0 = segAt(e.clientX);
    if (v0 !== valueRef.current) onChange(v0);
    const move = ev => {
      if (!trackRef.current) return;
      const v = segAt(ev.clientX);
      if (v !== valueRef.current) onChange(v);
    };
    const up = () => {
      setDragging(false);
      window.removeEventListener('pointermove', move);
      window.removeEventListener('pointerup', up);
    };
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', up);
  };
  return /*#__PURE__*/React.createElement(TweakRow, {
    label: label
  }, /*#__PURE__*/React.createElement("div", {
    ref: trackRef,
    role: "radiogroup",
    onPointerDown: onPointerDown,
    className: dragging ? 'twk-seg dragging' : 'twk-seg'
  }, /*#__PURE__*/React.createElement("div", {
    className: "twk-seg-thumb",
    style: {
      left: `calc(2px + ${idx} * (100% - 4px) / ${n})`,
      width: `calc((100% - 4px) / ${n})`
    }
  }), opts.map(o => /*#__PURE__*/React.createElement("button", {
    key: o.value,
    type: "button",
    role: "radio",
    "aria-checked": o.value === value
  }, o.label))));
}
function TweakSelect({
  label,
  value,
  options,
  onChange
}) {
  return /*#__PURE__*/React.createElement(TweakRow, {
    label: label
  }, /*#__PURE__*/React.createElement("select", {
    className: "twk-field",
    value: value,
    onChange: e => onChange(e.target.value)
  }, options.map(o => {
    const v = typeof o === 'object' ? o.value : o;
    const l = typeof o === 'object' ? o.label : o;
    return /*#__PURE__*/React.createElement("option", {
      key: v,
      value: v
    }, l);
  })));
}
function TweakText({
  label,
  value,
  placeholder,
  onChange
}) {
  return /*#__PURE__*/React.createElement(TweakRow, {
    label: label
  }, /*#__PURE__*/React.createElement("input", {
    className: "twk-field",
    type: "text",
    value: value,
    placeholder: placeholder,
    onChange: e => onChange(e.target.value)
  }));
}
function TweakNumber({
  label,
  value,
  min,
  max,
  step = 1,
  unit = '',
  onChange
}) {
  const clamp = n => {
    if (min != null && n < min) return min;
    if (max != null && n > max) return max;
    return n;
  };
  const startRef = React.useRef({
    x: 0,
    val: 0
  });
  const onScrubStart = e => {
    e.preventDefault();
    startRef.current = {
      x: e.clientX,
      val: value
    };
    const decimals = (String(step).split('.')[1] || '').length;
    const move = ev => {
      const dx = ev.clientX - startRef.current.x;
      const raw = startRef.current.val + dx * step;
      const snapped = Math.round(raw / step) * step;
      onChange(clamp(Number(snapped.toFixed(decimals))));
    };
    const up = () => {
      window.removeEventListener('pointermove', move);
      window.removeEventListener('pointerup', up);
    };
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', up);
  };
  return /*#__PURE__*/React.createElement("div", {
    className: "twk-num"
  }, /*#__PURE__*/React.createElement("span", {
    className: "twk-num-lbl",
    onPointerDown: onScrubStart
  }, label), /*#__PURE__*/React.createElement("input", {
    type: "number",
    value: value,
    min: min,
    max: max,
    step: step,
    onChange: e => onChange(clamp(Number(e.target.value)))
  }), unit && /*#__PURE__*/React.createElement("span", {
    className: "twk-num-unit"
  }, unit));
}

// Relative-luminance contrast pick — checkmarks drawn over a swatch need to
// read on both #111 and #fafafa without per-option configuration. Hex input
// only (#rgb / #rrggbb); named or rgb()/hsl() colors fall through to "light".
function __twkIsLight(hex) {
  const h = String(hex).replace('#', '');
  const x = h.length === 3 ? h.replace(/./g, c => c + c) : h.padEnd(6, '0');
  const n = parseInt(x.slice(0, 6), 16);
  if (Number.isNaN(n)) return true;
  const r = n >> 16 & 255,
    g = n >> 8 & 255,
    b = n & 255;
  return r * 299 + g * 587 + b * 114 > 148000;
}
const __TwkCheck = ({
  light
}) => /*#__PURE__*/React.createElement("svg", {
  viewBox: "0 0 14 14",
  "aria-hidden": "true"
}, /*#__PURE__*/React.createElement("path", {
  d: "M3 7.2 5.8 10 11 4.2",
  fill: "none",
  strokeWidth: "2.2",
  strokeLinecap: "round",
  strokeLinejoin: "round",
  stroke: light ? 'rgba(0,0,0,.78)' : '#fff'
}));

// TweakColor — curated color/palette picker. Each option is either a single
// hex string or an array of 1-5 hex strings; the card adapts — a lone color
// renders solid, a palette renders colors[0] as the hero (left ~2/3) with the
// rest stacked in a sharp column on the right. onChange emits the
// option in the shape it was passed (string stays string, array stays array).
// Without options it falls back to the native color input for back-compat.
function TweakColor({
  label,
  value,
  options,
  onChange
}) {
  if (!options || !options.length) {
    return /*#__PURE__*/React.createElement("div", {
      className: "twk-row twk-row-h"
    }, /*#__PURE__*/React.createElement("div", {
      className: "twk-lbl"
    }, /*#__PURE__*/React.createElement("span", null, label)), /*#__PURE__*/React.createElement("input", {
      type: "color",
      className: "twk-swatch",
      value: value,
      onChange: e => onChange(e.target.value)
    }));
  }
  // Native <input type=color> emits lowercase hex per the HTML spec, so
  // compare case-insensitively. String() guards JSON.stringify(undefined),
  // which returns the primitive undefined (no .toLowerCase).
  const key = o => String(JSON.stringify(o)).toLowerCase();
  const cur = key(value);
  return /*#__PURE__*/React.createElement(TweakRow, {
    label: label
  }, /*#__PURE__*/React.createElement("div", {
    className: "twk-chips",
    role: "radiogroup"
  }, options.map((o, i) => {
    const colors = Array.isArray(o) ? o : [o];
    const [hero, ...rest] = colors;
    const sup = rest.slice(0, 4);
    const on = key(o) === cur;
    return /*#__PURE__*/React.createElement("button", {
      key: i,
      type: "button",
      className: "twk-chip",
      role: "radio",
      "aria-checked": on,
      "data-on": on ? '1' : '0',
      "aria-label": colors.join(', '),
      title: colors.join(' · '),
      style: {
        background: hero
      },
      onClick: () => onChange(o)
    }, sup.length > 0 && /*#__PURE__*/React.createElement("span", null, sup.map((c, j) => /*#__PURE__*/React.createElement("i", {
      key: j,
      style: {
        background: c
      }
    }))), on && /*#__PURE__*/React.createElement(__TwkCheck, {
      light: __twkIsLight(hero)
    }));
  })));
}
function TweakButton({
  label,
  onClick,
  secondary = false
}) {
  return /*#__PURE__*/React.createElement("button", {
    type: "button",
    className: secondary ? 'twk-btn secondary' : 'twk-btn',
    onClick: onClick
  }, label);
}
Object.assign(window, {
  useTweaks,
  TweaksPanel,
  TweakSection,
  TweakRow,
  TweakSlider,
  TweakToggle,
  TweakRadio,
  TweakSelect,
  TweakText,
  TweakNumber,
  TweakColor,
  TweakButton
});
})(); } catch (e) { __ds_ns.__errors.push({ path: "redesign/_backup_2026-07-04/tweaks-panel.jsx", error: String((e && e.message) || e) }); }

// redesign/_dh_update/redesign/access-section.jsx
try { (() => {
/* Доступ и оргструктура — Настройки → Система.
   Mirrors front/src/pages/AccessControlPage (index + DepartmentsTab, DepartmentTree,
   DepartmentSidePanel, OrgChartView, RolesPermissionsTab, PermissionMatrix,
   VisibilityScopeTab) + entities/accessControl + ru.json. Redesigned look.
   Exposes window.AccessTab. */
(function () {
  const {
    useState,
    useRef,
    useEffect
  } = React;

  /* ── i18n (ru.json → accessControl) ── */
  const A_T = {
    title: 'Доступ и оргструктура',
    subtitle: 'Отделы, роли и видимость записей',
    tabs: {
      departments: 'Отделы',
      roles: 'Роли и права',
      visibility: 'Видимость'
    },
    dep: {
      add: 'Добавить отдел',
      edit: 'Редактировать отдел',
      del: 'Удалить отдел',
      delConfirm: n => 'Удалить отдел «' + n + '»? Дочерние отделы и сотрудники останутся без родителя.',
      name: 'Название',
      parent: 'Родительский отдел',
      root: '— Корень —',
      manager: 'Руководитель',
      members: 'Сотрудники отдела',
      addMember: 'Добавить сотрудника',
      removeMember: 'Убрать из отдела',
      viewTree: 'Дерево',
      viewChart: 'Схема',
      empty: 'Отделы не созданы',
      emptyHint: 'Добавьте первый отдел, чтобы настроить оргструктуру',
      noMembers: 'Нет участников',
      saved: 'Отдел сохранён',
      deleted: 'Отдел удалён'
    },
    roles: {
      title: 'Матрица прав',
      adminNote: 'Роль admin всегда получает все права и не может быть ограничена.',
      permissionLabel: 'Право',
      save: 'Сохранить',
      reset: 'Сбросить',
      saved: 'Права ролей сохранены'
    },
    vis: {
      title: 'Видимость записей',
      warning: 'Настройки видимости влияют на то, какие записи (сделки, контакты, задачи) видит каждая роль. Изменяйте с осторожностью.',
      scopeAll: 'Все',
      scopeDepartment: 'Отдел (+подотделы)',
      scopeOwn: 'Свои',
      roleColumn: 'Роль',
      scopeColumn: 'Видимость записей',
      departmentHint: 'Значение «Отдел» работает только если у пользователя указан отдел.',
      save: 'Сохранить',
      reset: 'Сбросить',
      saved: 'Настройки видимости сохранены'
    }
  };
  const A_ROLES = ['admin', 'director', 'lawyer', 'manager', 'accountant', 'cfo'];
  const A_ROLE_LABEL = {
    admin: 'Администратор',
    director: 'Директор',
    lawyer: 'Юрист',
    manager: 'Менеджер',
    accountant: 'Бухгалтер',
    cfo: 'Финансовый директор'
  };
  const A_ROLE_SHORT = {
    admin: 'Админ',
    director: 'Директор',
    lawyer: 'Юрист',
    manager: 'Менеджер',
    accountant: 'Бухгалтер',
    cfo: 'Фин. дир.'
  };
  const A_ROLE_SEV = {
    admin: 'danger',
    director: 'warn',
    lawyer: 'info',
    manager: 'success',
    accountant: 'secondary',
    cfo: 'secondary'
  };
  const A_GROUPS = [{
    key: 'crm',
    label: 'CRM',
    perms: ['crm.view', 'crm.manage']
  }, {
    key: 'sales',
    label: 'Продажи',
    perms: ['sales.view', 'sales.manage']
  }, {
    key: 'contracts',
    label: 'Договоры',
    perms: ['contracts.view', 'contracts.manage']
  }, {
    key: 'users',
    label: 'Пользователи',
    perms: ['users.view', 'users.manage']
  }, {
    key: 'automation',
    label: 'Автоматизации',
    perms: ['automation.manage']
  }, {
    key: 'analytics',
    label: 'Аналитика',
    perms: ['analytics.view', 'settings.manage']
  }, {
    key: 'finance',
    label: 'Финансы',
    perms: ['finance.view', 'finance.entry', 'finance.posting', 'finance.journals.manual', 'finance.payments.approve', 'finance.period.close', 'finance.settings.manage', 'finance.reports.management']
  }, {
    key: 'system',
    label: 'Системные права',
    perms: ['admin-write', 'dedup-scan-all', 'view-manager-cabinet', 'system-reset']
  }];
  const A_PERM_LABEL = {
    'crm.view': 'CRM — просмотр',
    'crm.manage': 'CRM — управление',
    'sales.view': 'Продажи — просмотр',
    'sales.manage': 'Продажи — управление',
    'contracts.view': 'Договоры — просмотр',
    'contracts.manage': 'Договоры — управление',
    'users.view': 'Пользователи — просмотр',
    'users.manage': 'Пользователи — управление',
    'automation.manage': 'Автоматизации — управление',
    'analytics.view': 'Аналитика — просмотр',
    'settings.manage': 'Настройки системы — управление',
    'finance.view': 'Финансы — просмотр',
    'finance.entry': 'Финансы — ввод операций',
    'finance.posting': 'Финансы — проводки',
    'finance.journals.manual': 'Финансы — ручные журналы',
    'finance.payments.approve': 'Финансы — согласование платежей',
    'finance.period.close': 'Финансы — закрытие периода',
    'finance.settings.manage': 'Финансы — настройки',
    'finance.reports.management': 'Финансы — управленческие отчёты',
    'admin-write': 'Системные изменения (админ)',
    'dedup-scan-all': 'Дедупликация — полное сканирование',
    'view-manager-cabinet': 'Кабинет менеджера — доступ',
    'system-reset': 'Сброс системы'
  };
  /* default role → granted permissions */
  const A_DEFAULT_PERMS = {
    admin: A_GROUPS.flatMap(g => g.perms),
    director: ['crm.view', 'crm.manage', 'sales.view', 'sales.manage', 'contracts.view', 'contracts.manage', 'users.view', 'automation.manage', 'analytics.view', 'settings.manage', 'finance.view', 'view-manager-cabinet'],
    lawyer: ['crm.view', 'contracts.view', 'contracts.manage'],
    manager: ['crm.view', 'sales.view', 'sales.manage', 'contracts.view'],
    accountant: ['crm.view', 'finance.view', 'finance.entry', 'finance.posting', 'finance.journals.manual'],
    cfo: ['crm.view', 'analytics.view', 'finance.view', 'finance.entry', 'finance.posting', 'finance.payments.approve', 'finance.period.close', 'finance.settings.manage', 'finance.reports.management']
  };
  const A_DEFAULT_VIS = {
    admin: 'all',
    director: 'all',
    lawyer: 'department',
    manager: 'own',
    accountant: 'department',
    cfo: 'all'
  };
  const A_M = (id, full_name, email, role) => ({
    id,
    full_name,
    email,
    role
  });
  const A_TREE0 = [{
    id: 1,
    name: 'Руководство',
    manager: 'Директор Петров П.П.',
    members: [A_M(6, 'Директор Петров П.П.', 'director@mgcrm.test', 'director'), A_M(2, 'Bogdan Yadykin', 'b.yadykin@macroglobaltech.com', 'admin')],
    children: [{
      id: 2,
      name: 'Отдел продаж',
      manager: 'Иванов Алексей Сергеевич',
      members: [A_M(7, 'Иванов Алексей Сергеевич', 'manager1@mgcrm.test', 'manager'), A_M(8, 'Илья Рогов', 'ilyarogov.mera@gmail.com', 'manager'), A_M(9, 'Клим Федорин', 'k.fedorin@macroglobaltech.com', 'manager'), A_M(10, 'Олеся Моисеева', 'o.moiseeva@macroglobaltech.com', 'manager'), A_M(5, 'Георгий Некрасов', 'g.nekrasov@macroglobaltech.com', 'manager'), A_M(11, 'Петрова Мария Сергеевна', 'manager2@mgcrm.test', 'manager')],
      children: [{
        id: 5,
        name: 'Группа B2B',
        manager: 'Петрова Мария Сергеевна',
        members: [A_M(7, 'Иванов Алексей Сергеевич', 'manager1@mgcrm.test', 'manager'), A_M(9, 'Клим Федорин', 'k.fedorin@macroglobaltech.com', 'manager'), A_M(5, 'Георгий Некрасов', 'g.nekrasov@macroglobaltech.com', 'manager')],
        children: []
      }]
    }, {
      id: 3,
      name: 'Финансы',
      manager: 'Сергей Казначеев',
      members: [A_M(13, 'Сергей Казначеев', 'cfo@mgcrm.test', 'cfo')],
      children: [{
        id: 6,
        name: 'Бухгалтерия',
        manager: 'Анна Счётова',
        members: [A_M(12, 'Анна Счётова', 'accountant@mgcrm.test', 'accountant')],
        children: []
      }]
    }, {
      id: 4,
      name: 'Юридический отдел',
      manager: 'Lawyer Test',
      members: [A_M(3, 'Lawyer Test', 'lawyer@mgcrm.test', 'lawyer')],
      children: []
    }]
  }];
  const A_ALL_USERS = [A_M(1, 'Admin', 'svkv42@gmail.com', 'admin'), A_M(2, 'Bogdan Yadykin', 'b.yadykin@macroglobaltech.com', 'admin'), A_M(3, 'Lawyer Test', 'lawyer@mgcrm.test', 'lawyer'), A_M(5, 'Георгий Некрасов', 'g.nekrasov@macroglobaltech.com', 'manager'), A_M(6, 'Директор Петров П.П.', 'director@mgcrm.test', 'director'), A_M(7, 'Иванов Алексей Сергеевич', 'manager1@mgcrm.test', 'manager'), A_M(8, 'Илья Рогов', 'ilyarogov.mera@gmail.com', 'manager'), A_M(9, 'Клим Федорин', 'k.fedorin@macroglobaltech.com', 'manager'), A_M(10, 'Олеся Моисеева', 'o.moiseeva@macroglobaltech.com', 'manager'), A_M(11, 'Петрова Мария Сергеевна', 'manager2@mgcrm.test', 'manager'), A_M(12, 'Анна Счётова', 'accountant@mgcrm.test', 'accountant'), A_M(13, 'Сергей Казначеев', 'cfo@mgcrm.test', 'cfo')];

  /* ── shared primitives ── */
  const A_SEV = {
    success: {
      bg: 'var(--mg-status-success-bg)',
      fg: 'var(--mg-status-success-text)'
    },
    danger: {
      bg: 'var(--mg-status-danger-bg)',
      fg: 'var(--mg-status-danger-text)'
    },
    warn: {
      bg: 'var(--mg-status-warning-bg)',
      fg: 'var(--mg-status-warning-text)'
    },
    info: {
      bg: 'var(--mg-status-info-bg)',
      fg: 'var(--mg-status-info-text)'
    },
    secondary: {
      bg: 'var(--c-muted2)',
      fg: 'var(--c-text2)'
    }
  };
  function A_btn(kind) {
    const base = {
      display: 'inline-flex',
      alignItems: 'center',
      gap: 7,
      height: 38,
      padding: '0 15px',
      borderRadius: 'var(--mg-radius-md)',
      fontFamily: 'var(--mg-font-sans)',
      fontSize: 13,
      fontWeight: 600,
      cursor: 'pointer',
      border: 'none',
      whiteSpace: 'nowrap'
    };
    if (kind === 'primary') return {
      ...base,
      background: 'var(--mg-primary-900)',
      color: '#fff'
    };
    if (kind === 'danger') return {
      ...base,
      background: 'var(--mg-status-danger-solid)',
      color: '#fff'
    };
    if (kind === 'text') return {
      ...base,
      background: 'transparent',
      color: 'var(--c-text2)'
    };
    return {
      ...base,
      background: 'transparent',
      color: 'var(--c-text2)',
      border: '1px solid var(--c-border2)'
    };
  }
  function A_sbtn(kind, active) {
    const b = A_btn(kind === 'p' ? 'primary' : 'ghost');
    return {
      ...b,
      height: 34,
      padding: '0 12px',
      fontSize: 12.5,
      ...(active ? {} : {})
    };
  }
  function A_Tag({
    value,
    severity
  }) {
    const c = A_SEV[severity] || A_SEV.secondary;
    return /*#__PURE__*/React.createElement("span", {
      style: {
        display: 'inline-flex',
        alignItems: 'center',
        fontSize: 11.5,
        fontWeight: 600,
        padding: '3px 9px',
        borderRadius: 6,
        background: c.bg,
        color: c.fg,
        whiteSpace: 'nowrap'
      }
    }, value);
  }
  function A_Msg({
    severity,
    children
  }) {
    const map = {
      warn: {
        bg: 'var(--mg-status-warning-bg)',
        bd: 'var(--mg-status-warning-border)',
        fg: 'var(--mg-status-warning-text)',
        ic: 'pi-exclamation-triangle'
      },
      secondary: {
        bg: 'var(--c-hover)',
        bd: 'var(--c-border)',
        fg: 'var(--c-text2)',
        ic: 'pi-info-circle'
      }
    };
    const c = map[severity] || map.secondary;
    return /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'flex-start',
        gap: 10,
        padding: '11px 14px',
        background: c.bg,
        border: '1px solid ' + c.bd,
        borderRadius: 'var(--mg-radius-md)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + c.ic,
      style: {
        fontSize: 14,
        color: c.fg,
        marginTop: 1,
        flexShrink: 0
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 13,
        color: c.fg,
        lineHeight: 1.5
      }
    }, children));
  }
  function A_Check({
    checked,
    disabled,
    onChange
  }) {
    return /*#__PURE__*/React.createElement("span", {
      onClick: () => !disabled && onChange(!checked),
      style: {
        display: 'inline-flex',
        width: 20,
        height: 20,
        borderRadius: 5,
        border: '1.5px solid ' + (checked ? 'var(--mg-primary-900)' : 'var(--c-border2)'),
        background: checked ? 'var(--mg-primary-900)' : 'var(--c-card)',
        alignItems: 'center',
        justifyContent: 'center',
        cursor: disabled ? 'not-allowed' : 'pointer',
        opacity: disabled ? 0.5 : 1
      }
    }, checked && /*#__PURE__*/React.createElement("i", {
      className: "pi pi-check",
      style: {
        fontSize: 11,
        color: '#fff',
        fontWeight: 700
      }
    }));
  }
  function A_Dropdown({
    value,
    onChange,
    options,
    placeholder,
    width,
    filter
  }) {
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const ref = useRef(null);
    useEffect(() => {
      const h = e => {
        if (ref.current && !ref.current.contains(e.target)) setOpen(false);
      };
      document.addEventListener('mousedown', h);
      return () => document.removeEventListener('mousedown', h);
    }, []);
    const sel = options.find(o => o.value === value);
    const list = filter && q ? options.filter(o => o.label.toLowerCase().includes(q.toLowerCase())) : options;
    return /*#__PURE__*/React.createElement("div", {
      ref: ref,
      style: {
        position: 'relative',
        width: width || '100%'
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: () => setOpen(!open),
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        height: 40,
        padding: '0 12px',
        border: '1px solid ' + (open ? 'var(--mg-input-focus-border)' : 'var(--mg-input-border)'),
        borderRadius: 'var(--mg-radius-md)',
        background: 'var(--c-card)',
        cursor: 'pointer',
        boxShadow: open ? 'var(--mg-focus-ring)' : 'none'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 14,
        color: sel ? 'var(--c-text)' : 'var(--c-muted)',
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, sel ? sel.label : placeholder), /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + (open ? 'pi-chevron-up' : 'pi-chevron-down'),
      style: {
        fontSize: 12,
        color: 'var(--c-muted)'
      }
    })), open && /*#__PURE__*/React.createElement("div", {
      style: {
        position: 'absolute',
        top: 44,
        insetInlineStart: 0,
        zIndex: 40,
        width: '100%',
        minWidth: 180,
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-md)',
        boxShadow: 'var(--mg-shadow-lg)',
        padding: 5,
        maxHeight: 260,
        overflowY: 'auto'
      }
    }, filter && /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 7,
        height: 34,
        padding: '0 10px',
        margin: '2px 2px 6px',
        border: '1px solid var(--c-border2)',
        borderRadius: 'var(--mg-radius-sm)',
        background: 'var(--c-page)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-search",
      style: {
        fontSize: 12,
        color: 'var(--c-muted)'
      }
    }), /*#__PURE__*/React.createElement("input", {
      autoFocus: true,
      value: q,
      onChange: e => setQ(e.target.value),
      placeholder: "\u041F\u043E\u0438\u0441\u043A\u2026",
      style: {
        flex: 1,
        border: 'none',
        outline: 'none',
        background: 'transparent',
        fontFamily: 'var(--mg-font-sans)',
        fontSize: 13,
        color: 'var(--c-text)'
      }
    })), list.map(o => {
      const on = o.value === value;
      return /*#__PURE__*/React.createElement("div", {
        key: String(o.value),
        onClick: () => {
          onChange(o.value);
          setOpen(false);
          setQ('');
        },
        style: {
          display: 'flex',
          alignItems: 'center',
          gap: 8,
          padding: '8px 10px',
          borderRadius: 7,
          cursor: 'pointer',
          fontSize: 13.5,
          fontWeight: on ? 600 : 500,
          color: on ? 'var(--mg-primary-900)' : 'var(--c-text)',
          background: on ? 'var(--mg-primary-100)' : 'transparent'
        }
      }, o.label, on && /*#__PURE__*/React.createElement("i", {
        className: "pi pi-check",
        style: {
          marginInlineStart: 'auto',
          fontSize: 12
        }
      }));
    })));
  }
  function A_avatar(name, role, size) {
    const grad = {
      admin: 'linear-gradient(135deg,#4C7DF0,#2B4987)',
      director: 'linear-gradient(135deg,#E6A46E,#C67E3C)',
      cfo: 'linear-gradient(135deg,#E6A46E,#C67E3C)',
      lawyer: 'linear-gradient(135deg,#78B0E0,#4A82BC)',
      manager: 'linear-gradient(135deg,#4FBF8F,#2E9469)',
      accountant: 'linear-gradient(135deg,#9AA3B2,#6C7688)'
    };
    const w = name.trim().split(/\s+/);
    const ini = (w.length >= 2 ? w[0][0] + w[1][0] : w[0].slice(0, 2)).toUpperCase();
    return /*#__PURE__*/React.createElement("span", {
      style: {
        width: size,
        height: size,
        borderRadius: '50%',
        flexShrink: 0,
        background: grad[role] || grad.accountant,
        color: '#fff',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        fontSize: size * 0.36,
        fontWeight: 600
      }
    }, ini);
  }

  /* ── tree helpers ── */
  function A_walkUpdate(nodes, id, fn) {
    return nodes.map(n => n.id === id ? fn(n) : {
      ...n,
      children: A_walkUpdate(n.children, id, fn)
    });
  }
  function A_flatten(nodes, depth, acc) {
    acc = acc || [];
    nodes.forEach(n => {
      acc.push({
        id: n.id,
        name: n.name,
        depth: depth || 0
      });
      A_flatten(n.children, (depth || 0) + 1, acc);
    });
    return acc;
  }
  function A_removeNode(nodes, id) {
    const kept = [];
    let orphans = [];
    nodes.forEach(n => {
      if (n.id === id) {
        orphans = orphans.concat(n.children);
      } else {
        const r = A_removeNode(n.children, id);
        n = {
          ...n,
          children: r.nodes
        };
        orphans = orphans.concat(r.orphans);
        kept.push(n);
      }
    });
    return {
      nodes: kept,
      orphans
    };
  }
  function A_countAll(n) {
    return 1;
  }

  /* ── Department tree (redesigned) ── */
  function A_TreeNode({
    node,
    depth,
    selectedId,
    onSelect,
    onEdit,
    onDelete,
    expanded,
    toggle,
    editing
  }) {
    const isOpen = expanded[node.id] !== false;
    const on = selectedId === node.id;
    return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("div", {
      className: "atnode",
      onClick: () => onSelect(node),
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        padding: '9px 12px',
        paddingInlineStart: 12 + depth * 22,
        borderRadius: 'var(--mg-radius-md)',
        cursor: 'pointer',
        background: on ? 'var(--mg-primary-100)' : 'transparent',
        transition: 'background .12s'
      }
    }, node.children.length > 0 ? /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + (isOpen ? 'pi-chevron-down' : 'pi-chevron-right'),
      onClick: e => {
        e.stopPropagation();
        toggle(node.id);
      },
      style: {
        fontSize: 11,
        color: 'var(--c-muted)',
        width: 14,
        cursor: 'pointer'
      }
    }) : /*#__PURE__*/React.createElement("span", {
      style: {
        width: 14
      }
    }), /*#__PURE__*/React.createElement("i", {
      className: "pi pi-building",
      style: {
        fontSize: 14,
        color: on ? 'var(--mg-primary-900)' : 'var(--c-muted)'
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 13.5,
        fontWeight: on ? 600 : 500,
        color: on ? 'var(--mg-primary-900)' : 'var(--c-text)',
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, node.name), /*#__PURE__*/React.createElement("span", {
      style: {
        display: 'inline-flex',
        alignItems: 'center',
        gap: 4,
        fontSize: 12,
        color: 'var(--c-muted)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-users",
      style: {
        fontSize: 11
      }
    }), node.members.length), editing && /*#__PURE__*/React.createElement("span", {
      className: "atacts",
      style: {
        display: 'inline-flex',
        gap: 2,
        opacity: 0,
        transition: 'opacity .12s'
      }
    }, /*#__PURE__*/React.createElement("button", {
      className: "uicon",
      onClick: e => {
        e.stopPropagation();
        onEdit(node);
      },
      title: "\u0420\u0435\u0434\u0430\u043A\u0442\u0438\u0440\u043E\u0432\u0430\u0442\u044C",
      style: {
        width: 28,
        height: 28,
        borderRadius: 7,
        border: 'none',
        background: 'transparent',
        cursor: 'pointer'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-pencil",
      style: {
        fontSize: 12,
        color: 'var(--c-text2)'
      }
    })), /*#__PURE__*/React.createElement("button", {
      className: "uicon",
      onClick: e => {
        e.stopPropagation();
        onDelete(node);
      },
      title: "\u0423\u0434\u0430\u043B\u0438\u0442\u044C",
      style: {
        width: 28,
        height: 28,
        borderRadius: 7,
        border: 'none',
        background: 'transparent',
        cursor: 'pointer'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-trash",
      style: {
        fontSize: 12,
        color: 'var(--mg-status-danger-text)'
      }
    })))), isOpen && node.children.map(c => /*#__PURE__*/React.createElement(A_TreeNode, {
      key: c.id,
      node: c,
      depth: depth + 1,
      selectedId: selectedId,
      onSelect: onSelect,
      onEdit: onEdit,
      onDelete: onDelete,
      expanded: expanded,
      toggle: toggle,
      editing: editing
    })));
  }

  /* ── Org chart (Схема) ── */
  function A_ChartNode({
    node
  }) {
    const [open, setOpen] = useState(false);
    return /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center'
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: () => setOpen(o => !o),
      style: {
        width: 210,
        background: 'var(--c-card)',
        border: '1px solid ' + (open ? 'var(--mg-primary-900)' : 'var(--c-border2)'),
        borderRadius: 'var(--mg-radius-md)',
        boxShadow: 'var(--mg-shadow-sm)',
        padding: '11px 14px',
        cursor: 'pointer',
        textAlign: 'center'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 13.5,
        fontWeight: 600,
        color: 'var(--c-text)'
      }
    }, node.name), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 12,
        color: 'var(--c-muted)',
        marginTop: 3
      }
    }, node.manager), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'inline-flex',
        alignItems: 'center',
        gap: 5,
        marginTop: 7,
        fontSize: 11.5,
        fontWeight: 600,
        padding: '2px 9px',
        borderRadius: 999,
        background: 'var(--mg-primary-100)',
        color: 'var(--mg-primary-900)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-users",
      style: {
        fontSize: 10
      }
    }), node.members.length, /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + (open ? 'pi-chevron-up' : 'pi-chevron-down'),
      style: {
        fontSize: 9,
        marginInlineStart: 1
      }
    })), open && /*#__PURE__*/React.createElement("div", {
      style: {
        marginTop: 11,
        paddingTop: 11,
        borderTop: '1px solid var(--c-border)',
        display: 'flex',
        flexDirection: 'column',
        gap: 8,
        textAlign: 'start'
      }
    }, node.members.length ? node.members.map(m => /*#__PURE__*/React.createElement("div", {
      key: m.id,
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8
      }
    }, A_avatar(m.full_name, m.role, 24), /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        minWidth: 0,
        fontSize: 12,
        fontWeight: 500,
        color: 'var(--c-text)',
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, m.full_name))) : /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 12,
        color: 'var(--c-muted)'
      }
    }, A_T.dep.noMembers))), node.children.length > 0 && /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("div", {
      style: {
        width: 1,
        height: 20,
        background: 'var(--c-border2)'
      }
    }), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 26,
        position: 'relative',
        paddingTop: 20,
        borderTop: node.children.length > 1 ? '1px solid var(--c-border2)' : 'none',
        alignItems: 'flex-start'
      }
    }, node.children.map(c => /*#__PURE__*/React.createElement("div", {
      key: c.id,
      style: {
        position: 'relative'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        position: 'absolute',
        top: -20,
        insetInlineStart: '50%',
        width: 1,
        height: 20,
        background: 'var(--c-border2)'
      }
    }), /*#__PURE__*/React.createElement(A_ChartNode, {
      node: c
    }))))));
  }

  /* ── Department side panel (drawer) ── */
  function A_SidePanel({
    mode,
    form,
    setForm,
    parentOptions,
    userOptions,
    onClose,
    onSave,
    onOpenPicker,
    onRemoveMember
  }) {
    const set = (k, v) => setForm(s => ({
      ...s,
      [k]: v
    }));
    return /*#__PURE__*/React.createElement("div", {
      onClick: onClose,
      style: {
        position: 'fixed',
        inset: 0,
        zIndex: 1300,
        background: 'rgba(9,16,32,0.45)',
        display: 'flex',
        justifyContent: 'flex-end'
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: e => e.stopPropagation(),
      style: {
        width: 440,
        maxWidth: '92vw',
        height: '100%',
        background: 'var(--c-card)',
        borderInlineStart: '1px solid var(--c-border)',
        boxShadow: 'var(--mg-shadow-lg)',
        display: 'flex',
        flexDirection: 'column'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '16px 20px',
        borderBottom: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 16,
        fontWeight: 600
      }
    }, mode === 'create' ? A_T.dep.add : A_T.dep.edit), /*#__PURE__*/React.createElement("i", {
      className: "pi pi-times",
      onClick: onClose,
      style: {
        fontSize: 14,
        color: 'var(--c-muted)',
        cursor: 'pointer'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        flex: 1,
        overflowY: 'auto',
        padding: 20,
        display: 'flex',
        flexDirection: 'column',
        gap: 16
      }
    }, /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("label", {
      style: A_lbl
    }, A_T.dep.name), /*#__PURE__*/React.createElement("input", {
      className: "inp",
      autoFocus: true,
      value: form.name,
      onChange: e => set('name', e.target.value),
      placeholder: A_T.dep.name,
      style: A_inp
    })), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("label", {
      style: A_lbl
    }, A_T.dep.parent), /*#__PURE__*/React.createElement(A_Dropdown, {
      value: form.parentId,
      onChange: v => set('parentId', v),
      options: [{
        value: null,
        label: A_T.dep.root
      }].concat(parentOptions),
      placeholder: A_T.dep.root
    })), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("label", {
      style: A_lbl
    }, A_T.dep.manager), /*#__PURE__*/React.createElement(A_Dropdown, {
      value: form.managerId,
      onChange: v => set('managerId', v),
      options: userOptions,
      placeholder: "\u2014",
      filter: true
    })), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        marginBottom: 8
      }
    }, /*#__PURE__*/React.createElement("label", {
      style: {
        ...A_lbl,
        margin: 0,
        flex: 1
      }
    }, A_T.dep.members), /*#__PURE__*/React.createElement("button", {
      style: {
        ...A_btn('text'),
        height: 30,
        padding: '0 8px',
        fontSize: 12.5,
        color: 'var(--mg-primary-900)'
      },
      onClick: onOpenPicker
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-plus",
      style: {
        fontSize: 11
      }
    }), A_T.dep.addMember)), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        gap: 6
      }
    }, form.members.length === 0 && /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 13,
        color: 'var(--c-muted)',
        padding: '10px 0'
      }
    }, A_T.dep.noMembers), form.members.map(m => /*#__PURE__*/React.createElement("div", {
      key: m.id,
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '7px 10px',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-md)'
      }
    }, A_avatar(m.full_name, m.role, 28), /*#__PURE__*/React.createElement("div", {
      style: {
        flex: 1,
        minWidth: 0
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 13,
        fontWeight: 500,
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, m.full_name)), /*#__PURE__*/React.createElement("i", {
      className: "pi pi-times",
      onClick: () => onRemoveMember(m.id),
      title: A_T.dep.removeMember,
      style: {
        fontSize: 12,
        color: 'var(--c-muted)',
        cursor: 'pointer'
      }
    })))))), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        justifyContent: 'flex-end',
        gap: 10,
        padding: '14px 20px',
        borderTop: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("button", {
      style: A_btn('ghost'),
      onClick: onClose
    }, "\u041E\u0442\u043C\u0435\u043D\u0430"), /*#__PURE__*/React.createElement("button", {
      style: A_btn('primary'),
      onClick: onSave
    }, "\u0421\u043E\u0445\u0440\u0430\u043D\u0438\u0442\u044C"))));
  }
  const A_lbl = {
    display: 'block',
    fontSize: 13,
    fontWeight: 500,
    color: 'var(--c-text)',
    marginBottom: 6
  };
  const A_inp = {
    width: '100%',
    height: 40,
    padding: '0 13px',
    borderRadius: 'var(--mg-radius-md)',
    border: '1px solid var(--mg-input-border)',
    background: 'var(--c-card)',
    color: 'var(--c-text)',
    fontFamily: 'var(--mg-font-sans)',
    fontSize: 14,
    boxSizing: 'border-box'
  };

  /* ── member picker dialog ── */
  function A_MemberPicker({
    available,
    close,
    onAdd
  }) {
    const [sel, setSel] = useState([]);
    const [q, setQ] = useState('');
    const list = available.filter(u => u.full_name.toLowerCase().includes(q.toLowerCase()));
    const toggle = id => setSel(s => s.includes(id) ? s.filter(x => x !== id) : [...s, id]);
    return /*#__PURE__*/React.createElement("div", {
      onClick: close,
      style: {
        position: 'fixed',
        inset: 0,
        zIndex: 1400,
        background: 'rgba(9,16,32,0.55)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: 20
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: e => e.stopPropagation(),
      style: {
        width: 440,
        maxWidth: '92vw',
        maxHeight: 'calc(100vh - 40px)',
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-lg)',
        display: 'flex',
        flexDirection: 'column'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '16px 20px',
        borderBottom: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 16,
        fontWeight: 600
      }
    }, A_T.dep.addMember), /*#__PURE__*/React.createElement("i", {
      className: "pi pi-times",
      onClick: close,
      style: {
        fontSize: 14,
        color: 'var(--c-muted)',
        cursor: 'pointer'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        padding: '14px 20px'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        height: 40,
        padding: '0 12px',
        border: '1px solid var(--mg-input-border)',
        borderRadius: 'var(--mg-radius-md)',
        background: 'var(--c-page)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-search",
      style: {
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }), /*#__PURE__*/React.createElement("input", {
      autoFocus: true,
      value: q,
      onChange: e => setQ(e.target.value),
      placeholder: "\u041F\u043E\u0438\u0441\u043A\u2026",
      style: {
        flex: 1,
        border: 'none',
        outline: 'none',
        background: 'transparent',
        fontFamily: 'var(--mg-font-sans)',
        fontSize: 14,
        color: 'var(--c-text)'
      }
    }))), /*#__PURE__*/React.createElement("div", {
      style: {
        flex: 1,
        overflowY: 'auto',
        padding: '0 12px 8px'
      }
    }, list.map(u => {
      const on = sel.includes(u.id);
      return /*#__PURE__*/React.createElement("div", {
        key: u.id,
        className: "urow",
        onClick: () => toggle(u.id),
        style: {
          display: 'flex',
          alignItems: 'center',
          gap: 11,
          padding: '8px 10px',
          borderRadius: 8,
          cursor: 'pointer'
        }
      }, /*#__PURE__*/React.createElement(A_Check, {
        checked: on,
        onChange: () => toggle(u.id)
      }), A_avatar(u.full_name, u.role, 30), /*#__PURE__*/React.createElement("div", {
        style: {
          flex: 1,
          minWidth: 0
        }
      }, /*#__PURE__*/React.createElement("div", {
        style: {
          fontSize: 13.5,
          fontWeight: 500
        }
      }, u.full_name), /*#__PURE__*/React.createElement("div", {
        style: {
          fontSize: 12,
          color: 'var(--c-muted)'
        }
      }, u.email)));
    }), list.length === 0 && /*#__PURE__*/React.createElement("div", {
      style: {
        padding: '20px 10px',
        fontSize: 13,
        color: 'var(--c-muted)',
        textAlign: 'center'
      }
    }, "\u041D\u0438\u0447\u0435\u0433\u043E \u043D\u0435 \u043D\u0430\u0439\u0434\u0435\u043D\u043E")), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        justifyContent: 'flex-end',
        gap: 10,
        padding: '14px 20px',
        borderTop: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("button", {
      style: A_btn('ghost'),
      onClick: close
    }, "\u041E\u0442\u043C\u0435\u043D\u0430"), /*#__PURE__*/React.createElement("button", {
      style: {
        ...A_btn('primary'),
        opacity: sel.length ? 1 : 0.5,
        pointerEvents: sel.length ? 'auto' : 'none'
      },
      onClick: () => onAdd(sel)
    }, "\u0414\u043E\u0431\u0430\u0432\u0438\u0442\u044C"))));
  }

  /* ── confirm delete ── */
  function A_Confirm({
    message,
    onAccept,
    close
  }) {
    return /*#__PURE__*/React.createElement("div", {
      onClick: close,
      style: {
        position: 'fixed',
        inset: 0,
        zIndex: 1500,
        background: 'rgba(9,16,32,0.55)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: 20
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: e => e.stopPropagation(),
      style: {
        width: 430,
        maxWidth: '92vw',
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-lg)'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '16px 20px',
        borderBottom: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 16,
        fontWeight: 600
      }
    }, A_T.dep.del), /*#__PURE__*/React.createElement("i", {
      className: "pi pi-times",
      onClick: close,
      style: {
        fontSize: 14,
        color: 'var(--c-muted)',
        cursor: 'pointer'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        padding: 20,
        display: 'flex',
        gap: 14,
        alignItems: 'flex-start'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        width: 40,
        height: 40,
        borderRadius: '50%',
        background: 'var(--mg-status-danger-bg)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        flexShrink: 0
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-exclamation-triangle",
      style: {
        fontSize: 17,
        color: 'var(--mg-status-danger-text)'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 14,
        color: 'var(--c-text2)',
        lineHeight: 1.55,
        paddingTop: 3
      }
    }, message)), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        justifyContent: 'flex-end',
        gap: 10,
        padding: '14px 20px',
        borderTop: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("button", {
      style: A_btn('ghost'),
      onClick: close
    }, "\u041E\u0442\u043C\u0435\u043D\u0430"), /*#__PURE__*/React.createElement("button", {
      style: A_btn('danger'),
      onClick: onAccept
    }, "\u0423\u0434\u0430\u043B\u0438\u0442\u044C"))));
  }
  function A_Toasts({
    items
  }) {
    return /*#__PURE__*/React.createElement("div", {
      style: {
        position: 'fixed',
        insetBlockEnd: 20,
        insetInlineEnd: 20,
        zIndex: 2000,
        display: 'flex',
        flexDirection: 'column',
        gap: 10
      }
    }, items.map(t => /*#__PURE__*/React.createElement("div", {
      key: t.id,
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        minWidth: 240,
        maxWidth: 360,
        padding: '12px 14px',
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderInlineStart: '3px solid var(--mg-status-success-text)',
        borderRadius: 'var(--mg-radius-md)',
        boxShadow: 'var(--mg-shadow-lg)',
        animation: 'toastIn .2s ease'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-check-circle",
      style: {
        fontSize: 16,
        color: 'var(--mg-status-success-text)'
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 13.5,
        fontWeight: 500
      }
    }, t.summary))));
  }

  /* ═══════════════ TABS ═══════════════ */
  function A_DepartmentsTab({
    pushToast
  }) {
    const [tree, setTree] = useState(A_TREE0);
    const [search, setSearch] = useState('');
    const [viewMode, setViewMode] = useState('tree');
    const [selectedId, setSelectedId] = useState(2);
    const [expanded, setExpanded] = useState({});
    const [panel, setPanel] = useState(null);
    const [form, setForm] = useState({
      name: '',
      parentId: null,
      managerId: null,
      members: []
    });
    const [picker, setPicker] = useState(false);
    const [confirm, setConfirm] = useState(null);
    const [editing, setEditing] = useState(false);
    const toggle = id => setExpanded(e => ({
      ...e,
      [id]: e[id] === false ? true : false
    }));
    const findNode = (nodes, id) => {
      for (const n of nodes) {
        if (n.id === id) return n;
        const f = findNode(n.children, id);
        if (f) return f;
      }
      return null;
    };
    const selected = findNode(tree, selectedId);
    const parentOptions = A_flatten(tree).map(d => ({
      value: d.id,
      label: '— '.repeat(d.depth) + d.name
    }));
    const userOptions = A_ALL_USERS.map(u => ({
      value: u.id,
      label: u.full_name
    }));
    function openCreate() {
      setForm({
        name: '',
        parentId: selectedId || null,
        managerId: null,
        members: []
      });
      setPanel('create');
    }
    function openEdit(n) {
      setForm({
        id: n.id,
        name: n.name,
        parentId: null,
        managerId: (A_ALL_USERS.find(u => u.full_name === n.manager) || {}).id ?? null,
        members: n.members.slice()
      });
      setPanel('edit');
    }
    function savePanel() {
      if (!form.name.trim()) return;
      const mgr = (A_ALL_USERS.find(u => u.id === form.managerId) || {}).full_name || '—';
      if (panel === 'edit') {
        setTree(t => A_walkUpdate(t, form.id, n => ({
          ...n,
          name: form.name.trim(),
          manager: mgr,
          members: form.members
        })));
      } else {
        const id = Date.now();
        const node = {
          id,
          name: form.name.trim(),
          manager: mgr,
          members: form.members,
          children: []
        };
        setTree(t => form.parentId ? A_walkUpdate(t, form.parentId, n => ({
          ...n,
          children: [...n.children, node]
        })) : [...t, node]);
        if (form.parentId) setExpanded(e => ({
          ...e,
          [form.parentId]: false
        }));
      }
      pushToast(A_T.dep.saved);
      setPanel(null);
    }
    function doDelete(n) {
      setTree(t => A_removeNode(t, n.id).nodes);
      if (selectedId === n.id) setSelectedId(null);
      pushToast(A_T.dep.deleted);
      setConfirm(null);
    }
    function addMembers(ids) {
      const add = A_ALL_USERS.filter(u => ids.includes(u.id) && !form.members.some(m => m.id === u.id));
      setForm(f => ({
        ...f,
        members: [...f.members, ...add]
      }));
      setPicker(false);
    }
    function removeMember(id) {
      setForm(f => ({
        ...f,
        members: f.members.filter(m => m.id !== id)
      }));
    }
    const q = search.trim().toLowerCase();
    const filterTree = nodes => nodes.map(n => ({
      ...n,
      children: filterTree(n.children)
    })).filter(n => !q || n.name.toLowerCase().includes(q) || n.children.length);
    const shown = filterTree(tree);
    return /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        gap: 16
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        flexWrap: 'wrap'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        height: 40,
        padding: '0 12px',
        flex: 1,
        minWidth: 180,
        maxWidth: 280,
        border: '1px solid var(--mg-input-border)',
        borderRadius: 'var(--mg-radius-md)',
        background: 'var(--c-card)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-search",
      style: {
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }), /*#__PURE__*/React.createElement("input", {
      value: search,
      onChange: e => setSearch(e.target.value),
      placeholder: "\u041F\u043E\u0438\u0441\u043A\u2026",
      style: {
        flex: 1,
        border: 'none',
        outline: 'none',
        background: 'transparent',
        fontFamily: 'var(--mg-font-sans)',
        fontSize: 14,
        color: 'var(--c-text)'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'inline-flex',
        padding: 3,
        gap: 2,
        background: 'var(--c-muted2)',
        borderRadius: 'var(--mg-radius-md)'
      }
    }, [['tree', A_T.dep.viewTree, 'pi-sitemap'], ['chart', A_T.dep.viewChart, 'pi-share-alt']].map(([k, l, ic]) => /*#__PURE__*/React.createElement("button", {
      key: k,
      onClick: () => setViewMode(k),
      style: {
        display: 'inline-flex',
        alignItems: 'center',
        gap: 6,
        height: 32,
        padding: '0 12px',
        border: 'none',
        borderRadius: 'var(--mg-radius-sm)',
        cursor: 'pointer',
        fontFamily: 'var(--mg-font-sans)',
        fontSize: 12.5,
        fontWeight: 600,
        background: viewMode === k ? 'var(--c-card)' : 'transparent',
        color: viewMode === k ? 'var(--mg-primary-900)' : 'var(--c-text2)',
        boxShadow: viewMode === k ? 'var(--mg-shadow-sm)' : 'none'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + ic,
      style: {
        fontSize: 12
      }
    }), l))), /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1
      }
    }), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 10
      }
    }, /*#__PURE__*/React.createElement("button", {
      style: editing ? {
        ...A_btn('ghost'),
        color: 'var(--mg-primary-900)',
        borderColor: 'var(--mg-primary-900)'
      } : A_btn('ghost'),
      onClick: () => setEditing(e => !e)
    }, /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + (editing ? 'pi-check' : 'pi-pencil'),
      style: {
        fontSize: 12
      }
    }), editing ? 'Завершить редактирование' : 'Редактировать'), /*#__PURE__*/React.createElement("button", {
      style: A_btn('primary'),
      onClick: openCreate
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-plus",
      style: {
        fontSize: 12
      }
    }), A_T.dep.add))), viewMode === 'tree' ? /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'grid',
        gridTemplateColumns: '1fr 340px',
        gap: 16,
        alignItems: 'start'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-sm)',
        padding: 8
      }
    }, shown.length ? shown.map(n => /*#__PURE__*/React.createElement(A_TreeNode, {
      key: n.id,
      node: n,
      depth: 0,
      selectedId: selectedId,
      onSelect: d => setSelectedId(d.id),
      onEdit: openEdit,
      onDelete: d => setConfirm(d),
      expanded: expanded,
      toggle: toggle,
      editing: editing
    })) : /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        gap: 10,
        padding: '48px 20px',
        color: 'var(--c-muted)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-building",
      style: {
        fontSize: 28,
        opacity: 0.3
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 14,
        color: 'var(--c-text2)',
        fontWeight: 500
      }
    }, A_T.dep.empty), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 13,
        textAlign: 'center',
        maxWidth: 240
      }
    }, A_T.dep.emptyHint), /*#__PURE__*/React.createElement("button", {
      style: {
        ...A_btn('ghost'),
        marginTop: 4
      },
      onClick: openCreate
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-plus",
      style: {
        fontSize: 12
      }
    }), A_T.dep.add))), selected ? /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-sm)',
        overflow: 'hidden'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '14px 16px',
        borderBottom: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        flex: 1,
        minWidth: 0
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 15,
        fontWeight: 600,
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, selected.name), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 12,
        color: 'var(--c-muted)',
        marginTop: 2
      }
    }, "\u0420\u0443\u043A\u043E\u0432\u043E\u0434\u0438\u0442\u0435\u043B\u044C: ", selected.manager)), editing && /*#__PURE__*/React.createElement("button", {
      className: "uicon",
      onClick: () => openEdit(selected),
      title: "\u0420\u0435\u0434\u0430\u043A\u0442\u0438\u0440\u043E\u0432\u0430\u0442\u044C",
      style: {
        width: 30,
        height: 30,
        borderRadius: 7,
        border: 'none',
        background: 'transparent',
        cursor: 'pointer'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-pencil",
      style: {
        fontSize: 13,
        color: 'var(--c-text2)'
      }
    }))), /*#__PURE__*/React.createElement("div", {
      style: {
        padding: 4
      }
    }, selected.members.length ? selected.members.map(m => /*#__PURE__*/React.createElement("div", {
      key: m.id,
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '8px 12px'
      }
    }, A_avatar(m.full_name, m.role, 30), /*#__PURE__*/React.createElement("div", {
      style: {
        flex: 1,
        minWidth: 0
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 13,
        fontWeight: 500,
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, m.full_name), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 11.5,
        color: 'var(--c-muted)',
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, m.email)), /*#__PURE__*/React.createElement(A_Tag, {
      value: A_ROLE_LABEL[m.role],
      severity: A_ROLE_SEV[m.role]
    }))) : /*#__PURE__*/React.createElement("div", {
      style: {
        padding: '28px 12px',
        fontSize: 13,
        color: 'var(--c-muted)',
        textAlign: 'center'
      }
    }, A_T.dep.noMembers))) : /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px dashed var(--c-border2)',
        borderRadius: 'var(--mg-radius-lg)',
        padding: '40px 20px',
        textAlign: 'center',
        color: 'var(--c-muted)',
        fontSize: 13
      }
    }, "\u0412\u044B\u0431\u0435\u0440\u0438\u0442\u0435 \u043E\u0442\u0434\u0435\u043B, \u0447\u0442\u043E\u0431\u044B \u0443\u0432\u0438\u0434\u0435\u0442\u044C \u0441\u043E\u0442\u0440\u0443\u0434\u043D\u0438\u043A\u043E\u0432")) : /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-page)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        padding: '32px 20px',
        overflowX: 'auto'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 40,
        justifyContent: 'center',
        minWidth: 'min-content',
        alignItems: 'flex-start'
      }
    }, shown.map(n => /*#__PURE__*/React.createElement(A_ChartNode, {
      key: n.id,
      node: n
    })))), panel && /*#__PURE__*/React.createElement(A_SidePanel, {
      mode: panel,
      form: form,
      setForm: setForm,
      parentOptions: parentOptions.filter(o => o.value !== form.id),
      userOptions: userOptions,
      onClose: () => setPanel(null),
      onSave: savePanel,
      onOpenPicker: () => setPicker(true),
      onRemoveMember: removeMember
    }), picker && /*#__PURE__*/React.createElement(A_MemberPicker, {
      available: A_ALL_USERS.filter(u => !form.members.some(m => m.id === u.id)),
      close: () => setPicker(false),
      onAdd: addMembers
    }), confirm && /*#__PURE__*/React.createElement(A_Confirm, {
      message: A_T.dep.delConfirm(confirm.name),
      onAccept: () => doDelete(confirm),
      close: () => setConfirm(null)
    }));
  }
  function A_RolesTab({
    pushToast
  }) {
    const [perms, setPerms] = useState(() => {
      const m = {};
      A_ROLES.forEach(r => {
        m[r] = new Set(A_DEFAULT_PERMS[r]);
      });
      return m;
    });
    const [collapsed, setCollapsed] = useState({});
    const [dirty, setDirty] = useState(false);
    const has = (r, p) => perms[r].has(p);
    function toggle(p, r, v) {
      if (r === 'admin') return;
      setPerms(m => {
        const s = new Set(m[r]);
        v ? s.add(p) : s.delete(p);
        return {
          ...m,
          [r]: s
        };
      });
      setDirty(true);
    }
    function reset() {
      const m = {};
      A_ROLES.forEach(r => {
        m[r] = new Set(A_DEFAULT_PERMS[r]);
      });
      setPerms(m);
      setDirty(false);
    }
    function save() {
      pushToast(A_T.roles.saved);
      setDirty(false);
    }
    const th = {
      padding: '9px 12px',
      fontSize: 11,
      fontWeight: 600,
      letterSpacing: '.02em',
      textTransform: 'uppercase',
      color: 'var(--c-muted)',
      borderBottom: '1px solid var(--c-border)'
    };
    return /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        gap: 16
      }
    }, /*#__PURE__*/React.createElement(A_Msg, {
      severity: "secondary"
    }, A_T.roles.adminNote), A_GROUPS.map(g => {
      const open = !collapsed[g.key];
      return /*#__PURE__*/React.createElement("div", {
        key: g.key,
        style: {
          background: 'var(--c-card)',
          border: '1px solid var(--c-border)',
          borderRadius: 'var(--mg-radius-lg)',
          boxShadow: 'var(--mg-shadow-sm)',
          overflow: 'hidden'
        }
      }, /*#__PURE__*/React.createElement("div", {
        onClick: () => setCollapsed(c => ({
          ...c,
          [g.key]: !c[g.key]
        })),
        style: {
          display: 'flex',
          alignItems: 'center',
          gap: 10,
          padding: '12px 16px',
          cursor: 'pointer',
          background: 'var(--c-hover)',
          borderBottom: open ? '1px solid var(--c-border)' : 'none'
        }
      }, /*#__PURE__*/React.createElement("i", {
        className: 'pi ' + (open ? 'pi-chevron-down' : 'pi-chevron-right'),
        style: {
          fontSize: 12,
          color: 'var(--c-muted)'
        }
      }), /*#__PURE__*/React.createElement("span", {
        style: {
          flex: 1,
          fontSize: 14,
          fontWeight: 600
        }
      }, g.label), /*#__PURE__*/React.createElement("span", {
        style: {
          fontSize: 11.5,
          fontWeight: 600,
          padding: '2px 8px',
          borderRadius: 999,
          background: 'var(--c-muted2)',
          color: 'var(--c-text2)'
        }
      }, g.perms.length)), open && /*#__PURE__*/React.createElement("div", {
        style: {
          overflowX: 'auto'
        }
      }, /*#__PURE__*/React.createElement("table", {
        style: {
          width: '100%',
          borderCollapse: 'collapse',
          minWidth: 760
        }
      }, /*#__PURE__*/React.createElement("thead", null, /*#__PURE__*/React.createElement("tr", null, /*#__PURE__*/React.createElement("th", {
        style: {
          ...th,
          textAlign: 'start',
          minWidth: 260
        }
      }, A_T.roles.permissionLabel), A_ROLES.map(r => /*#__PURE__*/React.createElement("th", {
        key: r,
        style: {
          ...th,
          textAlign: 'center',
          width: 92
        }
      }, A_ROLE_SHORT[r])))), /*#__PURE__*/React.createElement("tbody", null, g.perms.map(p => /*#__PURE__*/React.createElement("tr", {
        key: p,
        className: "urow"
      }, /*#__PURE__*/React.createElement("td", {
        style: {
          padding: '10px 12px',
          borderBottom: '1px solid var(--c-border)'
        }
      }, /*#__PURE__*/React.createElement("div", {
        style: {
          display: 'flex',
          flexDirection: 'column',
          gap: 3
        }
      }, /*#__PURE__*/React.createElement("span", {
        style: {
          fontSize: 13.5,
          color: 'var(--c-text)'
        }
      }, A_PERM_LABEL[p] || p), /*#__PURE__*/React.createElement("code", {
        style: {
          fontFamily: 'ui-monospace,monospace',
          fontSize: 11,
          color: 'var(--c-muted)',
          background: 'var(--c-muted2)',
          padding: '1px 6px',
          borderRadius: 4,
          alignSelf: 'flex-start'
        }
      }, p))), A_ROLES.map(r => /*#__PURE__*/React.createElement("td", {
        key: r,
        style: {
          padding: '10px 12px',
          borderBottom: '1px solid var(--c-border)',
          textAlign: 'center'
        }
      }, /*#__PURE__*/React.createElement("div", {
        style: {
          display: 'flex',
          justifyContent: 'center'
        }
      }, /*#__PURE__*/React.createElement(A_Check, {
        checked: r === 'admin' ? true : has(r, p),
        disabled: r === 'admin',
        onChange: v => toggle(p, r, v)
      }))))))))));
    }), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        justifyContent: 'flex-end',
        gap: 10,
        paddingTop: 2
      }
    }, /*#__PURE__*/React.createElement("button", {
      style: {
        ...A_btn('ghost'),
        opacity: dirty ? 1 : 0.5,
        pointerEvents: dirty ? 'auto' : 'none'
      },
      onClick: reset
    }, A_T.roles.reset), /*#__PURE__*/React.createElement("button", {
      style: {
        ...A_btn('primary'),
        opacity: dirty ? 1 : 0.5,
        pointerEvents: dirty ? 'auto' : 'none'
      },
      onClick: save
    }, A_T.roles.save)));
  }
  function A_VisibilityTab({
    pushToast
  }) {
    const [vis, setVis] = useState(() => ({
      ...A_DEFAULT_VIS
    }));
    const [dirty, setDirty] = useState(false);
    const opts = [{
      value: 'all',
      label: A_T.vis.scopeAll
    }, {
      value: 'department',
      label: A_T.vis.scopeDepartment
    }, {
      value: 'own',
      label: A_T.vis.scopeOwn
    }];
    function setScope(r, v) {
      setVis(m => ({
        ...m,
        [r]: v
      }));
      setDirty(true);
    }
    function reset() {
      setVis({
        ...A_DEFAULT_VIS
      });
      setDirty(false);
    }
    function save() {
      pushToast(A_T.vis.saved);
      setDirty(false);
    }
    const th = {
      padding: '11px 16px',
      fontSize: 11.5,
      fontWeight: 600,
      letterSpacing: '.03em',
      textTransform: 'uppercase',
      color: 'var(--c-muted)',
      textAlign: 'start',
      borderBottom: '1px solid var(--c-border)'
    };
    return /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        gap: 16,
        maxWidth: 640
      }
    }, /*#__PURE__*/React.createElement(A_Msg, {
      severity: "warn"
    }, A_T.vis.warning), /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-sm)',
        overflow: 'hidden'
      }
    }, /*#__PURE__*/React.createElement("table", {
      style: {
        width: '100%',
        borderCollapse: 'collapse'
      }
    }, /*#__PURE__*/React.createElement("thead", null, /*#__PURE__*/React.createElement("tr", null, /*#__PURE__*/React.createElement("th", {
      style: {
        ...th,
        width: 200
      }
    }, A_T.vis.roleColumn), /*#__PURE__*/React.createElement("th", {
      style: th
    }, A_T.vis.scopeColumn))), /*#__PURE__*/React.createElement("tbody", null, A_ROLES.map(r => /*#__PURE__*/React.createElement("tr", {
      key: r,
      className: "urow"
    }, /*#__PURE__*/React.createElement("td", {
      style: {
        padding: '12px 16px',
        borderBottom: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement(A_Tag, {
      value: A_ROLE_LABEL[r],
      severity: A_ROLE_SEV[r]
    })), /*#__PURE__*/React.createElement("td", {
      style: {
        padding: '10px 16px',
        borderBottom: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement(A_Dropdown, {
      value: vis[r],
      onChange: v => setScope(r, v),
      options: opts,
      width: 240
    }))))))), /*#__PURE__*/React.createElement(A_Msg, {
      severity: "secondary"
    }, A_T.vis.departmentHint), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        justifyContent: 'flex-end',
        gap: 10
      }
    }, /*#__PURE__*/React.createElement("button", {
      style: {
        ...A_btn('ghost'),
        opacity: dirty ? 1 : 0.5,
        pointerEvents: dirty ? 'auto' : 'none'
      },
      onClick: reset
    }, A_T.vis.reset), /*#__PURE__*/React.createElement("button", {
      style: {
        ...A_btn('primary'),
        opacity: dirty ? 1 : 0.5,
        pointerEvents: dirty ? 'auto' : 'none'
      },
      onClick: save
    }, A_T.vis.save)));
  }
  function AccessTab() {
    const [tab, setTab] = useState('departments');
    const [toasts, setToasts] = useState([]);
    const pushToast = summary => {
      const id = Date.now() + Math.random();
      setToasts(t => [...t, {
        id,
        summary
      }]);
      setTimeout(() => setToasts(t => t.filter(x => x.id !== id)), 2600);
    };
    const TABS = [['departments', A_T.tabs.departments], ['roles', A_T.tabs.roles], ['visibility', A_T.tabs.visibility]];
    return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("div", {
      style: {
        marginBottom: 18
      }
    }, /*#__PURE__*/React.createElement("h2", {
      style: {
        margin: 0,
        fontSize: 20,
        fontWeight: 600
      }
    }, A_T.title), /*#__PURE__*/React.createElement("p", {
      style: {
        margin: '5px 0 0',
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }, A_T.subtitle)), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 4,
        borderBottom: '1px solid var(--c-border)',
        marginBottom: 20
      }
    }, TABS.map(([k, l]) => {
      const on = tab === k;
      return /*#__PURE__*/React.createElement("button", {
        key: k,
        onClick: () => setTab(k),
        style: {
          background: 'transparent',
          border: 'none',
          cursor: 'pointer',
          padding: '10px 14px',
          marginBottom: -1,
          whiteSpace: 'nowrap',
          fontFamily: 'var(--mg-font-sans)',
          fontSize: 14,
          fontWeight: on ? 600 : 500,
          color: on ? 'var(--mg-primary-900)' : 'var(--c-muted)',
          borderBottom: '2px solid ' + (on ? 'var(--mg-primary-900)' : 'transparent')
        }
      }, l);
    })), tab === 'departments' && /*#__PURE__*/React.createElement(A_DepartmentsTab, {
      pushToast: pushToast
    }), tab === 'roles' && /*#__PURE__*/React.createElement(A_RolesTab, {
      pushToast: pushToast
    }), tab === 'visibility' && /*#__PURE__*/React.createElement(A_VisibilityTab, {
      pushToast: pushToast
    }), /*#__PURE__*/React.createElement(A_Toasts, {
      items: toasts
    }));
  }
  window.AccessTab = AccessTab;
})();
})(); } catch (e) { __ds_ns.__errors.push({ path: "redesign/_dh_update/redesign/access-section.jsx", error: String((e && e.message) || e) }); }

// redesign/_dh_update/redesign/system-section.jsx
try { (() => {
/* System sections for Настройки → Система:
   - Журнал автоматизаций (AutomationsTab): read-only execution log of automation rules.
   - Сброс системы (ResetTab): selective data wipe — checkbox choice of which datasets to delete
     (unlike the product's full-wipe behaviour, per request).
   Built to match the fresh MACRO CRM look (shared --c-* / --mg-* tokens, table th/td, tags).
   Exposes window.AutomationsTab and window.ResetTab. */
(function () {
  const {
    useState
  } = React;

  /* ── shared button/tag helpers (mirror settings.html btn()) ── */
  function sBtn(kind) {
    const base = {
      display: 'inline-flex',
      alignItems: 'center',
      gap: 7,
      height: 38,
      padding: '0 15px',
      borderRadius: 'var(--mg-radius-md)',
      fontFamily: 'var(--mg-font-sans)',
      fontSize: 13,
      fontWeight: 600,
      cursor: 'pointer',
      border: 'none',
      whiteSpace: 'nowrap'
    };
    if (kind === 'primary') return {
      ...base,
      background: 'var(--mg-primary-900)',
      color: '#fff'
    };
    if (kind === 'danger') return {
      ...base,
      background: 'var(--mg-status-danger-solid)',
      color: '#fff'
    };
    return {
      ...base,
      background: 'transparent',
      color: 'var(--c-text2)',
      border: '1px solid var(--c-border2)'
    };
  }
  const sTh = {
    padding: '11px 16px',
    fontSize: 12,
    fontWeight: 600,
    color: 'var(--c-text2)',
    textAlign: 'start',
    borderBottom: '1px solid var(--c-border)',
    whiteSpace: 'nowrap',
    background: 'var(--c-card)'
  };
  const sTd = {
    padding: '12px 16px',
    fontSize: 13.5,
    color: 'var(--c-text)',
    borderBottom: '1px solid var(--c-border)',
    verticalAlign: 'middle'
  };
  function SCheck({
    checked,
    onChange,
    disabled,
    indeterminate
  }) {
    const active = checked || indeterminate;
    return /*#__PURE__*/React.createElement("span", {
      onClick: disabled ? undefined : e => {
        e.stopPropagation();
        onChange();
      },
      role: "checkbox",
      "aria-checked": checked,
      style: {
        width: 20,
        height: 20,
        borderRadius: 6,
        border: '1.5px solid ' + (active ? 'var(--mg-primary-900)' : 'var(--c-border2)'),
        background: active ? 'var(--mg-primary-900)' : 'var(--c-card)',
        display: 'inline-flex',
        alignItems: 'center',
        justifyContent: 'center',
        cursor: disabled ? 'not-allowed' : 'pointer',
        flexShrink: 0,
        opacity: disabled ? 0.45 : 1,
        transition: 'background .12s, border-color .12s'
      }
    }, checked ? /*#__PURE__*/React.createElement("i", {
      className: "pi pi-check",
      style: {
        fontSize: 11,
        color: '#fff'
      }
    }) : indeterminate ? /*#__PURE__*/React.createElement("span", {
      style: {
        width: 9,
        height: 2,
        borderRadius: 1,
        background: '#fff'
      }
    }) : null);
  }

  /* ═══════════════ Журнал автоматизаций ═══════════════ */
  const S_TRIG = {
    created: {
      label: 'Сделка создана',
      ic: 'pi-plus-circle'
    },
    stage: {
      label: 'Этап изменён',
      ic: 'pi-sort-alt'
    },
    overdue: {
      label: 'Задача просрочена',
      ic: 'pi-exclamation-circle'
    },
    webform: {
      label: 'Заявка с сайта',
      ic: 'pi-globe'
    },
    lost: {
      label: 'Статус «Отказ»',
      ic: 'pi-times-circle'
    },
    product: {
      label: 'Товар добавлен',
      ic: 'pi-box'
    },
    contact: {
      label: 'Контакт создан',
      ic: 'pi-user-plus'
    },
    noreply: {
      label: 'Нет ответа 24ч',
      ic: 'pi-clock'
    },
    won: {
      label: 'Сделка выиграна',
      ic: 'pi-check-circle'
    },
    birthday: {
      label: 'Дата рождения',
      ic: 'pi-calendar'
    }
  };
  const S_LOG = [{
    time: '02.07 · 09:41',
    name: 'Приветственное сообщение',
    trig: 'created',
    obj: 'Сделка #10485',
    link: true,
    action: 'Отправлено сообщение в Telegram',
    status: 'ok'
  }, {
    time: '02.07 · 09:38',
    name: 'Задача при смене этапа',
    trig: 'stage',
    obj: 'Сделка #10477',
    link: true,
    action: 'Создана задача «Позвонить клиенту»',
    status: 'ok'
  }, {
    time: '02.07 · 09:36',
    name: 'Автоназначение ответственного',
    trig: 'webform',
    obj: 'Сделка #10484',
    link: true,
    action: 'Назначен Иванов А. С.',
    status: 'ok'
  }, {
    time: '02.07 · 09:30',
    name: 'Уведомление при отказе',
    trig: 'lost',
    obj: 'Сделка #10460',
    link: true,
    action: 'Email руководителю — SMTP timeout',
    status: 'err'
  }, {
    time: '02.07 · 09:24',
    name: 'Пересчёт суммы сделки',
    trig: 'product',
    obj: 'Сделка #10483',
    link: true,
    action: 'Обновлено поле «Сумма» → 480 000 ₽',
    status: 'ok'
  }, {
    time: '02.07 · 09:19',
    name: 'Напоминание о просрочке',
    trig: 'overdue',
    obj: 'Задача #3391',
    link: false,
    action: 'Уведомление ответственному',
    status: 'ok'
  }, {
    time: '02.07 · 09:12',
    name: 'Дедупликация контактов',
    trig: 'contact',
    obj: 'Контакт «Петров И.»',
    link: true,
    action: 'Найден дубль — объединено',
    status: 'ok'
  }, {
    time: '02.07 · 08:58',
    name: 'SLA-эскалация',
    trig: 'noreply',
    obj: 'Сделка #10471',
    link: true,
    action: 'Правило отключено — пропущено',
    status: 'skip'
  }, {
    time: '02.07 · 08:45',
    name: 'Синхронизация с 1С',
    trig: 'won',
    obj: 'Сделка #10455',
    link: true,
    action: 'Выгрузка в 1С — нет связи с сервером',
    status: 'err'
  }, {
    time: '02.07 · 08:30',
    name: 'Поздравление с днём рождения',
    trig: 'birthday',
    obj: 'Контакт «Сидорова М.»',
    link: true,
    action: 'Email отправлен',
    status: 'ok'
  }];
  const S_STATUS = {
    ok: {
      label: 'Успешно',
      bg: 'var(--mg-status-success-bg)',
      fg: 'var(--mg-status-success-text)',
      ic: 'pi-check-circle'
    },
    err: {
      label: 'Ошибка',
      bg: 'var(--mg-status-danger-bg)',
      fg: 'var(--mg-status-danger-text)',
      ic: 'pi-times-circle'
    },
    skip: {
      label: 'Пропущено',
      bg: 'var(--c-muted2)',
      fg: 'var(--c-text2)',
      ic: 'pi-minus-circle'
    }
  };
  function AutomationsTab() {
    const [q, setQ] = useState('');
    const [f, setF] = useState('all');
    const counts = {
      all: S_LOG.length,
      ok: 0,
      err: 0,
      skip: 0
    };
    S_LOG.forEach(r => counts[r.status]++);
    const ql = q.trim().toLowerCase();
    const rows = S_LOG.filter(r => (f === 'all' || r.status === f) && (!ql || r.name.toLowerCase().includes(ql) || r.obj.toLowerCase().includes(ql) || S_TRIG[r.trig].label.toLowerCase().includes(ql)));
    return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("h2", {
      style: {
        margin: '2px 0 4px',
        fontSize: 22,
        fontWeight: 600
      }
    }, "\u0416\u0443\u0440\u043D\u0430\u043B \u0430\u0432\u0442\u043E\u043C\u0430\u0442\u0438\u0437\u0430\u0446\u0438\u0439"), /*#__PURE__*/React.createElement("p", {
      style: {
        margin: '0 0 20px',
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }, "\u0418\u0441\u0442\u043E\u0440\u0438\u044F \u0441\u0440\u0430\u0431\u0430\u0442\u044B\u0432\u0430\u043D\u0438\u044F \u043F\u0440\u0430\u0432\u0438\u043B \u0430\u0432\u0442\u043E\u043C\u0430\u0442\u0438\u0437\u0430\u0446\u0438\u0438 \u0437\u0430 \u043F\u043E\u0441\u043B\u0435\u0434\u043D\u0438\u0435 24 \u0447\u0430\u0441\u0430"), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        flexWrap: 'wrap',
        marginBottom: 14
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        height: 40,
        padding: '0 12px',
        flex: 1,
        minWidth: 200,
        maxWidth: 300,
        border: '1px solid var(--mg-input-border)',
        borderRadius: 'var(--mg-radius-md)',
        background: 'var(--c-card)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-search",
      style: {
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }), /*#__PURE__*/React.createElement("input", {
      value: q,
      onChange: e => setQ(e.target.value),
      placeholder: "\u041F\u043E\u0438\u0441\u043A \u043F\u043E \u043F\u0440\u0430\u0432\u0438\u043B\u0443 \u0438\u043B\u0438 \u043E\u0431\u044A\u0435\u043A\u0442\u0443\u2026",
      style: {
        flex: 1,
        border: 'none',
        outline: 'none',
        background: 'transparent',
        fontFamily: 'var(--mg-font-sans)',
        fontSize: 14,
        color: 'var(--c-text)'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'inline-flex',
        padding: 3,
        gap: 2,
        background: 'var(--c-muted2)',
        borderRadius: 'var(--mg-radius-md)'
      }
    }, [['all', 'Все'], ['ok', 'Успешно'], ['err', 'Ошибка'], ['skip', 'Пропущено']].map(([k, l]) => /*#__PURE__*/React.createElement("button", {
      key: k,
      onClick: () => setF(k),
      style: {
        display: 'inline-flex',
        alignItems: 'center',
        gap: 6,
        height: 32,
        padding: '0 12px',
        border: 'none',
        borderRadius: 'var(--mg-radius-sm)',
        cursor: 'pointer',
        fontFamily: 'var(--mg-font-sans)',
        fontSize: 12.5,
        fontWeight: 600,
        background: f === k ? 'var(--c-card)' : 'transparent',
        color: f === k ? 'var(--mg-primary-900)' : 'var(--c-text2)',
        boxShadow: f === k ? 'var(--mg-shadow-sm)' : 'none'
      }
    }, l, /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 11,
        color: 'var(--c-muted)'
      }
    }, counts[k])))), /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1
      }
    }), /*#__PURE__*/React.createElement("button", {
      style: sBtn('ghost')
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-refresh",
      style: {
        fontSize: 12
      }
    }), "\u041E\u0431\u043D\u043E\u0432\u0438\u0442\u044C")), /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-sm)',
        overflow: 'hidden'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        overflowX: 'auto'
      }
    }, /*#__PURE__*/React.createElement("table", {
      style: {
        width: '100%',
        borderCollapse: 'collapse',
        minWidth: 860
      }
    }, /*#__PURE__*/React.createElement("thead", null, /*#__PURE__*/React.createElement("tr", null, /*#__PURE__*/React.createElement("th", {
      style: {
        ...sTh,
        width: 108
      }
    }, "\u0412\u0440\u0435\u043C\u044F"), /*#__PURE__*/React.createElement("th", {
      style: sTh
    }, "\u0410\u0432\u0442\u043E\u043C\u0430\u0442\u0438\u0437\u0430\u0446\u0438\u044F"), /*#__PURE__*/React.createElement("th", {
      style: sTh
    }, "\u0422\u0440\u0438\u0433\u0433\u0435\u0440"), /*#__PURE__*/React.createElement("th", {
      style: sTh
    }, "\u041E\u0431\u044A\u0435\u043A\u0442"), /*#__PURE__*/React.createElement("th", {
      style: sTh
    }, "\u0414\u0435\u0439\u0441\u0442\u0432\u0438\u0435"), /*#__PURE__*/React.createElement("th", {
      style: {
        ...sTh,
        width: 130
      }
    }, "\u0421\u0442\u0430\u0442\u0443\u0441"))), /*#__PURE__*/React.createElement("tbody", null, rows.map((r, i) => {
      const st = S_STATUS[r.status];
      const tg = S_TRIG[r.trig];
      return /*#__PURE__*/React.createElement("tr", {
        key: i,
        className: "urow",
        style: {
          background: i % 2 ? 'var(--c-hover)' : 'var(--c-card)'
        }
      }, /*#__PURE__*/React.createElement("td", {
        style: {
          ...sTd,
          fontFamily: 'ui-monospace,monospace',
          fontSize: 12,
          color: 'var(--c-muted)',
          whiteSpace: 'nowrap'
        }
      }, r.time), /*#__PURE__*/React.createElement("td", {
        style: {
          ...sTd,
          fontWeight: 600
        }
      }, r.name), /*#__PURE__*/React.createElement("td", {
        style: sTd
      }, /*#__PURE__*/React.createElement("span", {
        style: {
          display: 'inline-flex',
          alignItems: 'center',
          gap: 6,
          fontSize: 12.5,
          fontWeight: 500,
          padding: '3px 9px',
          borderRadius: 6,
          background: 'var(--c-muted2)',
          color: 'var(--c-text2)',
          whiteSpace: 'nowrap'
        }
      }, /*#__PURE__*/React.createElement("i", {
        className: 'pi ' + tg.ic,
        style: {
          fontSize: 11
        }
      }), tg.label)), /*#__PURE__*/React.createElement("td", {
        style: {
          ...sTd,
          whiteSpace: 'nowrap'
        }
      }, /*#__PURE__*/React.createElement("span", {
        style: {
          color: r.link ? 'var(--mg-primary-900)' : 'var(--c-text)',
          fontWeight: r.link ? 500 : 400,
          cursor: r.link ? 'pointer' : 'default'
        }
      }, r.obj)), /*#__PURE__*/React.createElement("td", {
        style: {
          ...sTd,
          color: 'var(--c-text2)'
        }
      }, r.action), /*#__PURE__*/React.createElement("td", {
        style: sTd
      }, /*#__PURE__*/React.createElement("span", {
        style: {
          display: 'inline-flex',
          alignItems: 'center',
          gap: 6,
          fontSize: 12,
          fontWeight: 600,
          padding: '3px 10px',
          borderRadius: 999,
          background: st.bg,
          color: st.fg,
          whiteSpace: 'nowrap'
        }
      }, /*#__PURE__*/React.createElement("i", {
        className: 'pi ' + st.ic,
        style: {
          fontSize: 11
        }
      }), st.label)));
    })))), rows.length === 0 && /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        gap: 8,
        padding: '48px 20px',
        color: 'var(--c-muted)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-clock",
      style: {
        fontSize: 26,
        opacity: 0.4
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 14,
        color: 'var(--c-text2)',
        fontWeight: 500
      }
    }, "\u0417\u0430\u043F\u0438\u0441\u0438 \u043D\u0435 \u043D\u0430\u0439\u0434\u0435\u043D\u044B"))));
  }

  /* ═══════════════ Сброс системы ═══════════════ */
  const S_CATS = [{
    key: 'deals',
    icon: 'pi-briefcase',
    name: 'Сделки',
    desc: 'Все сделки и их история изменений',
    count: '1 248 записей'
  }, {
    key: 'contacts',
    icon: 'pi-user',
    name: 'Контакты',
    desc: 'Контактные лица и их данные',
    count: '3 972 записи'
  }, {
    key: 'companies',
    icon: 'pi-building',
    name: 'Компании',
    desc: 'Организации-клиенты',
    count: '864 записи'
  }, {
    key: 'tasks',
    icon: 'pi-check-square',
    name: 'Задачи и активности',
    desc: 'Задачи, звонки, встречи, заметки',
    count: '5 310 записей'
  }, {
    key: 'docs',
    icon: 'pi-file',
    name: 'Документы и файлы',
    desc: 'Договоры и вложения',
    count: '2 140 файлов'
  }, {
    key: 'finance',
    icon: 'pi-wallet',
    name: 'Финансовые операции',
    desc: 'Платежи, счета, проводки',
    count: '918 записей'
  }, {
    key: 'logs',
    icon: 'pi-history',
    name: 'Журналы и история',
    desc: 'Журнал автоматизаций и аудита',
    count: '42 300 событий'
  }, {
    key: 'automations',
    icon: 'pi-bolt',
    name: 'Правила автоматизаций',
    desc: 'Настроенные сценарии',
    count: '36 правил'
  }, {
    key: 'directories',
    icon: 'pi-folder-open',
    name: 'Справочники',
    desc: 'Товары, теги, поля, курсы валют',
    count: '512 записей'
  }];
  const S_CONFIRM_WORD = 'СБРОСИТЬ';
  function ResetTab() {
    const [sel, setSel] = useState({});
    const [word, setWord] = useState('');
    const [confirm, setConfirm] = useState(false);
    const [done, setDone] = useState(false);
    const selKeys = S_CATS.filter(c => sel[c.key]).map(c => c.key);
    const n = selKeys.length;
    const allOn = n === S_CATS.length;
    const toggle = k => setSel(s => ({
      ...s,
      [k]: !s[k]
    }));
    const toggleAll = () => {
      const v = !allOn;
      const m = {};
      S_CATS.forEach(c => {
        m[c.key] = v;
      });
      setSel(m);
    };
    const canReset = n > 0 && word.trim().toUpperCase() === S_CONFIRM_WORD;
    function doReset() {
      setConfirm(false);
      setDone(true);
      setSel({});
      setWord('');
      setTimeout(() => setDone(false), 3600);
    }
    return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("h2", {
      style: {
        margin: '2px 0 4px',
        fontSize: 22,
        fontWeight: 600
      }
    }, "\u0421\u0431\u0440\u043E\u0441 \u0441\u0438\u0441\u0442\u0435\u043C\u044B"), /*#__PURE__*/React.createElement("p", {
      style: {
        margin: '0 0 20px',
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }, "\u0412\u044B\u0431\u043E\u0440\u043E\u0447\u043D\u043E\u0435 \u0443\u0434\u0430\u043B\u0435\u043D\u0438\u0435 \u0434\u0430\u043D\u043D\u044B\u0445 \u0438\u0437 \u0441\u0438\u0441\u0442\u0435\u043C\u044B. \u041D\u0430\u0441\u0442\u0440\u043E\u0439\u043A\u0438 \u0430\u043A\u043A\u0430\u0443\u043D\u0442\u0430 \u0438 \u0443\u0447\u0451\u0442\u043D\u044B\u0435 \u0437\u0430\u043F\u0438\u0441\u0438 \u0441\u043E\u0445\u0440\u0430\u043D\u044F\u044E\u0442\u0441\u044F."), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 12,
        alignItems: 'flex-start',
        padding: '14px 16px',
        marginBottom: 16,
        background: 'var(--mg-status-danger-bg)',
        border: '1px solid var(--mg-status-danger-border)',
        borderRadius: 'var(--mg-radius-lg)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-exclamation-triangle",
      style: {
        fontSize: 18,
        color: 'var(--mg-status-danger-text)',
        marginTop: 1,
        flexShrink: 0
      }
    }), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 13.5,
        color: 'var(--c-text2)',
        lineHeight: 1.55
      }
    }, /*#__PURE__*/React.createElement("b", {
      style: {
        color: 'var(--mg-status-danger-text)',
        fontWeight: 600
      }
    }, "\u041E\u043F\u0435\u0440\u0430\u0446\u0438\u044F \u043D\u0435\u043E\u0431\u0440\u0430\u0442\u0438\u043C\u0430."), " \u0412\u044B\u0431\u0440\u0430\u043D\u043D\u044B\u0435 \u0434\u0430\u043D\u043D\u044B\u0435 \u0431\u0443\u0434\u0443\u0442 \u0443\u0434\u0430\u043B\u0435\u043D\u044B \u0431\u0435\u0437\u0432\u043E\u0437\u0432\u0440\u0430\u0442\u043D\u043E. \u041F\u0435\u0440\u0435\u0434 \u0441\u0431\u0440\u043E\u0441\u043E\u043C \u0440\u0435\u043A\u043E\u043C\u0435\u043D\u0434\u0443\u0435\u043C \u0432\u044B\u0433\u0440\u0443\u0437\u0438\u0442\u044C \u0440\u0435\u0437\u0435\u0440\u0432\u043D\u0443\u044E \u043A\u043E\u043F\u0438\u044E.")), /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-sm)',
        overflow: 'hidden',
        marginBottom: 16
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: toggleAll,
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 12,
        padding: '13px 16px',
        borderBottom: '1px solid var(--c-border)',
        background: 'var(--c-hover)',
        cursor: 'pointer'
      }
    }, /*#__PURE__*/React.createElement(SCheck, {
      checked: allOn,
      indeterminate: n > 0 && !allOn,
      onChange: toggleAll
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 13.5,
        fontWeight: 600
      }
    }, "\u0412\u044B\u0431\u0440\u0430\u0442\u044C \u0432\u0441\u0451"), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 12.5,
        color: 'var(--c-muted)',
        whiteSpace: 'nowrap',
        flexShrink: 0
      }
    }, "\u0412\u044B\u0431\u0440\u0430\u043D\u043E: ", n, " \u0438\u0437 ", S_CATS.length)), S_CATS.map((c, i) => {
      const on = !!sel[c.key];
      return /*#__PURE__*/React.createElement("div", {
        key: c.key,
        onClick: () => toggle(c.key),
        style: {
          display: 'flex',
          alignItems: 'center',
          gap: 13,
          padding: '13px 16px',
          borderBottom: i < S_CATS.length - 1 ? '1px solid var(--c-border)' : 'none',
          cursor: 'pointer',
          background: on ? 'var(--mg-primary-100)' : 'transparent',
          transition: 'background .12s'
        }
      }, /*#__PURE__*/React.createElement(SCheck, {
        checked: on,
        onChange: () => toggle(c.key)
      }), /*#__PURE__*/React.createElement("span", {
        style: {
          width: 36,
          height: 36,
          borderRadius: 'var(--mg-radius-md)',
          background: 'var(--c-muted2)',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          flexShrink: 0
        }
      }, /*#__PURE__*/React.createElement("i", {
        className: 'pi ' + c.icon,
        style: {
          fontSize: 15,
          color: 'var(--c-text2)'
        }
      })), /*#__PURE__*/React.createElement("div", {
        style: {
          flex: 1,
          minWidth: 0
        }
      }, /*#__PURE__*/React.createElement("div", {
        style: {
          fontSize: 14,
          fontWeight: 600,
          color: 'var(--c-text)'
        }
      }, c.name), /*#__PURE__*/React.createElement("div", {
        style: {
          fontSize: 12.5,
          color: 'var(--c-muted)',
          marginTop: 2
        }
      }, c.desc)), /*#__PURE__*/React.createElement("span", {
        style: {
          fontSize: 12,
          fontWeight: 600,
          padding: '3px 10px',
          borderRadius: 999,
          background: 'var(--c-muted2)',
          color: 'var(--c-text2)',
          whiteSpace: 'nowrap'
        }
      }, c.count));
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-sm)',
        padding: '18px 20px'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 14,
        fontWeight: 600,
        marginBottom: 4
      }
    }, "\u041F\u043E\u0434\u0442\u0432\u0435\u0440\u0436\u0434\u0435\u043D\u0438\u0435"), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 13,
        color: 'var(--c-muted)',
        marginBottom: 12
      }
    }, "\u0427\u0442\u043E\u0431\u044B \u0443\u0434\u0430\u043B\u0438\u0442\u044C ", n > 0 ? 'выбранные данные (' + n + ')' : 'выбранные данные', ", \u0432\u0432\u0435\u0434\u0438\u0442\u0435 ", /*#__PURE__*/React.createElement("b", {
      style: {
        color: 'var(--c-text2)',
        fontFamily: 'ui-monospace,monospace'
      }
    }, S_CONFIRM_WORD), " \u0432 \u043F\u043E\u043B\u0435 \u043D\u0438\u0436\u0435."), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 12,
        alignItems: 'center',
        flexWrap: 'wrap'
      }
    }, /*#__PURE__*/React.createElement("input", {
      className: "inp",
      value: word,
      onChange: e => setWord(e.target.value),
      placeholder: S_CONFIRM_WORD,
      style: {
        flex: 1,
        minWidth: 200,
        maxWidth: 280,
        height: 40,
        padding: '0 13px',
        borderRadius: 'var(--mg-radius-md)',
        border: '1px solid ' + (word && !canReset && n > 0 ? 'var(--mg-status-danger-border)' : 'var(--mg-input-border)'),
        background: 'var(--c-card)',
        color: 'var(--c-text)',
        fontFamily: 'ui-monospace,monospace',
        fontSize: 14,
        letterSpacing: '0.05em'
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1
      }
    }), /*#__PURE__*/React.createElement("button", {
      disabled: !canReset,
      onClick: () => setConfirm(true),
      style: {
        ...sBtn('danger'),
        opacity: canReset ? 1 : 0.45,
        pointerEvents: canReset ? 'auto' : 'none'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-trash",
      style: {
        fontSize: 13
      }
    }), "\u0423\u0434\u0430\u043B\u0438\u0442\u044C \u0432\u044B\u0431\u0440\u0430\u043D\u043D\u043E\u0435", n > 0 ? ' (' + n + ')' : ''))), confirm && /*#__PURE__*/React.createElement("div", {
      onClick: () => setConfirm(false),
      style: {
        position: 'fixed',
        inset: 0,
        zIndex: 1500,
        background: 'rgba(9,16,32,0.55)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: 20
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: e => e.stopPropagation(),
      style: {
        width: 460,
        maxWidth: '92vw',
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-lg)'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '16px 20px',
        borderBottom: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 16,
        fontWeight: 600
      }
    }, "\u0423\u0434\u0430\u043B\u0438\u0442\u044C \u0434\u0430\u043D\u043D\u044B\u0435?"), /*#__PURE__*/React.createElement("i", {
      className: "pi pi-times",
      onClick: () => setConfirm(false),
      style: {
        fontSize: 14,
        color: 'var(--c-muted)',
        cursor: 'pointer'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        padding: 20,
        display: 'flex',
        gap: 14,
        alignItems: 'flex-start'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        width: 42,
        height: 42,
        borderRadius: '50%',
        background: 'var(--mg-status-danger-bg)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        flexShrink: 0
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-exclamation-triangle",
      style: {
        fontSize: 18,
        color: 'var(--mg-status-danger-text)'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 14,
        color: 'var(--c-text2)',
        lineHeight: 1.55,
        paddingTop: 2
      }
    }, "\u0411\u0443\u0434\u0435\u0442 \u0431\u0435\u0437\u0432\u043E\u0437\u0432\u0440\u0430\u0442\u043D\u043E \u0443\u0434\u0430\u043B\u0435\u043D\u043E ", /*#__PURE__*/React.createElement("b", {
      style: {
        color: 'var(--c-text)'
      }
    }, n), " ", n === 1 ? 'категория' : n < 5 ? 'категории' : 'категорий', " \u0434\u0430\u043D\u043D\u044B\u0445: ", selKeys.map(k => (S_CATS.find(c => c.key === k) || {}).name).join(', '), ". \u041F\u0440\u043E\u0434\u043E\u043B\u0436\u0438\u0442\u044C?")), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        justifyContent: 'flex-end',
        gap: 10,
        padding: '14px 20px',
        borderTop: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("button", {
      style: sBtn('ghost'),
      onClick: () => setConfirm(false)
    }, "\u041E\u0442\u043C\u0435\u043D\u0430"), /*#__PURE__*/React.createElement("button", {
      style: sBtn('danger'),
      onClick: doReset
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-trash",
      style: {
        fontSize: 13
      }
    }), "\u0423\u0434\u0430\u043B\u0438\u0442\u044C \u0431\u0435\u0437\u0432\u043E\u0437\u0432\u0440\u0430\u0442\u043D\u043E")))), done && /*#__PURE__*/React.createElement("div", {
      style: {
        position: 'fixed',
        insetBlockEnd: 20,
        insetInlineEnd: 20,
        zIndex: 2000,
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        minWidth: 260,
        padding: '12px 14px',
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderInlineStart: '3px solid var(--mg-status-success-text)',
        borderRadius: 'var(--mg-radius-md)',
        boxShadow: 'var(--mg-shadow-lg)',
        animation: 'toastIn .2s ease'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-check-circle",
      style: {
        fontSize: 16,
        color: 'var(--mg-status-success-text)'
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 13.5,
        fontWeight: 500
      }
    }, "\u0412\u044B\u0431\u0440\u0430\u043D\u043D\u044B\u0435 \u0434\u0430\u043D\u043D\u044B\u0435 \u0443\u0434\u0430\u043B\u0435\u043D\u044B")));
  }
  window.AutomationsTab = AutomationsTab;
  window.ResetTab = ResetTab;
})();
})(); } catch (e) { __ds_ns.__errors.push({ path: "redesign/_dh_update/redesign/system-section.jsx", error: String((e && e.message) || e) }); }

// redesign/_dh_update/redesign/users-section.jsx
try { (() => {
/* Users section for Настройки → Система → Пользователи
   Mirrors front/src/pages/UsersPage (index.vue + composable + dialogs, ru.json),
   redesigned to the fresh MACRO CRM look. Exposes window.UsersTab. */
(function () {
  const {
    useState,
    useRef,
    useEffect
  } = React;
  const U_T = {
    title: 'Пользователи',
    subtitle: 'Управление учётными записями сотрудников',
    addUser: 'Добавить пользователя',
    editUser: 'Редактировать пользователя',
    active: 'Активен',
    inactive: 'Неактивен',
    passwordHint: 'Если не задать пароль, он будет сгенерирован автоматически. Сообщите учётные данные сотруднику.',
    fields: {
      full_name: 'ФИО',
      full_name_ph: 'Иванов Иван Иванович',
      email: 'Email',
      email_ph: 'user@company.com',
      phone: 'Телефон',
      phone_ph: '+7 (999) 000-00-00',
      job_title: 'Должность',
      job_title_ph: 'Менеджер по продажам',
      department: 'Отдел',
      department_ph: 'Выберите отдел',
      manager: 'Руководитель',
      manager_ph: 'Выберите руководителя',
      role: 'Роль',
      role_ph: 'Выберите роль (по умолчанию: Менеджер)',
      password: 'Пароль',
      password_ph: 'Оставьте пустым для автогенерации',
      password_edit_ph: 'Оставьте пустым, чтобы не менять'
    },
    filters: {
      search: 'Поиск по имени, email…',
      role: 'Роль',
      department: 'Отдел',
      status: 'Статус',
      active: 'Активен',
      inactive: 'Неактивен'
    },
    deactivate: 'Деактивировать',
    deactivateTitle: 'Деактивация пользователя',
    deactivateConfirm: n => 'Деактивировать пользователя «' + n + '»? Он не сможет войти в систему, но останется в истории.',
    activate: 'Активировать',
    reset: {
      action: 'Сбросить пароль',
      title: 'Сброс пароля',
      confirm: n => 'Сбросить пароль пользователя «' + n + '»? Текущий пароль перестанет работать, будет сгенерирован новый.',
      resultTitle: 'Новый пароль сгенерирован',
      oneTimeWarning: 'Сохраните пароль — он показывается только один раз. Передайте его пользователю по защищённому каналу.',
      newPassword: 'Новый пароль',
      copy: 'Копировать пароль'
    }
  };
  const U_ROLE_LABEL = {
    admin: 'Администратор',
    director: 'Директор',
    manager: 'Менеджер',
    lawyer: 'Юрист',
    accountant: 'Бухгалтер',
    cfo: 'Финансовый директор'
  };
  const U_ROLE_SEV = {
    admin: 'danger',
    director: 'warn',
    lawyer: 'info',
    cfo: 'warn',
    manager: 'secondary',
    accountant: 'secondary'
  };
  const U_ROLE_OPTS = ['admin', 'director', 'manager', 'lawyer', 'accountant', 'cfo'].map(v => ({
    value: v,
    label: U_ROLE_LABEL[v]
  }));
  const U_ROLE_GRAD = {
    admin: 'linear-gradient(135deg,#4C7DF0,#2B4987)',
    director: 'linear-gradient(135deg,#E6A46E,#C67E3C)',
    cfo: 'linear-gradient(135deg,#E6A46E,#C67E3C)',
    lawyer: 'linear-gradient(135deg,#78B0E0,#4A82BC)',
    manager: 'linear-gradient(135deg,#4FBF8F,#2E9469)',
    accountant: 'linear-gradient(135deg,#9AA3B2,#6C7688)'
  };
  const U_DEPTS = ['Отдел продаж', 'Бухгалтерия', 'Финансы', 'Юридический отдел', 'Руководство'].map((n, i) => ({
    id: i + 1,
    name: n
  }));
  const U_ME = 2;
  const U_USERS = [{
    id: 1,
    full_name: 'Admin',
    email: 'svkv42@gmail.com',
    phone: '+7 (495) 120-33-01',
    job_title: null,
    department_name: null,
    role: 'admin',
    is_active: true
  }, {
    id: 2,
    full_name: 'Bogdan Yadykin',
    email: 'b.yadykin@macroglobaltech.com',
    phone: '+7 (495) 120-33-02',
    job_title: null,
    department_name: null,
    role: 'admin',
    is_active: true
  }, {
    id: 3,
    full_name: 'Lawyer Test',
    email: 'lawyer@mgcrm.test',
    phone: '+7 (495) 120-33-03',
    job_title: null,
    department_name: null,
    role: 'lawyer',
    is_active: true
  }, {
    id: 4,
    full_name: 'MG CRM Admin',
    email: 'admin@mgcrm.test',
    phone: '+7 (495) 120-33-04',
    job_title: null,
    department_name: null,
    role: 'admin',
    is_active: true
  }, {
    id: 5,
    full_name: 'Георгий Некрасов',
    email: 'g.nekrasov@macroglobaltech.com',
    phone: '+7 (701) 555-21-05',
    job_title: 'Менеджер по продажам',
    department_name: 'Отдел продаж',
    role: 'manager',
    is_active: true
  }, {
    id: 6,
    full_name: 'Директор Петров П.П.',
    email: 'director@mgcrm.test',
    phone: '+7 (701) 555-21-06',
    job_title: 'Директор по продажам',
    department_name: 'Отдел продаж',
    role: 'director',
    is_active: true
  }, {
    id: 7,
    full_name: 'Иванов Алексей Сергеевич',
    email: 'manager1@mgcrm.test',
    phone: '+7 (701) 555-21-07',
    job_title: 'Менеджер по продажам',
    department_name: 'Отдел продаж',
    role: 'manager',
    is_active: true
  }, {
    id: 8,
    full_name: 'Илья Рогов',
    email: 'ilyarogov.mera@gmail.com',
    phone: '+7 (701) 555-21-08',
    job_title: 'Менеджер по продажам',
    department_name: 'Отдел продаж',
    role: 'manager',
    is_active: true
  }, {
    id: 9,
    full_name: 'Клим Федорин',
    email: 'k.fedorin@macroglobaltech.com',
    phone: '+7 (701) 555-21-09',
    job_title: 'Менеджер по продажам',
    department_name: 'Отдел продаж',
    role: 'manager',
    is_active: true
  }, {
    id: 10,
    full_name: 'Олеся Моисеева',
    email: 'o.moiseeva@macroglobaltech.com',
    phone: '+7 (701) 555-21-10',
    job_title: 'Менеджер по продажам',
    department_name: 'Отдел продаж',
    role: 'manager',
    is_active: true
  }, {
    id: 11,
    full_name: 'Петрова Мария Сергеевна',
    email: 'manager2@mgcrm.test',
    phone: '+7 (701) 555-21-11',
    job_title: 'Старший менеджер',
    department_name: 'Отдел продаж',
    role: 'manager',
    is_active: true
  }, {
    id: 12,
    full_name: 'Анна Счётова',
    email: 'accountant@mgcrm.test',
    phone: '+7 (701) 340-11-22',
    job_title: 'Главный бухгалтер',
    department_name: 'Бухгалтерия',
    role: 'accountant',
    is_active: true
  }, {
    id: 13,
    full_name: 'Сергей Казначеев',
    email: 'cfo@mgcrm.test',
    phone: '+7 (701) 555-21-13',
    job_title: 'Финансовый директор',
    department_name: 'Финансы',
    role: 'cfo',
    is_active: true
  }, {
    id: 14,
    full_name: 'Роман Уваров',
    email: 'roman.old@mgcrm.test',
    phone: '+7 (701) 555-21-14',
    job_title: 'Менеджер по продажам',
    department_name: 'Отдел продаж',
    role: 'manager',
    is_active: false
  }];
  const U_SEV = {
    success: {
      bg: 'var(--mg-status-success-bg)',
      fg: 'var(--mg-status-success-text)'
    },
    danger: {
      bg: 'var(--mg-status-danger-bg)',
      fg: 'var(--mg-status-danger-text)'
    },
    warn: {
      bg: 'var(--mg-status-warning-bg)',
      fg: 'var(--mg-status-warning-text)'
    },
    info: {
      bg: 'var(--mg-status-info-bg)',
      fg: 'var(--mg-status-info-text)'
    },
    secondary: {
      bg: 'var(--c-muted2)',
      fg: 'var(--c-text2)'
    }
  };
  const uInp = {
    width: '100%',
    height: 40,
    padding: '0 13px',
    borderRadius: 'var(--mg-radius-md)',
    border: '1px solid var(--mg-input-border)',
    background: 'var(--c-card)',
    color: 'var(--c-text)',
    fontFamily: 'var(--mg-font-sans)',
    fontSize: 14,
    boxSizing: 'border-box'
  };
  function uBtn(kind) {
    const base = {
      display: 'inline-flex',
      alignItems: 'center',
      gap: 7,
      height: 38,
      padding: '0 15px',
      borderRadius: 'var(--mg-radius-md)',
      fontFamily: 'var(--mg-font-sans)',
      fontSize: 13,
      fontWeight: 600,
      cursor: 'pointer',
      border: 'none',
      whiteSpace: 'nowrap'
    };
    if (kind === 'primary') return {
      ...base,
      background: 'var(--mg-primary-900)',
      color: '#fff'
    };
    if (kind === 'danger') return {
      ...base,
      background: 'var(--mg-status-danger-solid)',
      color: '#fff'
    };
    if (kind === 'warn') return {
      ...base,
      background: 'var(--mg-status-warning-solid)',
      color: '#3A2A12'
    };
    if (kind === 'text') return {
      ...base,
      background: 'transparent',
      color: 'var(--c-text2)'
    };
    return {
      ...base,
      background: 'transparent',
      color: 'var(--c-text2)',
      border: '1px solid var(--c-border2)'
    };
  }
  function uInitials(name) {
    const w = String(name).trim().split(/\s+/);
    return (w.length >= 2 ? w[0][0] + w[1][0] : w[0].slice(0, 2)).toUpperCase();
  }
  function UTag({
    value,
    severity
  }) {
    const c = U_SEV[severity] || U_SEV.secondary;
    return /*#__PURE__*/React.createElement("span", {
      style: {
        display: 'inline-flex',
        alignItems: 'center',
        fontSize: 11.5,
        fontWeight: 600,
        padding: '3px 9px',
        borderRadius: 6,
        background: c.bg,
        color: c.fg,
        whiteSpace: 'nowrap'
      }
    }, value);
  }
  function UStatus({
    active
  }) {
    const c = active ? U_SEV.success : U_SEV.secondary;
    return /*#__PURE__*/React.createElement("span", {
      style: {
        display: 'inline-flex',
        alignItems: 'center',
        gap: 6,
        fontSize: 12,
        fontWeight: 500,
        color: active ? 'var(--mg-status-success-text)' : 'var(--c-muted)'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        width: 7,
        height: 7,
        borderRadius: '50%',
        background: active ? 'var(--mg-status-success-solid, var(--mg-status-success-text))' : 'var(--c-border2)'
      }
    }), active ? U_T.active : U_T.inactive);
  }
  function UDropdown({
    value,
    onChange,
    options,
    placeholder,
    width,
    filter
  }) {
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const ref = useRef(null);
    useEffect(() => {
      const h = e => {
        if (ref.current && !ref.current.contains(e.target)) setOpen(false);
      };
      document.addEventListener('mousedown', h);
      return () => document.removeEventListener('mousedown', h);
    }, []);
    const sel = options.find(o => o.value === value);
    const list = filter && q ? options.filter(o => o.label.toLowerCase().includes(q.toLowerCase())) : options;
    return /*#__PURE__*/React.createElement("div", {
      ref: ref,
      style: {
        position: 'relative',
        width: width || '100%'
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: () => setOpen(!open),
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        height: 40,
        padding: '0 12px',
        border: '1px solid ' + (open ? 'var(--mg-input-focus-border)' : 'var(--mg-input-border)'),
        borderRadius: 'var(--mg-radius-md)',
        background: 'var(--c-card)',
        cursor: 'pointer',
        boxShadow: open ? 'var(--mg-focus-ring)' : 'none'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 14,
        color: sel ? 'var(--c-text)' : 'var(--c-muted)',
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, sel ? sel.label : placeholder), sel && /*#__PURE__*/React.createElement("i", {
      className: "pi pi-times",
      onClick: e => {
        e.stopPropagation();
        onChange(null);
        setOpen(false);
      },
      style: {
        fontSize: 12,
        color: 'var(--c-muted)',
        cursor: 'pointer'
      }
    }), /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + (open ? 'pi-chevron-up' : 'pi-chevron-down'),
      style: {
        fontSize: 12,
        color: 'var(--c-muted)'
      }
    })), open && /*#__PURE__*/React.createElement("div", {
      style: {
        position: 'absolute',
        top: 44,
        insetInlineStart: 0,
        zIndex: 40,
        width: '100%',
        minWidth: 180,
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-md)',
        boxShadow: 'var(--mg-shadow-lg)',
        padding: 5,
        maxHeight: 280,
        overflowY: 'auto'
      }
    }, filter && /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 7,
        height: 34,
        padding: '0 10px',
        margin: '2px 2px 6px',
        border: '1px solid var(--c-border2)',
        borderRadius: 'var(--mg-radius-sm)',
        background: 'var(--c-page)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-search",
      style: {
        fontSize: 12,
        color: 'var(--c-muted)'
      }
    }), /*#__PURE__*/React.createElement("input", {
      autoFocus: true,
      value: q,
      onChange: e => setQ(e.target.value),
      placeholder: "\u041F\u043E\u0438\u0441\u043A\u2026",
      style: {
        flex: 1,
        border: 'none',
        outline: 'none',
        background: 'transparent',
        fontFamily: 'var(--mg-font-sans)',
        fontSize: 13,
        color: 'var(--c-text)'
      }
    })), list.map(o => {
      const on = o.value === value;
      return /*#__PURE__*/React.createElement("div", {
        key: String(o.value),
        onClick: () => {
          onChange(o.value);
          setOpen(false);
          setQ('');
        },
        style: {
          display: 'flex',
          alignItems: 'center',
          gap: 8,
          padding: '8px 10px',
          borderRadius: 7,
          cursor: 'pointer',
          fontSize: 13.5,
          fontWeight: on ? 600 : 500,
          color: on ? 'var(--mg-primary-900)' : 'var(--c-text)',
          background: on ? 'var(--mg-primary-100)' : 'transparent'
        }
      }, o.label, on && /*#__PURE__*/React.createElement("i", {
        className: "pi pi-check",
        style: {
          marginInlineStart: 'auto',
          fontSize: 12
        }
      }));
    }), list.length === 0 && /*#__PURE__*/React.createElement("div", {
      style: {
        padding: '8px 10px',
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }, "\u041D\u0438\u0447\u0435\u0433\u043E \u043D\u0435 \u043D\u0430\u0439\u0434\u0435\u043D\u043E")));
  }
  function UModal({
    title,
    close,
    footer,
    width,
    children
  }) {
    return /*#__PURE__*/React.createElement("div", {
      onClick: close,
      style: {
        position: 'fixed',
        inset: 0,
        zIndex: 1300,
        background: 'rgba(9,16,32,0.55)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: 20
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: e => e.stopPropagation(),
      style: {
        width: '100%',
        maxWidth: width || 544,
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-lg)',
        overflow: 'hidden',
        maxHeight: 'calc(100vh - 40px)',
        display: 'flex',
        flexDirection: 'column'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '16px 20px',
        borderBottom: '1px solid var(--c-border)',
        flexShrink: 0
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 16,
        fontWeight: 600,
        color: 'var(--c-text)'
      }
    }, title), /*#__PURE__*/React.createElement("i", {
      className: "pi pi-times",
      onClick: close,
      style: {
        fontSize: 14,
        color: 'var(--c-muted)',
        cursor: 'pointer'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        padding: 20,
        overflowY: 'auto'
      }
    }, children), footer && /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'flex-end',
        gap: 10,
        padding: '14px 20px',
        borderTop: '1px solid var(--c-border)',
        flexShrink: 0
      }
    }, footer)));
  }
  function ULabel({
    children
  }) {
    return /*#__PURE__*/React.createElement("label", {
      style: {
        display: 'block',
        fontSize: 13,
        fontWeight: 500,
        color: 'var(--c-text)',
        marginBottom: 6
      }
    }, children);
  }
  function UUserDialog({
    editing,
    close,
    onSave
  }) {
    const isEdit = !!editing;
    const [f, setF] = useState(editing ? {
      full_name: editing.full_name,
      email: editing.email,
      phone: editing.phone || '',
      job_title: editing.job_title || '',
      department_id: (U_DEPTS.find(d => d.name === editing.department_name) || {}).id ?? null,
      manager_id: null,
      role: editing.role,
      password: ''
    } : {
      full_name: '',
      email: '',
      phone: '',
      job_title: '',
      department_id: null,
      manager_id: null,
      role: null,
      password: ''
    });
    const [err, setErr] = useState({});
    const [showPw, setShowPw] = useState(false);
    const set = (k, v) => setF(s => ({
      ...s,
      [k]: v
    }));
    const managers = U_USERS.filter(u => u.id !== (editing || {}).id).map(u => ({
      value: u.id,
      label: u.full_name
    }));
    function submit() {
      const e = {};
      if (!f.full_name.trim()) e.full_name = 'Обязательное поле';
      if (!f.email.trim()) e.email = 'Обязательное поле';else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(f.email.trim())) e.email = 'Некорректный email';
      if (f.password && f.password.length < 8) e.password = 'Минимум 8 символов';
      setErr(e);
      if (Object.keys(e).length) return;
      onSave(f, isEdit);
    }
    const half = {
      flex: 1,
      minWidth: 0
    };
    return /*#__PURE__*/React.createElement(UModal, {
      title: isEdit ? U_T.editUser : U_T.addUser,
      close: close,
      width: 544,
      footer: /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("button", {
        style: uBtn('text'),
        onClick: close
      }, "\u041E\u0442\u043C\u0435\u043D\u0430"), /*#__PURE__*/React.createElement("button", {
        style: uBtn('primary'),
        onClick: submit
      }, isEdit ? 'Сохранить' : 'Создать'))
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        gap: 14
      }
    }, /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement(ULabel, null, U_T.fields.full_name, " *"), /*#__PURE__*/React.createElement("input", {
      className: "inp",
      autoFocus: true,
      value: f.full_name,
      onChange: e => set('full_name', e.target.value),
      placeholder: U_T.fields.full_name_ph,
      style: {
        ...uInp,
        borderColor: err.full_name ? 'var(--mg-status-danger-border)' : 'var(--mg-input-border)'
      }
    }), err.full_name && /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 12,
        color: 'var(--mg-status-danger-text)',
        marginTop: 5
      }
    }, err.full_name)), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement(ULabel, null, U_T.fields.email, " *"), /*#__PURE__*/React.createElement("input", {
      className: "inp",
      type: "email",
      value: f.email,
      onChange: e => set('email', e.target.value),
      placeholder: U_T.fields.email_ph,
      style: {
        ...uInp,
        borderColor: err.email ? 'var(--mg-status-danger-border)' : 'var(--mg-input-border)'
      }
    }), err.email && /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 12,
        color: 'var(--mg-status-danger-text)',
        marginTop: 5
      }
    }, err.email)), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 14
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: half
    }, /*#__PURE__*/React.createElement(ULabel, null, U_T.fields.phone), /*#__PURE__*/React.createElement("input", {
      className: "inp",
      value: f.phone,
      onChange: e => set('phone', e.target.value),
      placeholder: U_T.fields.phone_ph,
      style: uInp
    })), /*#__PURE__*/React.createElement("div", {
      style: half
    }, /*#__PURE__*/React.createElement(ULabel, null, U_T.fields.job_title), /*#__PURE__*/React.createElement("input", {
      className: "inp",
      value: f.job_title,
      onChange: e => set('job_title', e.target.value),
      placeholder: U_T.fields.job_title_ph,
      style: uInp
    }))), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 14
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: half
    }, /*#__PURE__*/React.createElement(ULabel, null, U_T.fields.department), /*#__PURE__*/React.createElement(UDropdown, {
      value: f.department_id,
      onChange: v => set('department_id', v),
      options: U_DEPTS.map(d => ({
        value: d.id,
        label: d.name
      })),
      placeholder: U_T.fields.department_ph
    })), /*#__PURE__*/React.createElement("div", {
      style: half
    }, /*#__PURE__*/React.createElement(ULabel, null, U_T.fields.role), /*#__PURE__*/React.createElement(UDropdown, {
      value: f.role,
      onChange: v => set('role', v),
      options: U_ROLE_OPTS,
      placeholder: U_T.fields.role_ph
    }))), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement(ULabel, null, U_T.fields.manager), /*#__PURE__*/React.createElement(UDropdown, {
      value: f.manager_id,
      onChange: v => set('manager_id', v),
      options: managers,
      placeholder: U_T.fields.manager_ph,
      filter: true
    })), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement(ULabel, null, U_T.fields.password), /*#__PURE__*/React.createElement("div", {
      style: {
        position: 'relative'
      }
    }, /*#__PURE__*/React.createElement("input", {
      className: "inp",
      type: showPw ? 'text' : 'password',
      value: f.password,
      onChange: e => set('password', e.target.value),
      placeholder: isEdit ? U_T.fields.password_edit_ph : U_T.fields.password_ph,
      style: {
        ...uInp,
        paddingInlineEnd: 42,
        borderColor: err.password ? 'var(--mg-status-danger-border)' : 'var(--mg-input-border)'
      }
    }), /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + (showPw ? 'pi-eye-slash' : 'pi-eye'),
      onClick: () => setShowPw(!showPw),
      style: {
        position: 'absolute',
        insetInlineEnd: 13,
        top: 13,
        fontSize: 15,
        color: 'var(--c-muted)',
        cursor: 'pointer'
      }
    })), err.password && /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 12,
        color: 'var(--mg-status-danger-text)',
        marginTop: 5
      }
    }, err.password)), !isEdit && /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 9,
        alignItems: 'flex-start',
        padding: '11px 13px',
        background: 'var(--c-hover)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-md)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-info-circle",
      style: {
        fontSize: 14,
        color: 'var(--c-muted)',
        marginTop: 1
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 12.5,
        color: 'var(--c-text2)',
        lineHeight: 1.5
      }
    }, U_T.passwordHint))));
  }
  function UConfirm({
    header,
    icon,
    iconColor,
    message,
    acceptLabel,
    acceptKind,
    onAccept,
    close
  }) {
    return /*#__PURE__*/React.createElement(UModal, {
      title: header,
      close: close,
      width: 430,
      footer: /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("button", {
        style: uBtn('ghost'),
        onClick: close
      }, "\u041E\u0442\u043C\u0435\u043D\u0430"), /*#__PURE__*/React.createElement("button", {
        style: uBtn(acceptKind),
        onClick: onAccept
      }, acceptLabel))
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 14,
        alignItems: 'flex-start'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        width: 40,
        height: 40,
        borderRadius: '50%',
        background: U_SEV[acceptKind === 'danger' ? 'danger' : 'warn'].bg,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        flexShrink: 0
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + icon,
      style: {
        fontSize: 17,
        color: iconColor
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 14,
        color: 'var(--c-text2)',
        lineHeight: 1.55,
        paddingTop: 3
      }
    }, message)));
  }
  function UResetResult({
    password,
    close
  }) {
    const [copied, setCopied] = useState(false);
    return /*#__PURE__*/React.createElement(UModal, {
      title: U_T.reset.resultTitle,
      close: close,
      width: 448,
      footer: /*#__PURE__*/React.createElement("button", {
        style: uBtn('ghost'),
        onClick: close
      }, "\u0417\u0430\u043A\u0440\u044B\u0442\u044C")
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'flex-start',
        gap: 10,
        padding: 13,
        marginBottom: 18,
        background: 'var(--mg-status-warning-bg)',
        border: '1px solid var(--mg-status-warning-border)',
        borderRadius: 'var(--mg-radius-md)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-exclamation-triangle",
      style: {
        fontSize: 15,
        color: 'var(--mg-status-warning-solid)',
        marginTop: 1,
        flexShrink: 0
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 13,
        color: 'var(--mg-status-warning-text)',
        lineHeight: 1.5
      }
    }, U_T.reset.oneTimeWarning)), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        gap: 8
      }
    }, /*#__PURE__*/React.createElement("label", {
      style: {
        fontSize: 13,
        fontWeight: 500,
        color: 'var(--c-text)'
      }
    }, U_T.reset.newPassword), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        padding: '8px 12px',
        background: 'var(--c-hover)',
        border: '1px solid var(--c-border2)',
        borderRadius: 'var(--mg-radius-md)'
      }
    }, /*#__PURE__*/React.createElement("code", {
      style: {
        flex: 1,
        fontFamily: 'ui-monospace,monospace',
        fontSize: 15,
        fontWeight: 500,
        color: 'var(--c-text)',
        letterSpacing: '0.04em',
        wordBreak: 'break-all'
      }
    }, password), /*#__PURE__*/React.createElement("button", {
      onClick: () => {
        navigator.clipboard && navigator.clipboard.writeText(password);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
      },
      title: U_T.reset.copy,
      style: {
        width: 32,
        height: 32,
        borderRadius: '50%',
        border: 'none',
        background: 'transparent',
        cursor: 'pointer',
        display: 'inline-flex',
        alignItems: 'center',
        justifyContent: 'center'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + (copied ? 'pi-check' : 'pi-copy'),
      style: {
        fontSize: 14,
        color: copied ? 'var(--mg-status-success-text)' : 'var(--c-muted)'
      }
    })))));
  }
  function UToasts({
    items
  }) {
    return /*#__PURE__*/React.createElement("div", {
      style: {
        position: 'fixed',
        insetBlockEnd: 20,
        insetInlineEnd: 20,
        zIndex: 2000,
        display: 'flex',
        flexDirection: 'column',
        gap: 10
      }
    }, items.map(t => {
      const c = U_SEV[t.severity] || U_SEV.success;
      return /*#__PURE__*/React.createElement("div", {
        key: t.id,
        style: {
          display: 'flex',
          alignItems: 'center',
          gap: 10,
          minWidth: 260,
          maxWidth: 360,
          padding: '12px 14px',
          background: 'var(--c-card)',
          border: '1px solid var(--c-border)',
          borderInlineStart: '3px solid ' + c.fg,
          borderRadius: 'var(--mg-radius-md)',
          boxShadow: 'var(--mg-shadow-lg)',
          animation: 'toastIn .2s ease'
        }
      }, /*#__PURE__*/React.createElement("i", {
        className: "pi pi-check-circle",
        style: {
          fontSize: 16,
          color: c.fg
        }
      }), /*#__PURE__*/React.createElement("span", {
        style: {
          fontSize: 13.5,
          fontWeight: 500,
          color: 'var(--c-text)'
        }
      }, t.summary));
    }));
  }
  function UIconBtn({
    icon,
    color,
    title,
    onClick
  }) {
    return /*#__PURE__*/React.createElement("button", {
      className: "uicon",
      onClick: onClick,
      title: title,
      "aria-label": title,
      style: {
        width: 32,
        height: 32,
        borderRadius: 8,
        border: 'none',
        background: 'transparent',
        cursor: 'pointer',
        display: 'inline-flex',
        alignItems: 'center',
        justifyContent: 'center'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + icon,
      style: {
        fontSize: 14,
        color
      }
    }));
  }
  function UsersTab() {
    const [rows, setRows] = useState(U_USERS);
    const [search, setSearch] = useState('');
    const [roleF, setRoleF] = useState(null);
    const [deptF, setDeptF] = useState(null);
    const [activeF, setActiveF] = useState(null);
    const [dialog, setDialog] = useState(null);
    const [toasts, setToasts] = useState([]);
    const [editing, setEditing] = useState(false);
    const pushToast = summary => {
      const id = Date.now() + Math.random();
      setToasts(t => [...t, {
        id,
        summary,
        severity: 'success'
      }]);
      setTimeout(() => setToasts(t => t.filter(x => x.id !== id)), 2600);
    };
    const th = {
      padding: '11px 16px',
      fontSize: 11.5,
      fontWeight: 600,
      letterSpacing: '.03em',
      textTransform: 'uppercase',
      color: 'var(--c-muted)',
      textAlign: 'start',
      borderBottom: '1px solid var(--c-border)',
      whiteSpace: 'nowrap'
    };
    const td = {
      padding: '10px 16px',
      fontSize: 13.5,
      color: 'var(--c-text)',
      borderBottom: '1px solid var(--c-border)',
      verticalAlign: 'middle'
    };
    const filtered = rows.filter(u => {
      if (search.trim()) {
        const q = search.trim().toLowerCase();
        if (!(u.full_name.toLowerCase().includes(q) || u.email.toLowerCase().includes(q))) return false;
      }
      if (roleF && u.role !== roleF) return false;
      if (deptF !== null && (U_DEPTS.find(d => d.id === deptF) || {}).name !== u.department_name) return false;
      return true;
    });
    const active = filtered.filter(u => u.is_active);
    const inactive = filtered.filter(u => !u.is_active);
    const activeCount = rows.filter(u => u.is_active).length;
    function saveUser(form, isEdit) {
      if (isEdit) {
        setRows(rs => rs.map(u => u.id === dialog.editing.id ? {
          ...u,
          full_name: form.full_name.trim(),
          email: form.email.trim(),
          phone: form.phone.trim() || null,
          job_title: form.job_title.trim() || null,
          department_name: (U_DEPTS.find(d => d.id === form.department_id) || {}).name ?? null,
          role: form.role
        } : u));
        pushToast('Изменения сохранены');
      } else {
        const id = Math.max(...rows.map(r => r.id)) + 1;
        setRows(rs => [...rs, {
          id,
          full_name: form.full_name.trim(),
          email: form.email.trim(),
          phone: form.phone.trim() || null,
          job_title: form.job_title.trim() || null,
          department_name: (U_DEPTS.find(d => d.id === form.department_id) || {}).name ?? null,
          role: form.role || 'manager',
          is_active: true
        }]);
        pushToast('Пользователь создан');
      }
      setDialog(null);
    }
    function doDeactivate(u) {
      setRows(rs => rs.map(x => x.id === u.id ? {
        ...x,
        is_active: false
      } : x));
      pushToast('Пользователь деактивирован');
      setDialog(null);
    }
    function doReactivate(u) {
      setRows(rs => rs.map(x => x.id === u.id ? {
        ...x,
        is_active: true
      } : x));
      pushToast('Пользователь активирован');
    }
    function doReset() {
      setDialog({
        type: 'resetResult',
        pw: uGenPw()
      });
    }
    const columns = /*#__PURE__*/React.createElement("thead", null, /*#__PURE__*/React.createElement("tr", null, /*#__PURE__*/React.createElement("th", {
      style: th
    }, "\u0421\u043E\u0442\u0440\u0443\u0434\u043D\u0438\u043A"), /*#__PURE__*/React.createElement("th", {
      style: {
        ...th,
        width: 170
      }
    }, "\u0422\u0435\u043B\u0435\u0444\u043E\u043D"), /*#__PURE__*/React.createElement("th", {
      style: {
        ...th,
        width: 180
      }
    }, "\u0414\u043E\u043B\u0436\u043D\u043E\u0441\u0442\u044C"), /*#__PURE__*/React.createElement("th", {
      style: {
        ...th,
        width: 150
      }
    }, "\u041E\u0442\u0434\u0435\u043B"), /*#__PURE__*/React.createElement("th", {
      style: {
        ...th,
        width: 150
      }
    }, "\u0420\u043E\u043B\u044C"), editing && /*#__PURE__*/React.createElement("th", {
      style: {
        ...th,
        width: 120,
        textAlign: 'end'
      }
    }, "\u0414\u0435\u0439\u0441\u0442\u0432\u0438\u044F")));
    const renderRow = (u, archive) => /*#__PURE__*/React.createElement("tr", {
      key: u.id,
      className: "urow",
      style: {
        transition: 'background .12s',
        opacity: archive ? 0.72 : 1
      }
    }, /*#__PURE__*/React.createElement("td", {
      style: td
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 11
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        width: 36,
        height: 36,
        borderRadius: '50%',
        flexShrink: 0,
        background: archive ? 'var(--c-muted2)' : U_ROLE_GRAD[u.role] || U_ROLE_GRAD.accountant,
        color: archive ? 'var(--c-muted)' : '#fff',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        fontSize: 12.5,
        fontWeight: 600,
        letterSpacing: '.02em'
      }
    }, uInitials(u.full_name)), /*#__PURE__*/React.createElement("div", {
      style: {
        minWidth: 0
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        fontWeight: 600,
        fontSize: 13.5,
        color: 'var(--c-text)',
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, u.full_name), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 12,
        color: 'var(--c-muted)',
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, u.email)))), /*#__PURE__*/React.createElement("td", {
      style: {
        ...td,
        color: u.phone ? 'var(--c-text2)' : 'var(--c-muted)',
        whiteSpace: 'nowrap',
        fontVariantNumeric: 'tabular-nums'
      }
    }, u.phone ?? '—'), /*#__PURE__*/React.createElement("td", {
      style: {
        ...td,
        color: u.job_title ? 'var(--c-text2)' : 'var(--c-muted)'
      }
    }, u.job_title ?? '—'), /*#__PURE__*/React.createElement("td", {
      style: {
        ...td,
        color: u.department_name ? 'var(--c-text2)' : 'var(--c-muted)'
      }
    }, u.department_name ?? '—'), /*#__PURE__*/React.createElement("td", {
      style: td
    }, u.role ? /*#__PURE__*/React.createElement(UTag, {
      value: U_ROLE_LABEL[u.role],
      severity: U_ROLE_SEV[u.role]
    }) : /*#__PURE__*/React.createElement("span", {
      style: {
        color: 'var(--c-muted)'
      }
    }, "\u2014")), editing && /*#__PURE__*/React.createElement("td", {
      style: td
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 2,
        justifyContent: 'flex-end'
      }
    }, /*#__PURE__*/React.createElement(UIconBtn, {
      icon: "pi-pencil",
      color: "var(--c-text2)",
      title: "\u0420\u0435\u0434\u0430\u043A\u0442\u0438\u0440\u043E\u0432\u0430\u0442\u044C",
      onClick: () => setDialog({
        type: 'form',
        editing: u
      })
    }), u.id !== U_ME && /*#__PURE__*/React.createElement(UIconBtn, {
      icon: "pi-key",
      color: "var(--c-text2)",
      title: U_T.reset.action,
      onClick: () => setDialog({
        type: 'confirm',
        kind: 'reset',
        user: u
      })
    }), archive ? /*#__PURE__*/React.createElement(UIconBtn, {
      icon: "pi-replay",
      color: "var(--mg-status-success-text)",
      title: U_T.activate,
      onClick: () => doReactivate(u)
    }) : /*#__PURE__*/React.createElement(UIconBtn, {
      icon: "pi-ban",
      color: "var(--mg-status-danger-text)",
      title: U_T.deactivate,
      onClick: () => setDialog({
        type: 'confirm',
        kind: 'deactivate',
        user: u
      })
    }))));
    return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'flex-start',
        gap: 16,
        marginBottom: 20
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        flex: 1,
        minWidth: 0
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10
      }
    }, /*#__PURE__*/React.createElement("h2", {
      style: {
        margin: 0,
        fontSize: 20,
        fontWeight: 600
      }
    }, U_T.title), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 12,
        fontWeight: 600,
        padding: '2px 9px',
        borderRadius: 999,
        background: 'var(--mg-primary-100)',
        color: 'var(--mg-primary-900)'
      }
    }, rows.length)), /*#__PURE__*/React.createElement("p", {
      style: {
        margin: '5px 0 0',
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }, U_T.subtitle, " \xB7 ", activeCount, " \u0430\u043A\u0442\u0438\u0432\u043D\u044B\u0445")), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 10,
        flexShrink: 0
      }
    }, /*#__PURE__*/React.createElement("button", {
      style: editing ? {
        ...uBtn('ghost'),
        color: 'var(--mg-primary-900)',
        borderColor: 'var(--mg-primary-900)'
      } : uBtn('ghost'),
      onClick: () => setEditing(e => !e)
    }, /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + (editing ? 'pi-check' : 'pi-pencil'),
      style: {
        fontSize: 12
      }
    }), editing ? 'Завершить редактирование' : 'Редактировать'), /*#__PURE__*/React.createElement("button", {
      style: uBtn('primary'),
      onClick: () => setDialog({
        type: 'form',
        editing: null
      })
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-plus",
      style: {
        fontSize: 12
      }
    }), U_T.addUser))), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        flexWrap: 'wrap',
        marginBottom: 14
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        height: 40,
        padding: '0 12px',
        flex: 1,
        minWidth: 200,
        maxWidth: 320,
        border: '1px solid var(--mg-input-border)',
        borderRadius: 'var(--mg-radius-md)',
        background: 'var(--c-card)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-search",
      style: {
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }), /*#__PURE__*/React.createElement("input", {
      value: search,
      onChange: e => setSearch(e.target.value),
      placeholder: U_T.filters.search,
      style: {
        flex: 1,
        border: 'none',
        outline: 'none',
        background: 'transparent',
        fontFamily: 'var(--mg-font-sans)',
        fontSize: 14,
        color: 'var(--c-text)'
      }
    })), /*#__PURE__*/React.createElement(UDropdown, {
      value: roleF,
      onChange: setRoleF,
      options: U_ROLE_OPTS,
      placeholder: U_T.filters.role,
      width: 150
    }), /*#__PURE__*/React.createElement(UDropdown, {
      value: deptF,
      onChange: setDeptF,
      options: U_DEPTS.map(d => ({
        value: d.id,
        label: d.name
      })),
      placeholder: U_T.filters.department,
      width: 168
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-sm)',
        overflow: 'hidden'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        overflowX: 'auto'
      }
    }, /*#__PURE__*/React.createElement("table", {
      style: {
        width: '100%',
        borderCollapse: 'collapse',
        minWidth: 820
      }
    }, columns, /*#__PURE__*/React.createElement("tbody", null, active.map(u => renderRow(u, false))))), active.length === 0 && /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        gap: 12,
        padding: '52px 20px',
        color: 'var(--c-muted)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-users",
      style: {
        fontSize: 30,
        opacity: 0.3
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 14,
        color: 'var(--c-text2)'
      }
    }, "\u0410\u043A\u0442\u0438\u0432\u043D\u044B\u0435 \u043F\u043E\u043B\u044C\u0437\u043E\u0432\u0430\u0442\u0435\u043B\u0438 \u043D\u0435 \u043D\u0430\u0439\u0434\u0435\u043D\u044B"))), inactive.length > 0 && /*#__PURE__*/React.createElement("div", {
      style: {
        marginTop: 28
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 9,
        marginBottom: 12
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-user-minus",
      style: {
        fontSize: 14,
        color: 'var(--c-muted)'
      }
    }), /*#__PURE__*/React.createElement("h3", {
      style: {
        margin: 0,
        fontSize: 15,
        fontWeight: 600,
        color: 'var(--c-text2)'
      }
    }, "\u0423\u0432\u043E\u043B\u0435\u043D\u043D\u044B\u0435"), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 11.5,
        fontWeight: 600,
        padding: '2px 8px',
        borderRadius: 999,
        background: 'var(--c-muted2)',
        color: 'var(--c-text2)'
      }
    }, inactive.length)), /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-sm)',
        overflow: 'hidden'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        overflowX: 'auto'
      }
    }, /*#__PURE__*/React.createElement("table", {
      style: {
        width: '100%',
        borderCollapse: 'collapse',
        minWidth: 820
      }
    }, columns, /*#__PURE__*/React.createElement("tbody", null, inactive.map(u => renderRow(u, true))))))), dialog && dialog.type === 'form' && /*#__PURE__*/React.createElement(UUserDialog, {
      key: dialog.editing ? 'edit-' + dialog.editing.id : 'new',
      editing: dialog.editing,
      close: () => setDialog(null),
      onSave: saveUser
    }), dialog && dialog.type === 'confirm' && dialog.kind === 'deactivate' && /*#__PURE__*/React.createElement(UConfirm, {
      header: U_T.deactivateTitle,
      icon: "pi-exclamation-triangle",
      iconColor: "var(--mg-status-warning-solid)",
      message: U_T.deactivateConfirm(dialog.user.full_name),
      acceptLabel: U_T.deactivate,
      acceptKind: "danger",
      onAccept: () => doDeactivate(dialog.user),
      close: () => setDialog(null)
    }), dialog && dialog.type === 'confirm' && dialog.kind === 'reset' && /*#__PURE__*/React.createElement(UConfirm, {
      header: U_T.reset.title,
      icon: "pi-key",
      iconColor: "var(--mg-status-warning-solid)",
      message: U_T.reset.confirm(dialog.user.full_name),
      acceptLabel: U_T.reset.action,
      acceptKind: "warn",
      onAccept: doReset,
      close: () => setDialog(null)
    }), dialog && dialog.type === 'resetResult' && /*#__PURE__*/React.createElement(UResetResult, {
      password: dialog.pw,
      close: () => setDialog(null)
    }), /*#__PURE__*/React.createElement(UToasts, {
      items: toasts
    }));
  }
  function uGenPw() {
    const a = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    let s = '';
    for (let i = 0; i < 12; i++) s += a[Math.floor(Math.random() * a.length)];
    return s;
  }
  window.UsersTab = UsersTab;
})();
})(); } catch (e) { __ds_ns.__errors.push({ path: "redesign/_dh_update/redesign/users-section.jsx", error: String((e && e.message) || e) }); }

// redesign/access-section.jsx
try { (() => {
/* Доступ и оргструктура — Настройки → Система.
   Mirrors front/src/pages/AccessControlPage (index + DepartmentsTab, DepartmentTree,
   DepartmentSidePanel, OrgChartView, RolesPermissionsTab, PermissionMatrix,
   VisibilityScopeTab) + entities/accessControl + ru.json. Redesigned look.
   Exposes window.AccessTab. */
(function () {
  const {
    useState,
    useRef,
    useEffect
  } = React;

  /* ── i18n (ru.json → accessControl) ── */
  const A_T = {
    title: 'Доступ и оргструктура',
    subtitle: 'Отделы, роли и видимость записей',
    tabs: {
      departments: 'Отделы',
      roles: 'Роли и права',
      visibility: 'Видимость'
    },
    dep: {
      add: 'Добавить отдел',
      edit: 'Редактировать отдел',
      del: 'Удалить отдел',
      delConfirm: n => 'Удалить отдел «' + n + '»? Дочерние отделы и сотрудники останутся без родителя.',
      name: 'Название',
      parent: 'Родительский отдел',
      root: '— Корень —',
      manager: 'Руководитель',
      members: 'Сотрудники отдела',
      addMember: 'Добавить сотрудника',
      removeMember: 'Убрать из отдела',
      viewTree: 'Дерево',
      viewChart: 'Схема',
      empty: 'Отделы не созданы',
      emptyHint: 'Добавьте первый отдел, чтобы настроить оргструктуру',
      noMembers: 'Нет участников',
      saved: 'Отдел сохранён',
      deleted: 'Отдел удалён'
    },
    roles: {
      title: 'Матрица прав',
      adminNote: 'Роль admin всегда получает все права и не может быть ограничена.',
      permissionLabel: 'Право',
      save: 'Сохранить',
      reset: 'Сбросить',
      saved: 'Права ролей сохранены'
    },
    vis: {
      title: 'Видимость записей',
      warning: 'Настройки видимости влияют на то, какие записи (сделки, контакты, задачи) видит каждая роль. Изменяйте с осторожностью.',
      scopeAll: 'Все',
      scopeDepartment: 'Отдел (+подотделы)',
      scopeOwn: 'Свои',
      roleColumn: 'Роль',
      scopeColumn: 'Видимость записей',
      departmentHint: 'Значение «Отдел» работает только если у пользователя указан отдел.',
      save: 'Сохранить',
      reset: 'Сбросить',
      saved: 'Настройки видимости сохранены'
    }
  };
  const A_ROLES = ['admin', 'director', 'lawyer', 'manager', 'accountant', 'cfo'];
  const A_ROLE_LABEL = {
    admin: 'Администратор',
    director: 'Директор',
    lawyer: 'Юрист',
    manager: 'Менеджер',
    accountant: 'Бухгалтер',
    cfo: 'Финансовый директор'
  };
  const A_ROLE_SHORT = {
    admin: 'Админ',
    director: 'Директор',
    lawyer: 'Юрист',
    manager: 'Менеджер',
    accountant: 'Бухгалтер',
    cfo: 'Фин. дир.'
  };
  const A_ROLE_SEV = {
    admin: 'danger',
    director: 'warn',
    lawyer: 'info',
    manager: 'success',
    accountant: 'secondary',
    cfo: 'secondary'
  };
  const A_GROUPS = [{
    key: 'crm',
    label: 'CRM',
    perms: ['crm.view', 'crm.manage']
  }, {
    key: 'sales',
    label: 'Продажи',
    perms: ['sales.view', 'sales.manage']
  }, {
    key: 'contracts',
    label: 'Договоры',
    perms: ['contracts.view', 'contracts.manage']
  }, {
    key: 'users',
    label: 'Пользователи',
    perms: ['users.view', 'users.manage']
  }, {
    key: 'automation',
    label: 'Автоматизации',
    perms: ['automation.manage']
  }, {
    key: 'analytics',
    label: 'Аналитика',
    perms: ['analytics.view', 'settings.manage']
  }, {
    key: 'finance',
    label: 'Финансы',
    perms: ['finance.view', 'finance.entry', 'finance.posting', 'finance.journals.manual', 'finance.payments.approve', 'finance.period.close', 'finance.settings.manage', 'finance.reports.management']
  }, {
    key: 'system',
    label: 'Системные права',
    perms: ['admin-write', 'dedup-scan-all', 'view-manager-cabinet', 'system-reset']
  }];
  const A_PERM_LABEL = {
    'crm.view': 'CRM — просмотр',
    'crm.manage': 'CRM — управление',
    'sales.view': 'Продажи — просмотр',
    'sales.manage': 'Продажи — управление',
    'contracts.view': 'Договоры — просмотр',
    'contracts.manage': 'Договоры — управление',
    'users.view': 'Пользователи — просмотр',
    'users.manage': 'Пользователи — управление',
    'automation.manage': 'Автоматизации — управление',
    'analytics.view': 'Аналитика — просмотр',
    'settings.manage': 'Настройки системы — управление',
    'finance.view': 'Финансы — просмотр',
    'finance.entry': 'Финансы — ввод операций',
    'finance.posting': 'Финансы — проводки',
    'finance.journals.manual': 'Финансы — ручные журналы',
    'finance.payments.approve': 'Финансы — согласование платежей',
    'finance.period.close': 'Финансы — закрытие периода',
    'finance.settings.manage': 'Финансы — настройки',
    'finance.reports.management': 'Финансы — управленческие отчёты',
    'admin-write': 'Системные изменения (админ)',
    'dedup-scan-all': 'Дедупликация — полное сканирование',
    'view-manager-cabinet': 'Кабинет менеджера — доступ',
    'system-reset': 'Сброс системы'
  };
  /* default role → granted permissions */
  const A_DEFAULT_PERMS = {
    admin: A_GROUPS.flatMap(g => g.perms),
    director: ['crm.view', 'crm.manage', 'sales.view', 'sales.manage', 'contracts.view', 'contracts.manage', 'users.view', 'automation.manage', 'analytics.view', 'settings.manage', 'finance.view', 'view-manager-cabinet'],
    lawyer: ['crm.view', 'contracts.view', 'contracts.manage'],
    manager: ['crm.view', 'sales.view', 'sales.manage', 'contracts.view'],
    accountant: ['crm.view', 'finance.view', 'finance.entry', 'finance.posting', 'finance.journals.manual'],
    cfo: ['crm.view', 'analytics.view', 'finance.view', 'finance.entry', 'finance.posting', 'finance.payments.approve', 'finance.period.close', 'finance.settings.manage', 'finance.reports.management']
  };
  const A_DEFAULT_VIS = {
    admin: 'all',
    director: 'all',
    lawyer: 'department',
    manager: 'own',
    accountant: 'department',
    cfo: 'all'
  };
  const A_M = (id, full_name, email, role) => ({
    id,
    full_name,
    email,
    role
  });
  const A_TREE0 = [{
    id: 1,
    name: 'Руководство',
    manager: 'Директор Петров П.П.',
    members: [A_M(6, 'Директор Петров П.П.', 'director@mgcrm.test', 'director'), A_M(2, 'Bogdan Yadykin', 'b.yadykin@macroglobaltech.com', 'admin')],
    children: [{
      id: 2,
      name: 'Отдел продаж',
      manager: 'Иванов Алексей Сергеевич',
      members: [A_M(7, 'Иванов Алексей Сергеевич', 'manager1@mgcrm.test', 'manager'), A_M(8, 'Илья Рогов', 'ilyarogov.mera@gmail.com', 'manager'), A_M(9, 'Клим Федорин', 'k.fedorin@macroglobaltech.com', 'manager'), A_M(10, 'Олеся Моисеева', 'o.moiseeva@macroglobaltech.com', 'manager'), A_M(5, 'Георгий Некрасов', 'g.nekrasov@macroglobaltech.com', 'manager'), A_M(11, 'Петрова Мария Сергеевна', 'manager2@mgcrm.test', 'manager')],
      children: [{
        id: 5,
        name: 'Группа B2B',
        manager: 'Петрова Мария Сергеевна',
        members: [A_M(7, 'Иванов Алексей Сергеевич', 'manager1@mgcrm.test', 'manager'), A_M(9, 'Клим Федорин', 'k.fedorin@macroglobaltech.com', 'manager'), A_M(5, 'Георгий Некрасов', 'g.nekrasov@macroglobaltech.com', 'manager')],
        children: []
      }]
    }, {
      id: 3,
      name: 'Финансы',
      manager: 'Сергей Казначеев',
      members: [A_M(13, 'Сергей Казначеев', 'cfo@mgcrm.test', 'cfo')],
      children: [{
        id: 6,
        name: 'Бухгалтерия',
        manager: 'Анна Счётова',
        members: [A_M(12, 'Анна Счётова', 'accountant@mgcrm.test', 'accountant')],
        children: []
      }]
    }, {
      id: 4,
      name: 'Юридический отдел',
      manager: 'Lawyer Test',
      members: [A_M(3, 'Lawyer Test', 'lawyer@mgcrm.test', 'lawyer')],
      children: []
    }]
  }];
  const A_ALL_USERS = [A_M(1, 'Admin', 'svkv42@gmail.com', 'admin'), A_M(2, 'Bogdan Yadykin', 'b.yadykin@macroglobaltech.com', 'admin'), A_M(3, 'Lawyer Test', 'lawyer@mgcrm.test', 'lawyer'), A_M(5, 'Георгий Некрасов', 'g.nekrasov@macroglobaltech.com', 'manager'), A_M(6, 'Директор Петров П.П.', 'director@mgcrm.test', 'director'), A_M(7, 'Иванов Алексей Сергеевич', 'manager1@mgcrm.test', 'manager'), A_M(8, 'Илья Рогов', 'ilyarogov.mera@gmail.com', 'manager'), A_M(9, 'Клим Федорин', 'k.fedorin@macroglobaltech.com', 'manager'), A_M(10, 'Олеся Моисеева', 'o.moiseeva@macroglobaltech.com', 'manager'), A_M(11, 'Петрова Мария Сергеевна', 'manager2@mgcrm.test', 'manager'), A_M(12, 'Анна Счётова', 'accountant@mgcrm.test', 'accountant'), A_M(13, 'Сергей Казначеев', 'cfo@mgcrm.test', 'cfo')];

  /* ── shared primitives ── */
  const A_SEV = {
    success: {
      bg: 'var(--mg-status-success-bg)',
      fg: 'var(--mg-status-success-text)'
    },
    danger: {
      bg: 'var(--mg-status-danger-bg)',
      fg: 'var(--mg-status-danger-text)'
    },
    warn: {
      bg: 'var(--mg-status-warning-bg)',
      fg: 'var(--mg-status-warning-text)'
    },
    info: {
      bg: 'var(--mg-status-info-bg)',
      fg: 'var(--mg-status-info-text)'
    },
    secondary: {
      bg: 'var(--c-muted2)',
      fg: 'var(--c-text2)'
    }
  };
  function A_btn(kind) {
    const base = {
      display: 'inline-flex',
      alignItems: 'center',
      gap: 7,
      height: 38,
      padding: '0 15px',
      borderRadius: 'var(--mg-radius-md)',
      fontFamily: 'var(--mg-font-sans)',
      fontSize: 13,
      fontWeight: 600,
      cursor: 'pointer',
      border: 'none',
      whiteSpace: 'nowrap'
    };
    if (kind === 'primary') return {
      ...base,
      background: 'var(--mg-primary-900)',
      color: '#fff'
    };
    if (kind === 'danger') return {
      ...base,
      background: 'var(--mg-status-danger-solid)',
      color: '#fff'
    };
    if (kind === 'text') return {
      ...base,
      background: 'transparent',
      color: 'var(--c-text2)'
    };
    return {
      ...base,
      background: 'transparent',
      color: 'var(--c-text2)',
      border: '1px solid var(--c-border2)'
    };
  }
  function A_sbtn(kind, active) {
    const b = A_btn(kind === 'p' ? 'primary' : 'ghost');
    return {
      ...b,
      height: 34,
      padding: '0 12px',
      fontSize: 12.5,
      ...(active ? {} : {})
    };
  }
  function A_Tag({
    value,
    severity
  }) {
    const c = A_SEV[severity] || A_SEV.secondary;
    return /*#__PURE__*/React.createElement("span", {
      style: {
        display: 'inline-flex',
        alignItems: 'center',
        fontSize: 11.5,
        fontWeight: 600,
        padding: '3px 9px',
        borderRadius: 6,
        background: c.bg,
        color: c.fg,
        whiteSpace: 'nowrap'
      }
    }, value);
  }
  function A_Msg({
    severity,
    children
  }) {
    const map = {
      warn: {
        bg: 'var(--mg-status-warning-bg)',
        bd: 'var(--mg-status-warning-border)',
        fg: 'var(--mg-status-warning-text)',
        ic: 'pi-exclamation-triangle'
      },
      secondary: {
        bg: 'var(--c-hover)',
        bd: 'var(--c-border)',
        fg: 'var(--c-text2)',
        ic: 'pi-info-circle'
      }
    };
    const c = map[severity] || map.secondary;
    return /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'flex-start',
        gap: 10,
        padding: '11px 14px',
        background: c.bg,
        border: '1px solid ' + c.bd,
        borderRadius: 'var(--mg-radius-md)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + c.ic,
      style: {
        fontSize: 14,
        color: c.fg,
        marginTop: 1,
        flexShrink: 0
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 13,
        color: c.fg,
        lineHeight: 1.5
      }
    }, children));
  }
  function A_Check({
    checked,
    disabled,
    onChange
  }) {
    return /*#__PURE__*/React.createElement("span", {
      onClick: () => !disabled && onChange(!checked),
      style: {
        display: 'inline-flex',
        width: 20,
        height: 20,
        borderRadius: 5,
        border: '1.5px solid ' + (checked ? 'var(--mg-primary-900)' : 'var(--c-border2)'),
        background: checked ? 'var(--mg-primary-900)' : 'var(--c-card)',
        alignItems: 'center',
        justifyContent: 'center',
        cursor: disabled ? 'not-allowed' : 'pointer',
        opacity: disabled ? 0.5 : 1
      }
    }, checked && /*#__PURE__*/React.createElement("i", {
      className: "pi pi-check",
      style: {
        fontSize: 11,
        color: '#fff',
        fontWeight: 700
      }
    }));
  }
  function A_Dropdown({
    value,
    onChange,
    options,
    placeholder,
    width,
    filter
  }) {
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const ref = useRef(null);
    useEffect(() => {
      const h = e => {
        if (ref.current && !ref.current.contains(e.target)) setOpen(false);
      };
      document.addEventListener('mousedown', h);
      return () => document.removeEventListener('mousedown', h);
    }, []);
    const sel = options.find(o => o.value === value);
    const list = filter && q ? options.filter(o => o.label.toLowerCase().includes(q.toLowerCase())) : options;
    return /*#__PURE__*/React.createElement("div", {
      ref: ref,
      style: {
        position: 'relative',
        width: width || '100%'
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: () => setOpen(!open),
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        height: 40,
        padding: '0 12px',
        border: '1px solid ' + (open ? 'var(--mg-input-focus-border)' : 'var(--mg-input-border)'),
        borderRadius: 'var(--mg-radius-md)',
        background: 'var(--c-card)',
        cursor: 'pointer',
        boxShadow: open ? 'var(--mg-focus-ring)' : 'none'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 14,
        color: sel ? 'var(--c-text)' : 'var(--c-muted)',
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, sel ? sel.label : placeholder), /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + (open ? 'pi-chevron-up' : 'pi-chevron-down'),
      style: {
        fontSize: 12,
        color: 'var(--c-muted)'
      }
    })), open && /*#__PURE__*/React.createElement("div", {
      style: {
        position: 'absolute',
        top: 44,
        insetInlineStart: 0,
        zIndex: 40,
        width: '100%',
        minWidth: 180,
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-md)',
        boxShadow: 'var(--mg-shadow-lg)',
        padding: 5,
        maxHeight: 260,
        overflowY: 'auto'
      }
    }, filter && /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 7,
        height: 34,
        padding: '0 10px',
        margin: '2px 2px 6px',
        border: '1px solid var(--c-border2)',
        borderRadius: 'var(--mg-radius-sm)',
        background: 'var(--c-page)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-search",
      style: {
        fontSize: 12,
        color: 'var(--c-muted)'
      }
    }), /*#__PURE__*/React.createElement("input", {
      autoFocus: true,
      value: q,
      onChange: e => setQ(e.target.value),
      placeholder: "\u041F\u043E\u0438\u0441\u043A\u2026",
      style: {
        flex: 1,
        border: 'none',
        outline: 'none',
        background: 'transparent',
        fontFamily: 'var(--mg-font-sans)',
        fontSize: 13,
        color: 'var(--c-text)'
      }
    })), list.map(o => {
      const on = o.value === value;
      return /*#__PURE__*/React.createElement("div", {
        key: String(o.value),
        onClick: () => {
          onChange(o.value);
          setOpen(false);
          setQ('');
        },
        style: {
          display: 'flex',
          alignItems: 'center',
          gap: 8,
          padding: '8px 10px',
          borderRadius: 7,
          cursor: 'pointer',
          fontSize: 13.5,
          fontWeight: on ? 600 : 500,
          color: on ? 'var(--mg-primary-900)' : 'var(--c-text)',
          background: on ? 'var(--mg-primary-100)' : 'transparent'
        }
      }, o.label, on && /*#__PURE__*/React.createElement("i", {
        className: "pi pi-check",
        style: {
          marginInlineStart: 'auto',
          fontSize: 12
        }
      }));
    })));
  }
  function A_avatar(name, role, size) {
    const grad = {
      admin: 'linear-gradient(135deg,#4C7DF0,#2B4987)',
      director: 'linear-gradient(135deg,#E6A46E,#C67E3C)',
      cfo: 'linear-gradient(135deg,#E6A46E,#C67E3C)',
      lawyer: 'linear-gradient(135deg,#78B0E0,#4A82BC)',
      manager: 'linear-gradient(135deg,#4FBF8F,#2E9469)',
      accountant: 'linear-gradient(135deg,#9AA3B2,#6C7688)'
    };
    const w = name.trim().split(/\s+/);
    const ini = (w.length >= 2 ? w[0][0] + w[1][0] : w[0].slice(0, 2)).toUpperCase();
    return /*#__PURE__*/React.createElement("span", {
      style: {
        width: size,
        height: size,
        borderRadius: '50%',
        flexShrink: 0,
        background: grad[role] || grad.accountant,
        color: '#fff',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        fontSize: size * 0.36,
        fontWeight: 600
      }
    }, ini);
  }

  /* ── tree helpers ── */
  function A_walkUpdate(nodes, id, fn) {
    return nodes.map(n => n.id === id ? fn(n) : {
      ...n,
      children: A_walkUpdate(n.children, id, fn)
    });
  }
  function A_flatten(nodes, depth, acc) {
    acc = acc || [];
    nodes.forEach(n => {
      acc.push({
        id: n.id,
        name: n.name,
        depth: depth || 0
      });
      A_flatten(n.children, (depth || 0) + 1, acc);
    });
    return acc;
  }
  function A_removeNode(nodes, id) {
    const kept = [];
    let orphans = [];
    nodes.forEach(n => {
      if (n.id === id) {
        orphans = orphans.concat(n.children);
      } else {
        const r = A_removeNode(n.children, id);
        n = {
          ...n,
          children: r.nodes
        };
        orphans = orphans.concat(r.orphans);
        kept.push(n);
      }
    });
    return {
      nodes: kept,
      orphans
    };
  }
  function A_countAll(n) {
    return 1;
  }

  /* ── Department tree (redesigned) ── */
  function A_TreeNode({
    node,
    depth,
    selectedId,
    onSelect,
    onEdit,
    onDelete,
    expanded,
    toggle,
    editing
  }) {
    const isOpen = expanded[node.id] !== false;
    const on = selectedId === node.id;
    return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("div", {
      className: "atnode",
      onClick: () => onSelect(node),
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        padding: '9px 12px',
        paddingInlineStart: 12 + depth * 22,
        borderRadius: 'var(--mg-radius-md)',
        cursor: 'pointer',
        background: on ? 'var(--mg-primary-100)' : 'transparent',
        transition: 'background .12s'
      }
    }, node.children.length > 0 ? /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + (isOpen ? 'pi-chevron-down' : 'pi-chevron-right'),
      onClick: e => {
        e.stopPropagation();
        toggle(node.id);
      },
      style: {
        fontSize: 11,
        color: 'var(--c-muted)',
        width: 14,
        cursor: 'pointer'
      }
    }) : /*#__PURE__*/React.createElement("span", {
      style: {
        width: 14
      }
    }), /*#__PURE__*/React.createElement("i", {
      className: "pi pi-building",
      style: {
        fontSize: 14,
        color: on ? 'var(--mg-primary-900)' : 'var(--c-muted)'
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 13.5,
        fontWeight: on ? 600 : 500,
        color: on ? 'var(--mg-primary-900)' : 'var(--c-text)',
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, node.name), /*#__PURE__*/React.createElement("span", {
      style: {
        display: 'inline-flex',
        alignItems: 'center',
        gap: 4,
        fontSize: 12,
        color: 'var(--c-muted)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-users",
      style: {
        fontSize: 11
      }
    }), node.members.length), editing && /*#__PURE__*/React.createElement("span", {
      className: "atacts",
      style: {
        display: 'inline-flex',
        gap: 2,
        opacity: 0,
        transition: 'opacity .12s'
      }
    }, /*#__PURE__*/React.createElement("button", {
      className: "uicon",
      onClick: e => {
        e.stopPropagation();
        onEdit(node);
      },
      title: "\u0420\u0435\u0434\u0430\u043A\u0442\u0438\u0440\u043E\u0432\u0430\u0442\u044C",
      style: {
        width: 28,
        height: 28,
        borderRadius: 7,
        border: 'none',
        background: 'transparent',
        cursor: 'pointer'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-pencil",
      style: {
        fontSize: 12,
        color: 'var(--c-text2)'
      }
    })), /*#__PURE__*/React.createElement("button", {
      className: "uicon",
      onClick: e => {
        e.stopPropagation();
        onDelete(node);
      },
      title: "\u0423\u0434\u0430\u043B\u0438\u0442\u044C",
      style: {
        width: 28,
        height: 28,
        borderRadius: 7,
        border: 'none',
        background: 'transparent',
        cursor: 'pointer'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-trash",
      style: {
        fontSize: 12,
        color: 'var(--mg-status-danger-text)'
      }
    })))), isOpen && node.children.map(c => /*#__PURE__*/React.createElement(A_TreeNode, {
      key: c.id,
      node: c,
      depth: depth + 1,
      selectedId: selectedId,
      onSelect: onSelect,
      onEdit: onEdit,
      onDelete: onDelete,
      expanded: expanded,
      toggle: toggle,
      editing: editing
    })));
  }

  /* ── Org chart (Схема) ── */
  function A_ChartNode({
    node
  }) {
    const [open, setOpen] = useState(false);
    return /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center'
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: () => setOpen(o => !o),
      style: {
        width: 210,
        background: 'var(--c-card)',
        border: '1px solid ' + (open ? 'var(--mg-primary-900)' : 'var(--c-border2)'),
        borderRadius: 'var(--mg-radius-md)',
        boxShadow: 'var(--mg-shadow-sm)',
        padding: '11px 14px',
        cursor: 'pointer',
        textAlign: 'center'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 13.5,
        fontWeight: 600,
        color: 'var(--c-text)'
      }
    }, node.name), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 12,
        color: 'var(--c-muted)',
        marginTop: 3
      }
    }, node.manager), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'inline-flex',
        alignItems: 'center',
        gap: 5,
        marginTop: 7,
        fontSize: 11.5,
        fontWeight: 600,
        padding: '2px 9px',
        borderRadius: 999,
        background: 'var(--mg-primary-100)',
        color: 'var(--mg-primary-900)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-users",
      style: {
        fontSize: 10
      }
    }), node.members.length, /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + (open ? 'pi-chevron-up' : 'pi-chevron-down'),
      style: {
        fontSize: 9,
        marginInlineStart: 1
      }
    })), open && /*#__PURE__*/React.createElement("div", {
      style: {
        marginTop: 11,
        paddingTop: 11,
        borderTop: '1px solid var(--c-border)',
        display: 'flex',
        flexDirection: 'column',
        gap: 8,
        textAlign: 'start'
      }
    }, node.members.length ? node.members.map(m => /*#__PURE__*/React.createElement("div", {
      key: m.id,
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8
      }
    }, A_avatar(m.full_name, m.role, 24), /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        minWidth: 0,
        fontSize: 12,
        fontWeight: 500,
        color: 'var(--c-text)',
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, m.full_name))) : /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 12,
        color: 'var(--c-muted)'
      }
    }, A_T.dep.noMembers))), node.children.length > 0 && /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("div", {
      style: {
        width: 1,
        height: 20,
        background: 'var(--c-border2)'
      }
    }), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 26,
        position: 'relative',
        paddingTop: 20,
        borderTop: node.children.length > 1 ? '1px solid var(--c-border2)' : 'none',
        alignItems: 'flex-start'
      }
    }, node.children.map(c => /*#__PURE__*/React.createElement("div", {
      key: c.id,
      style: {
        position: 'relative'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        position: 'absolute',
        top: -20,
        insetInlineStart: '50%',
        width: 1,
        height: 20,
        background: 'var(--c-border2)'
      }
    }), /*#__PURE__*/React.createElement(A_ChartNode, {
      node: c
    }))))));
  }

  /* ── Department side panel (drawer) ── */
  function A_SidePanel({
    mode,
    form,
    setForm,
    parentOptions,
    userOptions,
    onClose,
    onSave,
    onOpenPicker,
    onRemoveMember
  }) {
    const set = (k, v) => setForm(s => ({
      ...s,
      [k]: v
    }));
    return /*#__PURE__*/React.createElement("div", {
      onClick: onClose,
      style: {
        position: 'fixed',
        inset: 0,
        zIndex: 1300,
        background: 'rgba(9,16,32,0.45)',
        display: 'flex',
        justifyContent: 'flex-end'
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: e => e.stopPropagation(),
      style: {
        width: 440,
        maxWidth: '92vw',
        height: '100%',
        background: 'var(--c-card)',
        borderInlineStart: '1px solid var(--c-border)',
        boxShadow: 'var(--mg-shadow-lg)',
        display: 'flex',
        flexDirection: 'column'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '16px 20px',
        borderBottom: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 16,
        fontWeight: 600
      }
    }, mode === 'create' ? A_T.dep.add : A_T.dep.edit), /*#__PURE__*/React.createElement("i", {
      className: "pi pi-times",
      onClick: onClose,
      style: {
        fontSize: 14,
        color: 'var(--c-muted)',
        cursor: 'pointer'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        flex: 1,
        overflowY: 'auto',
        padding: 20,
        display: 'flex',
        flexDirection: 'column',
        gap: 16
      }
    }, /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("label", {
      style: A_lbl
    }, A_T.dep.name), /*#__PURE__*/React.createElement("input", {
      className: "inp",
      autoFocus: true,
      value: form.name,
      onChange: e => set('name', e.target.value),
      placeholder: A_T.dep.name,
      style: A_inp
    })), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("label", {
      style: A_lbl
    }, A_T.dep.parent), /*#__PURE__*/React.createElement(A_Dropdown, {
      value: form.parentId,
      onChange: v => set('parentId', v),
      options: [{
        value: null,
        label: A_T.dep.root
      }].concat(parentOptions),
      placeholder: A_T.dep.root
    })), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("label", {
      style: A_lbl
    }, A_T.dep.manager), /*#__PURE__*/React.createElement(A_Dropdown, {
      value: form.managerId,
      onChange: v => set('managerId', v),
      options: userOptions,
      placeholder: "\u2014",
      filter: true
    })), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        marginBottom: 8
      }
    }, /*#__PURE__*/React.createElement("label", {
      style: {
        ...A_lbl,
        margin: 0,
        flex: 1
      }
    }, A_T.dep.members), /*#__PURE__*/React.createElement("button", {
      style: {
        ...A_btn('text'),
        height: 30,
        padding: '0 8px',
        fontSize: 12.5,
        color: 'var(--mg-primary-900)'
      },
      onClick: onOpenPicker
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-plus",
      style: {
        fontSize: 11
      }
    }), A_T.dep.addMember)), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        gap: 6
      }
    }, form.members.length === 0 && /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 13,
        color: 'var(--c-muted)',
        padding: '10px 0'
      }
    }, A_T.dep.noMembers), form.members.map(m => /*#__PURE__*/React.createElement("div", {
      key: m.id,
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '7px 10px',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-md)'
      }
    }, A_avatar(m.full_name, m.role, 28), /*#__PURE__*/React.createElement("div", {
      style: {
        flex: 1,
        minWidth: 0
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 13,
        fontWeight: 500,
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, m.full_name)), /*#__PURE__*/React.createElement("i", {
      className: "pi pi-times",
      onClick: () => onRemoveMember(m.id),
      title: A_T.dep.removeMember,
      style: {
        fontSize: 12,
        color: 'var(--c-muted)',
        cursor: 'pointer'
      }
    })))))), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        justifyContent: 'flex-end',
        gap: 10,
        padding: '14px 20px',
        borderTop: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("button", {
      style: A_btn('ghost'),
      onClick: onClose
    }, "\u041E\u0442\u043C\u0435\u043D\u0430"), /*#__PURE__*/React.createElement("button", {
      style: A_btn('primary'),
      onClick: onSave
    }, "\u0421\u043E\u0445\u0440\u0430\u043D\u0438\u0442\u044C"))));
  }
  const A_lbl = {
    display: 'block',
    fontSize: 13,
    fontWeight: 500,
    color: 'var(--c-text)',
    marginBottom: 6
  };
  const A_inp = {
    width: '100%',
    height: 40,
    padding: '0 13px',
    borderRadius: 'var(--mg-radius-md)',
    border: '1px solid var(--mg-input-border)',
    background: 'var(--c-card)',
    color: 'var(--c-text)',
    fontFamily: 'var(--mg-font-sans)',
    fontSize: 14,
    boxSizing: 'border-box'
  };

  /* ── member picker dialog ── */
  function A_MemberPicker({
    available,
    close,
    onAdd
  }) {
    const [sel, setSel] = useState([]);
    const [q, setQ] = useState('');
    const list = available.filter(u => u.full_name.toLowerCase().includes(q.toLowerCase()));
    const toggle = id => setSel(s => s.includes(id) ? s.filter(x => x !== id) : [...s, id]);
    return /*#__PURE__*/React.createElement("div", {
      onClick: close,
      style: {
        position: 'fixed',
        inset: 0,
        zIndex: 1400,
        background: 'rgba(9,16,32,0.55)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: 20
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: e => e.stopPropagation(),
      style: {
        width: 440,
        maxWidth: '92vw',
        maxHeight: 'calc(100vh - 40px)',
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-lg)',
        display: 'flex',
        flexDirection: 'column'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '16px 20px',
        borderBottom: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 16,
        fontWeight: 600
      }
    }, A_T.dep.addMember), /*#__PURE__*/React.createElement("i", {
      className: "pi pi-times",
      onClick: close,
      style: {
        fontSize: 14,
        color: 'var(--c-muted)',
        cursor: 'pointer'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        padding: '14px 20px'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        height: 40,
        padding: '0 12px',
        border: '1px solid var(--mg-input-border)',
        borderRadius: 'var(--mg-radius-md)',
        background: 'var(--c-page)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-search",
      style: {
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }), /*#__PURE__*/React.createElement("input", {
      autoFocus: true,
      value: q,
      onChange: e => setQ(e.target.value),
      placeholder: "\u041F\u043E\u0438\u0441\u043A\u2026",
      style: {
        flex: 1,
        border: 'none',
        outline: 'none',
        background: 'transparent',
        fontFamily: 'var(--mg-font-sans)',
        fontSize: 14,
        color: 'var(--c-text)'
      }
    }))), /*#__PURE__*/React.createElement("div", {
      style: {
        flex: 1,
        overflowY: 'auto',
        padding: '0 12px 8px'
      }
    }, list.map(u => {
      const on = sel.includes(u.id);
      return /*#__PURE__*/React.createElement("div", {
        key: u.id,
        className: "urow",
        onClick: () => toggle(u.id),
        style: {
          display: 'flex',
          alignItems: 'center',
          gap: 11,
          padding: '8px 10px',
          borderRadius: 8,
          cursor: 'pointer'
        }
      }, /*#__PURE__*/React.createElement(A_Check, {
        checked: on,
        onChange: () => toggle(u.id)
      }), A_avatar(u.full_name, u.role, 30), /*#__PURE__*/React.createElement("div", {
        style: {
          flex: 1,
          minWidth: 0
        }
      }, /*#__PURE__*/React.createElement("div", {
        style: {
          fontSize: 13.5,
          fontWeight: 500
        }
      }, u.full_name), /*#__PURE__*/React.createElement("div", {
        style: {
          fontSize: 12,
          color: 'var(--c-muted)'
        }
      }, u.email)));
    }), list.length === 0 && /*#__PURE__*/React.createElement("div", {
      style: {
        padding: '20px 10px',
        fontSize: 13,
        color: 'var(--c-muted)',
        textAlign: 'center'
      }
    }, "\u041D\u0438\u0447\u0435\u0433\u043E \u043D\u0435 \u043D\u0430\u0439\u0434\u0435\u043D\u043E")), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        justifyContent: 'flex-end',
        gap: 10,
        padding: '14px 20px',
        borderTop: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("button", {
      style: A_btn('ghost'),
      onClick: close
    }, "\u041E\u0442\u043C\u0435\u043D\u0430"), /*#__PURE__*/React.createElement("button", {
      style: {
        ...A_btn('primary'),
        opacity: sel.length ? 1 : 0.5,
        pointerEvents: sel.length ? 'auto' : 'none'
      },
      onClick: () => onAdd(sel)
    }, "\u0414\u043E\u0431\u0430\u0432\u0438\u0442\u044C"))));
  }

  /* ── confirm delete ── */
  function A_Confirm({
    message,
    onAccept,
    close
  }) {
    return /*#__PURE__*/React.createElement("div", {
      onClick: close,
      style: {
        position: 'fixed',
        inset: 0,
        zIndex: 1500,
        background: 'rgba(9,16,32,0.55)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: 20
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: e => e.stopPropagation(),
      style: {
        width: 430,
        maxWidth: '92vw',
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-lg)'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '16px 20px',
        borderBottom: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 16,
        fontWeight: 600
      }
    }, A_T.dep.del), /*#__PURE__*/React.createElement("i", {
      className: "pi pi-times",
      onClick: close,
      style: {
        fontSize: 14,
        color: 'var(--c-muted)',
        cursor: 'pointer'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        padding: 20,
        display: 'flex',
        gap: 14,
        alignItems: 'flex-start'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        width: 40,
        height: 40,
        borderRadius: '50%',
        background: 'var(--mg-status-danger-bg)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        flexShrink: 0
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-exclamation-triangle",
      style: {
        fontSize: 17,
        color: 'var(--mg-status-danger-text)'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 14,
        color: 'var(--c-text2)',
        lineHeight: 1.55,
        paddingTop: 3
      }
    }, message)), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        justifyContent: 'flex-end',
        gap: 10,
        padding: '14px 20px',
        borderTop: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("button", {
      style: A_btn('ghost'),
      onClick: close
    }, "\u041E\u0442\u043C\u0435\u043D\u0430"), /*#__PURE__*/React.createElement("button", {
      style: A_btn('danger'),
      onClick: onAccept
    }, "\u0423\u0434\u0430\u043B\u0438\u0442\u044C"))));
  }
  function A_Toasts({
    items
  }) {
    return /*#__PURE__*/React.createElement("div", {
      style: {
        position: 'fixed',
        insetBlockEnd: 20,
        insetInlineEnd: 20,
        zIndex: 2000,
        display: 'flex',
        flexDirection: 'column',
        gap: 10
      }
    }, items.map(t => /*#__PURE__*/React.createElement("div", {
      key: t.id,
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        minWidth: 240,
        maxWidth: 360,
        padding: '12px 14px',
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderInlineStart: '3px solid var(--mg-status-success-text)',
        borderRadius: 'var(--mg-radius-md)',
        boxShadow: 'var(--mg-shadow-lg)',
        animation: 'toastIn .2s ease'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-check-circle",
      style: {
        fontSize: 16,
        color: 'var(--mg-status-success-text)'
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 13.5,
        fontWeight: 500
      }
    }, t.summary))));
  }

  /* ═══════════════ TABS ═══════════════ */
  function A_DepartmentsTab({
    pushToast
  }) {
    const [tree, setTree] = useState(A_TREE0);
    const [search, setSearch] = useState('');
    const [viewMode, setViewMode] = useState('tree');
    const [selectedId, setSelectedId] = useState(2);
    const [expanded, setExpanded] = useState({});
    const [panel, setPanel] = useState(null);
    const [form, setForm] = useState({
      name: '',
      parentId: null,
      managerId: null,
      members: []
    });
    const [picker, setPicker] = useState(false);
    const [confirm, setConfirm] = useState(null);
    const [editing, setEditing] = useState(false);
    const toggle = id => setExpanded(e => ({
      ...e,
      [id]: e[id] === false ? true : false
    }));
    const findNode = (nodes, id) => {
      for (const n of nodes) {
        if (n.id === id) return n;
        const f = findNode(n.children, id);
        if (f) return f;
      }
      return null;
    };
    const selected = findNode(tree, selectedId);
    const parentOptions = A_flatten(tree).map(d => ({
      value: d.id,
      label: '— '.repeat(d.depth) + d.name
    }));
    const userOptions = A_ALL_USERS.map(u => ({
      value: u.id,
      label: u.full_name
    }));
    function openCreate() {
      setForm({
        name: '',
        parentId: selectedId || null,
        managerId: null,
        members: []
      });
      setPanel('create');
    }
    function openEdit(n) {
      setForm({
        id: n.id,
        name: n.name,
        parentId: null,
        managerId: (A_ALL_USERS.find(u => u.full_name === n.manager) || {}).id ?? null,
        members: n.members.slice()
      });
      setPanel('edit');
    }
    function savePanel() {
      if (!form.name.trim()) return;
      const mgr = (A_ALL_USERS.find(u => u.id === form.managerId) || {}).full_name || '—';
      if (panel === 'edit') {
        setTree(t => A_walkUpdate(t, form.id, n => ({
          ...n,
          name: form.name.trim(),
          manager: mgr,
          members: form.members
        })));
      } else {
        const id = Date.now();
        const node = {
          id,
          name: form.name.trim(),
          manager: mgr,
          members: form.members,
          children: []
        };
        setTree(t => form.parentId ? A_walkUpdate(t, form.parentId, n => ({
          ...n,
          children: [...n.children, node]
        })) : [...t, node]);
        if (form.parentId) setExpanded(e => ({
          ...e,
          [form.parentId]: false
        }));
      }
      pushToast(A_T.dep.saved);
      setPanel(null);
    }
    function doDelete(n) {
      setTree(t => A_removeNode(t, n.id).nodes);
      if (selectedId === n.id) setSelectedId(null);
      pushToast(A_T.dep.deleted);
      setConfirm(null);
    }
    function addMembers(ids) {
      const add = A_ALL_USERS.filter(u => ids.includes(u.id) && !form.members.some(m => m.id === u.id));
      setForm(f => ({
        ...f,
        members: [...f.members, ...add]
      }));
      setPicker(false);
    }
    function removeMember(id) {
      setForm(f => ({
        ...f,
        members: f.members.filter(m => m.id !== id)
      }));
    }
    const q = search.trim().toLowerCase();
    const filterTree = nodes => nodes.map(n => ({
      ...n,
      children: filterTree(n.children)
    })).filter(n => !q || n.name.toLowerCase().includes(q) || n.children.length);
    const shown = filterTree(tree);
    return /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        gap: 16
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        flexWrap: 'wrap'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        height: 40,
        padding: '0 12px',
        flex: 1,
        minWidth: 180,
        maxWidth: 280,
        border: '1px solid var(--mg-input-border)',
        borderRadius: 'var(--mg-radius-md)',
        background: 'var(--c-card)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-search",
      style: {
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }), /*#__PURE__*/React.createElement("input", {
      value: search,
      onChange: e => setSearch(e.target.value),
      placeholder: "\u041F\u043E\u0438\u0441\u043A\u2026",
      style: {
        flex: 1,
        border: 'none',
        outline: 'none',
        background: 'transparent',
        fontFamily: 'var(--mg-font-sans)',
        fontSize: 14,
        color: 'var(--c-text)'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'inline-flex',
        padding: 3,
        gap: 2,
        background: 'var(--c-muted2)',
        borderRadius: 'var(--mg-radius-md)'
      }
    }, [['tree', A_T.dep.viewTree, 'pi-sitemap'], ['chart', A_T.dep.viewChart, 'pi-share-alt']].map(([k, l, ic]) => /*#__PURE__*/React.createElement("button", {
      key: k,
      onClick: () => setViewMode(k),
      style: {
        display: 'inline-flex',
        alignItems: 'center',
        gap: 6,
        height: 32,
        padding: '0 12px',
        border: 'none',
        borderRadius: 'var(--mg-radius-sm)',
        cursor: 'pointer',
        fontFamily: 'var(--mg-font-sans)',
        fontSize: 12.5,
        fontWeight: 600,
        background: viewMode === k ? 'var(--c-card)' : 'transparent',
        color: viewMode === k ? 'var(--mg-primary-900)' : 'var(--c-text2)',
        boxShadow: viewMode === k ? 'var(--mg-shadow-sm)' : 'none'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + ic,
      style: {
        fontSize: 12
      }
    }), l))), /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1
      }
    }), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 10
      }
    }, /*#__PURE__*/React.createElement("button", {
      style: editing ? {
        ...A_btn('ghost'),
        color: 'var(--mg-primary-900)',
        borderColor: 'var(--mg-primary-900)'
      } : A_btn('ghost'),
      onClick: () => setEditing(e => !e)
    }, /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + (editing ? 'pi-check' : 'pi-pencil'),
      style: {
        fontSize: 12
      }
    }), editing ? 'Завершить редактирование' : 'Редактировать'), /*#__PURE__*/React.createElement("button", {
      style: A_btn('primary'),
      onClick: openCreate
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-plus",
      style: {
        fontSize: 12
      }
    }), A_T.dep.add))), viewMode === 'tree' ? /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'grid',
        gridTemplateColumns: '1fr 340px',
        gap: 16,
        alignItems: 'start'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-sm)',
        padding: 8
      }
    }, shown.length ? shown.map(n => /*#__PURE__*/React.createElement(A_TreeNode, {
      key: n.id,
      node: n,
      depth: 0,
      selectedId: selectedId,
      onSelect: d => setSelectedId(d.id),
      onEdit: openEdit,
      onDelete: d => setConfirm(d),
      expanded: expanded,
      toggle: toggle,
      editing: editing
    })) : /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        gap: 10,
        padding: '48px 20px',
        color: 'var(--c-muted)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-building",
      style: {
        fontSize: 28,
        opacity: 0.3
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 14,
        color: 'var(--c-text2)',
        fontWeight: 500
      }
    }, A_T.dep.empty), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 13,
        textAlign: 'center',
        maxWidth: 240
      }
    }, A_T.dep.emptyHint), /*#__PURE__*/React.createElement("button", {
      style: {
        ...A_btn('ghost'),
        marginTop: 4
      },
      onClick: openCreate
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-plus",
      style: {
        fontSize: 12
      }
    }), A_T.dep.add))), selected ? /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-sm)',
        overflow: 'hidden'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '14px 16px',
        borderBottom: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        flex: 1,
        minWidth: 0
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 15,
        fontWeight: 600,
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, selected.name), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 12,
        color: 'var(--c-muted)',
        marginTop: 2
      }
    }, "\u0420\u0443\u043A\u043E\u0432\u043E\u0434\u0438\u0442\u0435\u043B\u044C: ", selected.manager)), editing && /*#__PURE__*/React.createElement("button", {
      className: "uicon",
      onClick: () => openEdit(selected),
      title: "\u0420\u0435\u0434\u0430\u043A\u0442\u0438\u0440\u043E\u0432\u0430\u0442\u044C",
      style: {
        width: 30,
        height: 30,
        borderRadius: 7,
        border: 'none',
        background: 'transparent',
        cursor: 'pointer'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-pencil",
      style: {
        fontSize: 13,
        color: 'var(--c-text2)'
      }
    }))), /*#__PURE__*/React.createElement("div", {
      style: {
        padding: 4
      }
    }, selected.members.length ? selected.members.map(m => /*#__PURE__*/React.createElement("div", {
      key: m.id,
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '8px 12px'
      }
    }, A_avatar(m.full_name, m.role, 30), /*#__PURE__*/React.createElement("div", {
      style: {
        flex: 1,
        minWidth: 0
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 13,
        fontWeight: 500,
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, m.full_name), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 11.5,
        color: 'var(--c-muted)',
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, m.email)), /*#__PURE__*/React.createElement(A_Tag, {
      value: A_ROLE_LABEL[m.role],
      severity: A_ROLE_SEV[m.role]
    }))) : /*#__PURE__*/React.createElement("div", {
      style: {
        padding: '28px 12px',
        fontSize: 13,
        color: 'var(--c-muted)',
        textAlign: 'center'
      }
    }, A_T.dep.noMembers))) : /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px dashed var(--c-border2)',
        borderRadius: 'var(--mg-radius-lg)',
        padding: '40px 20px',
        textAlign: 'center',
        color: 'var(--c-muted)',
        fontSize: 13
      }
    }, "\u0412\u044B\u0431\u0435\u0440\u0438\u0442\u0435 \u043E\u0442\u0434\u0435\u043B, \u0447\u0442\u043E\u0431\u044B \u0443\u0432\u0438\u0434\u0435\u0442\u044C \u0441\u043E\u0442\u0440\u0443\u0434\u043D\u0438\u043A\u043E\u0432")) : /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-page)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        padding: '32px 20px',
        overflowX: 'auto'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 40,
        justifyContent: 'center',
        minWidth: 'min-content',
        alignItems: 'flex-start'
      }
    }, shown.map(n => /*#__PURE__*/React.createElement(A_ChartNode, {
      key: n.id,
      node: n
    })))), panel && /*#__PURE__*/React.createElement(A_SidePanel, {
      mode: panel,
      form: form,
      setForm: setForm,
      parentOptions: parentOptions.filter(o => o.value !== form.id),
      userOptions: userOptions,
      onClose: () => setPanel(null),
      onSave: savePanel,
      onOpenPicker: () => setPicker(true),
      onRemoveMember: removeMember
    }), picker && /*#__PURE__*/React.createElement(A_MemberPicker, {
      available: A_ALL_USERS.filter(u => !form.members.some(m => m.id === u.id)),
      close: () => setPicker(false),
      onAdd: addMembers
    }), confirm && /*#__PURE__*/React.createElement(A_Confirm, {
      message: A_T.dep.delConfirm(confirm.name),
      onAccept: () => doDelete(confirm),
      close: () => setConfirm(null)
    }));
  }
  function A_RolesTab({
    pushToast
  }) {
    const [perms, setPerms] = useState(() => {
      const m = {};
      A_ROLES.forEach(r => {
        m[r] = new Set(A_DEFAULT_PERMS[r]);
      });
      return m;
    });
    const [collapsed, setCollapsed] = useState({});
    const [dirty, setDirty] = useState(false);
    const has = (r, p) => perms[r].has(p);
    function toggle(p, r, v) {
      if (r === 'admin') return;
      setPerms(m => {
        const s = new Set(m[r]);
        v ? s.add(p) : s.delete(p);
        return {
          ...m,
          [r]: s
        };
      });
      setDirty(true);
    }
    function reset() {
      const m = {};
      A_ROLES.forEach(r => {
        m[r] = new Set(A_DEFAULT_PERMS[r]);
      });
      setPerms(m);
      setDirty(false);
    }
    function save() {
      pushToast(A_T.roles.saved);
      setDirty(false);
    }
    const th = {
      padding: '9px 12px',
      fontSize: 11,
      fontWeight: 600,
      letterSpacing: '.02em',
      textTransform: 'uppercase',
      color: 'var(--c-muted)',
      borderBottom: '1px solid var(--c-border)'
    };
    return /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        gap: 16
      }
    }, /*#__PURE__*/React.createElement(A_Msg, {
      severity: "secondary"
    }, A_T.roles.adminNote), A_GROUPS.map(g => {
      const open = !collapsed[g.key];
      return /*#__PURE__*/React.createElement("div", {
        key: g.key,
        style: {
          background: 'var(--c-card)',
          border: '1px solid var(--c-border)',
          borderRadius: 'var(--mg-radius-lg)',
          boxShadow: 'var(--mg-shadow-sm)',
          overflow: 'hidden'
        }
      }, /*#__PURE__*/React.createElement("div", {
        onClick: () => setCollapsed(c => ({
          ...c,
          [g.key]: !c[g.key]
        })),
        style: {
          display: 'flex',
          alignItems: 'center',
          gap: 10,
          padding: '12px 16px',
          cursor: 'pointer',
          background: 'var(--c-hover)',
          borderBottom: open ? '1px solid var(--c-border)' : 'none'
        }
      }, /*#__PURE__*/React.createElement("i", {
        className: 'pi ' + (open ? 'pi-chevron-down' : 'pi-chevron-right'),
        style: {
          fontSize: 12,
          color: 'var(--c-muted)'
        }
      }), /*#__PURE__*/React.createElement("span", {
        style: {
          flex: 1,
          fontSize: 14,
          fontWeight: 600
        }
      }, g.label), /*#__PURE__*/React.createElement("span", {
        style: {
          fontSize: 11.5,
          fontWeight: 600,
          padding: '2px 8px',
          borderRadius: 999,
          background: 'var(--c-muted2)',
          color: 'var(--c-text2)'
        }
      }, g.perms.length)), open && /*#__PURE__*/React.createElement("div", {
        style: {
          overflowX: 'auto'
        }
      }, /*#__PURE__*/React.createElement("table", {
        style: {
          width: '100%',
          borderCollapse: 'collapse',
          minWidth: 760
        }
      }, /*#__PURE__*/React.createElement("thead", null, /*#__PURE__*/React.createElement("tr", null, /*#__PURE__*/React.createElement("th", {
        style: {
          ...th,
          textAlign: 'start',
          minWidth: 260
        }
      }, A_T.roles.permissionLabel), A_ROLES.map(r => /*#__PURE__*/React.createElement("th", {
        key: r,
        style: {
          ...th,
          textAlign: 'center',
          width: 92
        }
      }, A_ROLE_SHORT[r])))), /*#__PURE__*/React.createElement("tbody", null, g.perms.map(p => /*#__PURE__*/React.createElement("tr", {
        key: p,
        className: "urow"
      }, /*#__PURE__*/React.createElement("td", {
        style: {
          padding: '10px 12px',
          borderBottom: '1px solid var(--c-border)'
        }
      }, /*#__PURE__*/React.createElement("div", {
        style: {
          display: 'flex',
          flexDirection: 'column',
          gap: 3
        }
      }, /*#__PURE__*/React.createElement("span", {
        style: {
          fontSize: 13.5,
          color: 'var(--c-text)'
        }
      }, A_PERM_LABEL[p] || p), /*#__PURE__*/React.createElement("code", {
        style: {
          fontFamily: 'ui-monospace,monospace',
          fontSize: 11,
          color: 'var(--c-muted)',
          background: 'var(--c-muted2)',
          padding: '1px 6px',
          borderRadius: 4,
          alignSelf: 'flex-start'
        }
      }, p))), A_ROLES.map(r => /*#__PURE__*/React.createElement("td", {
        key: r,
        style: {
          padding: '10px 12px',
          borderBottom: '1px solid var(--c-border)',
          textAlign: 'center'
        }
      }, /*#__PURE__*/React.createElement("div", {
        style: {
          display: 'flex',
          justifyContent: 'center'
        }
      }, /*#__PURE__*/React.createElement(A_Check, {
        checked: r === 'admin' ? true : has(r, p),
        disabled: r === 'admin',
        onChange: v => toggle(p, r, v)
      }))))))))));
    }), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        justifyContent: 'flex-end',
        gap: 10,
        paddingTop: 2
      }
    }, /*#__PURE__*/React.createElement("button", {
      style: {
        ...A_btn('ghost'),
        opacity: dirty ? 1 : 0.5,
        pointerEvents: dirty ? 'auto' : 'none'
      },
      onClick: reset
    }, A_T.roles.reset), /*#__PURE__*/React.createElement("button", {
      style: {
        ...A_btn('primary'),
        opacity: dirty ? 1 : 0.5,
        pointerEvents: dirty ? 'auto' : 'none'
      },
      onClick: save
    }, A_T.roles.save)));
  }
  function A_VisibilityTab({
    pushToast
  }) {
    const [vis, setVis] = useState(() => ({
      ...A_DEFAULT_VIS
    }));
    const [dirty, setDirty] = useState(false);
    const opts = [{
      value: 'all',
      label: A_T.vis.scopeAll
    }, {
      value: 'department',
      label: A_T.vis.scopeDepartment
    }, {
      value: 'own',
      label: A_T.vis.scopeOwn
    }];
    function setScope(r, v) {
      setVis(m => ({
        ...m,
        [r]: v
      }));
      setDirty(true);
    }
    function reset() {
      setVis({
        ...A_DEFAULT_VIS
      });
      setDirty(false);
    }
    function save() {
      pushToast(A_T.vis.saved);
      setDirty(false);
    }
    const th = {
      padding: '11px 16px',
      fontSize: 11.5,
      fontWeight: 600,
      letterSpacing: '.03em',
      textTransform: 'uppercase',
      color: 'var(--c-muted)',
      textAlign: 'start',
      borderBottom: '1px solid var(--c-border)'
    };
    return /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        gap: 16,
        maxWidth: 640
      }
    }, /*#__PURE__*/React.createElement(A_Msg, {
      severity: "warn"
    }, A_T.vis.warning), /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-sm)',
        overflow: 'hidden'
      }
    }, /*#__PURE__*/React.createElement("table", {
      style: {
        width: '100%',
        borderCollapse: 'collapse'
      }
    }, /*#__PURE__*/React.createElement("thead", null, /*#__PURE__*/React.createElement("tr", null, /*#__PURE__*/React.createElement("th", {
      style: {
        ...th,
        width: 200
      }
    }, A_T.vis.roleColumn), /*#__PURE__*/React.createElement("th", {
      style: th
    }, A_T.vis.scopeColumn))), /*#__PURE__*/React.createElement("tbody", null, A_ROLES.map(r => /*#__PURE__*/React.createElement("tr", {
      key: r,
      className: "urow"
    }, /*#__PURE__*/React.createElement("td", {
      style: {
        padding: '12px 16px',
        borderBottom: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement(A_Tag, {
      value: A_ROLE_LABEL[r],
      severity: A_ROLE_SEV[r]
    })), /*#__PURE__*/React.createElement("td", {
      style: {
        padding: '10px 16px',
        borderBottom: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement(A_Dropdown, {
      value: vis[r],
      onChange: v => setScope(r, v),
      options: opts,
      width: 240
    }))))))), /*#__PURE__*/React.createElement(A_Msg, {
      severity: "secondary"
    }, A_T.vis.departmentHint), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        justifyContent: 'flex-end',
        gap: 10
      }
    }, /*#__PURE__*/React.createElement("button", {
      style: {
        ...A_btn('ghost'),
        opacity: dirty ? 1 : 0.5,
        pointerEvents: dirty ? 'auto' : 'none'
      },
      onClick: reset
    }, A_T.vis.reset), /*#__PURE__*/React.createElement("button", {
      style: {
        ...A_btn('primary'),
        opacity: dirty ? 1 : 0.5,
        pointerEvents: dirty ? 'auto' : 'none'
      },
      onClick: save
    }, A_T.vis.save)));
  }
  function AccessTab() {
    const [tab, setTab] = useState('departments');
    const [toasts, setToasts] = useState([]);
    const pushToast = summary => {
      const id = Date.now() + Math.random();
      setToasts(t => [...t, {
        id,
        summary
      }]);
      setTimeout(() => setToasts(t => t.filter(x => x.id !== id)), 2600);
    };
    const TABS = [['departments', A_T.tabs.departments], ['roles', A_T.tabs.roles], ['visibility', A_T.tabs.visibility]];
    return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("div", {
      style: {
        marginBottom: 18
      }
    }, /*#__PURE__*/React.createElement("h2", {
      style: {
        margin: 0,
        fontSize: 20,
        fontWeight: 600
      }
    }, A_T.title), /*#__PURE__*/React.createElement("p", {
      style: {
        margin: '5px 0 0',
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }, A_T.subtitle)), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 4,
        borderBottom: '1px solid var(--c-border)',
        marginBottom: 20
      }
    }, TABS.map(([k, l]) => {
      const on = tab === k;
      return /*#__PURE__*/React.createElement("button", {
        key: k,
        onClick: () => setTab(k),
        style: {
          background: 'transparent',
          border: 'none',
          cursor: 'pointer',
          padding: '10px 14px',
          marginBottom: -1,
          whiteSpace: 'nowrap',
          fontFamily: 'var(--mg-font-sans)',
          fontSize: 14,
          fontWeight: on ? 600 : 500,
          color: on ? 'var(--mg-primary-900)' : 'var(--c-muted)',
          borderBottom: '2px solid ' + (on ? 'var(--mg-primary-900)' : 'transparent')
        }
      }, l);
    })), tab === 'departments' && /*#__PURE__*/React.createElement(A_DepartmentsTab, {
      pushToast: pushToast
    }), tab === 'roles' && /*#__PURE__*/React.createElement(A_RolesTab, {
      pushToast: pushToast
    }), tab === 'visibility' && /*#__PURE__*/React.createElement(A_VisibilityTab, {
      pushToast: pushToast
    }), /*#__PURE__*/React.createElement(A_Toasts, {
      items: toasts
    }));
  }
  window.AccessTab = AccessTab;
})();
})(); } catch (e) { __ds_ns.__errors.push({ path: "redesign/access-section.jsx", error: String((e && e.message) || e) }); }

// redesign/handoff_settings_redesign/design/access-section.jsx
try { (() => {
/* Доступ и оргструктура — Настройки → Система.
   Mirrors front/src/pages/AccessControlPage (index + DepartmentsTab, DepartmentTree,
   DepartmentSidePanel, OrgChartView, RolesPermissionsTab, PermissionMatrix,
   VisibilityScopeTab) + entities/accessControl + ru.json. Redesigned look.
   Exposes window.AccessTab. */
(function () {
  const {
    useState,
    useRef,
    useEffect
  } = React;

  /* ── i18n (ru.json → accessControl) ── */
  const A_T = {
    title: 'Доступ и оргструктура',
    subtitle: 'Отделы, роли и видимость записей',
    tabs: {
      departments: 'Отделы',
      roles: 'Роли и права',
      visibility: 'Видимость'
    },
    dep: {
      add: 'Добавить отдел',
      edit: 'Редактировать отдел',
      del: 'Удалить отдел',
      delConfirm: n => 'Удалить отдел «' + n + '»? Дочерние отделы и сотрудники останутся без родителя.',
      name: 'Название',
      parent: 'Родительский отдел',
      root: '— Корень —',
      manager: 'Руководитель',
      members: 'Сотрудники отдела',
      addMember: 'Добавить сотрудника',
      removeMember: 'Убрать из отдела',
      viewTree: 'Дерево',
      viewChart: 'Схема',
      empty: 'Отделы не созданы',
      emptyHint: 'Добавьте первый отдел, чтобы настроить оргструктуру',
      noMembers: 'Нет участников',
      saved: 'Отдел сохранён',
      deleted: 'Отдел удалён'
    },
    roles: {
      title: 'Матрица прав',
      adminNote: 'Роль admin всегда получает все права и не может быть ограничена.',
      permissionLabel: 'Право',
      save: 'Сохранить',
      reset: 'Сбросить',
      saved: 'Права ролей сохранены'
    },
    vis: {
      title: 'Видимость записей',
      warning: 'Настройки видимости влияют на то, какие записи (сделки, контакты, задачи) видит каждая роль. Изменяйте с осторожностью.',
      scopeAll: 'Все',
      scopeDepartment: 'Отдел (+подотделы)',
      scopeOwn: 'Свои',
      roleColumn: 'Роль',
      scopeColumn: 'Видимость записей',
      departmentHint: 'Значение «Отдел» работает только если у пользователя указан отдел.',
      save: 'Сохранить',
      reset: 'Сбросить',
      saved: 'Настройки видимости сохранены'
    }
  };
  const A_ROLES = ['admin', 'director', 'lawyer', 'manager', 'accountant', 'cfo'];
  const A_ROLE_LABEL = {
    admin: 'Администратор',
    director: 'Директор',
    lawyer: 'Юрист',
    manager: 'Менеджер',
    accountant: 'Бухгалтер',
    cfo: 'Финансовый директор'
  };
  const A_ROLE_SHORT = {
    admin: 'Админ',
    director: 'Директор',
    lawyer: 'Юрист',
    manager: 'Менеджер',
    accountant: 'Бухгалтер',
    cfo: 'Фин. дир.'
  };
  const A_ROLE_SEV = {
    admin: 'danger',
    director: 'warn',
    lawyer: 'info',
    manager: 'success',
    accountant: 'secondary',
    cfo: 'secondary'
  };
  const A_GROUPS = [{
    key: 'crm',
    label: 'CRM',
    perms: ['crm.view', 'crm.manage']
  }, {
    key: 'sales',
    label: 'Продажи',
    perms: ['sales.view', 'sales.manage']
  }, {
    key: 'contracts',
    label: 'Договоры',
    perms: ['contracts.view', 'contracts.manage']
  }, {
    key: 'users',
    label: 'Пользователи',
    perms: ['users.view', 'users.manage']
  }, {
    key: 'automation',
    label: 'Автоматизации',
    perms: ['automation.manage']
  }, {
    key: 'analytics',
    label: 'Аналитика',
    perms: ['analytics.view', 'settings.manage']
  }, {
    key: 'finance',
    label: 'Финансы',
    perms: ['finance.view', 'finance.entry', 'finance.posting', 'finance.journals.manual', 'finance.payments.approve', 'finance.period.close', 'finance.settings.manage', 'finance.reports.management']
  }, {
    key: 'system',
    label: 'Системные права',
    perms: ['admin-write', 'dedup-scan-all', 'view-manager-cabinet', 'system-reset']
  }];
  const A_PERM_LABEL = {
    'crm.view': 'CRM — просмотр',
    'crm.manage': 'CRM — управление',
    'sales.view': 'Продажи — просмотр',
    'sales.manage': 'Продажи — управление',
    'contracts.view': 'Договоры — просмотр',
    'contracts.manage': 'Договоры — управление',
    'users.view': 'Пользователи — просмотр',
    'users.manage': 'Пользователи — управление',
    'automation.manage': 'Автоматизации — управление',
    'analytics.view': 'Аналитика — просмотр',
    'settings.manage': 'Настройки системы — управление',
    'finance.view': 'Финансы — просмотр',
    'finance.entry': 'Финансы — ввод операций',
    'finance.posting': 'Финансы — проводки',
    'finance.journals.manual': 'Финансы — ручные журналы',
    'finance.payments.approve': 'Финансы — согласование платежей',
    'finance.period.close': 'Финансы — закрытие периода',
    'finance.settings.manage': 'Финансы — настройки',
    'finance.reports.management': 'Финансы — управленческие отчёты',
    'admin-write': 'Системные изменения (админ)',
    'dedup-scan-all': 'Дедупликация — полное сканирование',
    'view-manager-cabinet': 'Кабинет менеджера — доступ',
    'system-reset': 'Сброс системы'
  };
  /* default role → granted permissions */
  const A_DEFAULT_PERMS = {
    admin: A_GROUPS.flatMap(g => g.perms),
    director: ['crm.view', 'crm.manage', 'sales.view', 'sales.manage', 'contracts.view', 'contracts.manage', 'users.view', 'automation.manage', 'analytics.view', 'settings.manage', 'finance.view', 'view-manager-cabinet'],
    lawyer: ['crm.view', 'contracts.view', 'contracts.manage'],
    manager: ['crm.view', 'sales.view', 'sales.manage', 'contracts.view'],
    accountant: ['crm.view', 'finance.view', 'finance.entry', 'finance.posting', 'finance.journals.manual'],
    cfo: ['crm.view', 'analytics.view', 'finance.view', 'finance.entry', 'finance.posting', 'finance.payments.approve', 'finance.period.close', 'finance.settings.manage', 'finance.reports.management']
  };
  const A_DEFAULT_VIS = {
    admin: 'all',
    director: 'all',
    lawyer: 'department',
    manager: 'own',
    accountant: 'department',
    cfo: 'all'
  };
  const A_M = (id, full_name, email, role) => ({
    id,
    full_name,
    email,
    role
  });
  const A_TREE0 = [{
    id: 1,
    name: 'Руководство',
    manager: 'Директор Петров П.П.',
    members: [A_M(6, 'Директор Петров П.П.', 'director@mgcrm.test', 'director'), A_M(2, 'Bogdan Yadykin', 'b.yadykin@macroglobaltech.com', 'admin')],
    children: [{
      id: 2,
      name: 'Отдел продаж',
      manager: 'Иванов Алексей Сергеевич',
      members: [A_M(7, 'Иванов Алексей Сергеевич', 'manager1@mgcrm.test', 'manager'), A_M(8, 'Илья Рогов', 'ilyarogov.mera@gmail.com', 'manager'), A_M(9, 'Клим Федорин', 'k.fedorin@macroglobaltech.com', 'manager'), A_M(10, 'Олеся Моисеева', 'o.moiseeva@macroglobaltech.com', 'manager'), A_M(5, 'Георгий Некрасов', 'g.nekrasov@macroglobaltech.com', 'manager'), A_M(11, 'Петрова Мария Сергеевна', 'manager2@mgcrm.test', 'manager')],
      children: [{
        id: 5,
        name: 'Группа B2B',
        manager: 'Петрова Мария Сергеевна',
        members: [A_M(7, 'Иванов Алексей Сергеевич', 'manager1@mgcrm.test', 'manager'), A_M(9, 'Клим Федорин', 'k.fedorin@macroglobaltech.com', 'manager'), A_M(5, 'Георгий Некрасов', 'g.nekrasov@macroglobaltech.com', 'manager')],
        children: []
      }]
    }, {
      id: 3,
      name: 'Финансы',
      manager: 'Сергей Казначеев',
      members: [A_M(13, 'Сергей Казначеев', 'cfo@mgcrm.test', 'cfo')],
      children: [{
        id: 6,
        name: 'Бухгалтерия',
        manager: 'Анна Счётова',
        members: [A_M(12, 'Анна Счётова', 'accountant@mgcrm.test', 'accountant')],
        children: []
      }]
    }, {
      id: 4,
      name: 'Юридический отдел',
      manager: 'Lawyer Test',
      members: [A_M(3, 'Lawyer Test', 'lawyer@mgcrm.test', 'lawyer')],
      children: []
    }]
  }];
  const A_ALL_USERS = [A_M(1, 'Admin', 'svkv42@gmail.com', 'admin'), A_M(2, 'Bogdan Yadykin', 'b.yadykin@macroglobaltech.com', 'admin'), A_M(3, 'Lawyer Test', 'lawyer@mgcrm.test', 'lawyer'), A_M(5, 'Георгий Некрасов', 'g.nekrasov@macroglobaltech.com', 'manager'), A_M(6, 'Директор Петров П.П.', 'director@mgcrm.test', 'director'), A_M(7, 'Иванов Алексей Сергеевич', 'manager1@mgcrm.test', 'manager'), A_M(8, 'Илья Рогов', 'ilyarogov.mera@gmail.com', 'manager'), A_M(9, 'Клим Федорин', 'k.fedorin@macroglobaltech.com', 'manager'), A_M(10, 'Олеся Моисеева', 'o.moiseeva@macroglobaltech.com', 'manager'), A_M(11, 'Петрова Мария Сергеевна', 'manager2@mgcrm.test', 'manager'), A_M(12, 'Анна Счётова', 'accountant@mgcrm.test', 'accountant'), A_M(13, 'Сергей Казначеев', 'cfo@mgcrm.test', 'cfo')];

  /* ── shared primitives ── */
  const A_SEV = {
    success: {
      bg: 'var(--mg-status-success-bg)',
      fg: 'var(--mg-status-success-text)'
    },
    danger: {
      bg: 'var(--mg-status-danger-bg)',
      fg: 'var(--mg-status-danger-text)'
    },
    warn: {
      bg: 'var(--mg-status-warning-bg)',
      fg: 'var(--mg-status-warning-text)'
    },
    info: {
      bg: 'var(--mg-status-info-bg)',
      fg: 'var(--mg-status-info-text)'
    },
    secondary: {
      bg: 'var(--c-muted2)',
      fg: 'var(--c-text2)'
    }
  };
  function A_btn(kind) {
    const base = {
      display: 'inline-flex',
      alignItems: 'center',
      gap: 7,
      height: 38,
      padding: '0 15px',
      borderRadius: 'var(--mg-radius-md)',
      fontFamily: 'var(--mg-font-sans)',
      fontSize: 13,
      fontWeight: 600,
      cursor: 'pointer',
      border: 'none',
      whiteSpace: 'nowrap'
    };
    if (kind === 'primary') return {
      ...base,
      background: 'var(--mg-primary-900)',
      color: '#fff'
    };
    if (kind === 'danger') return {
      ...base,
      background: 'var(--mg-status-danger-solid)',
      color: '#fff'
    };
    if (kind === 'text') return {
      ...base,
      background: 'transparent',
      color: 'var(--c-text2)'
    };
    return {
      ...base,
      background: 'transparent',
      color: 'var(--c-text2)',
      border: '1px solid var(--c-border2)'
    };
  }
  function A_sbtn(kind, active) {
    const b = A_btn(kind === 'p' ? 'primary' : 'ghost');
    return {
      ...b,
      height: 34,
      padding: '0 12px',
      fontSize: 12.5,
      ...(active ? {} : {})
    };
  }
  function A_Tag({
    value,
    severity
  }) {
    const c = A_SEV[severity] || A_SEV.secondary;
    return /*#__PURE__*/React.createElement("span", {
      style: {
        display: 'inline-flex',
        alignItems: 'center',
        fontSize: 11.5,
        fontWeight: 600,
        padding: '3px 9px',
        borderRadius: 6,
        background: c.bg,
        color: c.fg,
        whiteSpace: 'nowrap'
      }
    }, value);
  }
  function A_Msg({
    severity,
    children
  }) {
    const map = {
      warn: {
        bg: 'var(--mg-status-warning-bg)',
        bd: 'var(--mg-status-warning-border)',
        fg: 'var(--mg-status-warning-text)',
        ic: 'pi-exclamation-triangle'
      },
      secondary: {
        bg: 'var(--c-hover)',
        bd: 'var(--c-border)',
        fg: 'var(--c-text2)',
        ic: 'pi-info-circle'
      }
    };
    const c = map[severity] || map.secondary;
    return /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'flex-start',
        gap: 10,
        padding: '11px 14px',
        background: c.bg,
        border: '1px solid ' + c.bd,
        borderRadius: 'var(--mg-radius-md)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + c.ic,
      style: {
        fontSize: 14,
        color: c.fg,
        marginTop: 1,
        flexShrink: 0
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 13,
        color: c.fg,
        lineHeight: 1.5
      }
    }, children));
  }
  function A_Check({
    checked,
    disabled,
    onChange
  }) {
    return /*#__PURE__*/React.createElement("span", {
      onClick: () => !disabled && onChange(!checked),
      style: {
        display: 'inline-flex',
        width: 20,
        height: 20,
        borderRadius: 5,
        border: '1.5px solid ' + (checked ? 'var(--mg-primary-900)' : 'var(--c-border2)'),
        background: checked ? 'var(--mg-primary-900)' : 'var(--c-card)',
        alignItems: 'center',
        justifyContent: 'center',
        cursor: disabled ? 'not-allowed' : 'pointer',
        opacity: disabled ? 0.5 : 1
      }
    }, checked && /*#__PURE__*/React.createElement("i", {
      className: "pi pi-check",
      style: {
        fontSize: 11,
        color: '#fff',
        fontWeight: 700
      }
    }));
  }
  function A_Dropdown({
    value,
    onChange,
    options,
    placeholder,
    width,
    filter
  }) {
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const ref = useRef(null);
    useEffect(() => {
      const h = e => {
        if (ref.current && !ref.current.contains(e.target)) setOpen(false);
      };
      document.addEventListener('mousedown', h);
      return () => document.removeEventListener('mousedown', h);
    }, []);
    const sel = options.find(o => o.value === value);
    const list = filter && q ? options.filter(o => o.label.toLowerCase().includes(q.toLowerCase())) : options;
    return /*#__PURE__*/React.createElement("div", {
      ref: ref,
      style: {
        position: 'relative',
        width: width || '100%'
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: () => setOpen(!open),
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        height: 40,
        padding: '0 12px',
        border: '1px solid ' + (open ? 'var(--mg-input-focus-border)' : 'var(--mg-input-border)'),
        borderRadius: 'var(--mg-radius-md)',
        background: 'var(--c-card)',
        cursor: 'pointer',
        boxShadow: open ? 'var(--mg-focus-ring)' : 'none'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 14,
        color: sel ? 'var(--c-text)' : 'var(--c-muted)',
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, sel ? sel.label : placeholder), /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + (open ? 'pi-chevron-up' : 'pi-chevron-down'),
      style: {
        fontSize: 12,
        color: 'var(--c-muted)'
      }
    })), open && /*#__PURE__*/React.createElement("div", {
      style: {
        position: 'absolute',
        top: 44,
        insetInlineStart: 0,
        zIndex: 40,
        width: '100%',
        minWidth: 180,
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-md)',
        boxShadow: 'var(--mg-shadow-lg)',
        padding: 5,
        maxHeight: 260,
        overflowY: 'auto'
      }
    }, filter && /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 7,
        height: 34,
        padding: '0 10px',
        margin: '2px 2px 6px',
        border: '1px solid var(--c-border2)',
        borderRadius: 'var(--mg-radius-sm)',
        background: 'var(--c-page)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-search",
      style: {
        fontSize: 12,
        color: 'var(--c-muted)'
      }
    }), /*#__PURE__*/React.createElement("input", {
      autoFocus: true,
      value: q,
      onChange: e => setQ(e.target.value),
      placeholder: "\u041F\u043E\u0438\u0441\u043A\u2026",
      style: {
        flex: 1,
        border: 'none',
        outline: 'none',
        background: 'transparent',
        fontFamily: 'var(--mg-font-sans)',
        fontSize: 13,
        color: 'var(--c-text)'
      }
    })), list.map(o => {
      const on = o.value === value;
      return /*#__PURE__*/React.createElement("div", {
        key: String(o.value),
        onClick: () => {
          onChange(o.value);
          setOpen(false);
          setQ('');
        },
        style: {
          display: 'flex',
          alignItems: 'center',
          gap: 8,
          padding: '8px 10px',
          borderRadius: 7,
          cursor: 'pointer',
          fontSize: 13.5,
          fontWeight: on ? 600 : 500,
          color: on ? 'var(--mg-primary-900)' : 'var(--c-text)',
          background: on ? 'var(--mg-primary-100)' : 'transparent'
        }
      }, o.label, on && /*#__PURE__*/React.createElement("i", {
        className: "pi pi-check",
        style: {
          marginInlineStart: 'auto',
          fontSize: 12
        }
      }));
    })));
  }
  function A_avatar(name, role, size) {
    const grad = {
      admin: 'linear-gradient(135deg,#4C7DF0,#2B4987)',
      director: 'linear-gradient(135deg,#E6A46E,#C67E3C)',
      cfo: 'linear-gradient(135deg,#E6A46E,#C67E3C)',
      lawyer: 'linear-gradient(135deg,#78B0E0,#4A82BC)',
      manager: 'linear-gradient(135deg,#4FBF8F,#2E9469)',
      accountant: 'linear-gradient(135deg,#9AA3B2,#6C7688)'
    };
    const w = name.trim().split(/\s+/);
    const ini = (w.length >= 2 ? w[0][0] + w[1][0] : w[0].slice(0, 2)).toUpperCase();
    return /*#__PURE__*/React.createElement("span", {
      style: {
        width: size,
        height: size,
        borderRadius: '50%',
        flexShrink: 0,
        background: grad[role] || grad.accountant,
        color: '#fff',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        fontSize: size * 0.36,
        fontWeight: 600
      }
    }, ini);
  }

  /* ── tree helpers ── */
  function A_walkUpdate(nodes, id, fn) {
    return nodes.map(n => n.id === id ? fn(n) : {
      ...n,
      children: A_walkUpdate(n.children, id, fn)
    });
  }
  function A_flatten(nodes, depth, acc) {
    acc = acc || [];
    nodes.forEach(n => {
      acc.push({
        id: n.id,
        name: n.name,
        depth: depth || 0
      });
      A_flatten(n.children, (depth || 0) + 1, acc);
    });
    return acc;
  }
  function A_removeNode(nodes, id) {
    const kept = [];
    let orphans = [];
    nodes.forEach(n => {
      if (n.id === id) {
        orphans = orphans.concat(n.children);
      } else {
        const r = A_removeNode(n.children, id);
        n = {
          ...n,
          children: r.nodes
        };
        orphans = orphans.concat(r.orphans);
        kept.push(n);
      }
    });
    return {
      nodes: kept,
      orphans
    };
  }
  function A_countAll(n) {
    return 1;
  }

  /* ── Department tree (redesigned) ── */
  function A_TreeNode({
    node,
    depth,
    selectedId,
    onSelect,
    onEdit,
    onDelete,
    expanded,
    toggle,
    editing
  }) {
    const isOpen = expanded[node.id] !== false;
    const on = selectedId === node.id;
    return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("div", {
      className: "atnode",
      onClick: () => onSelect(node),
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        padding: '9px 12px',
        paddingInlineStart: 12 + depth * 22,
        borderRadius: 'var(--mg-radius-md)',
        cursor: 'pointer',
        background: on ? 'var(--mg-primary-100)' : 'transparent',
        transition: 'background .12s'
      }
    }, node.children.length > 0 ? /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + (isOpen ? 'pi-chevron-down' : 'pi-chevron-right'),
      onClick: e => {
        e.stopPropagation();
        toggle(node.id);
      },
      style: {
        fontSize: 11,
        color: 'var(--c-muted)',
        width: 14,
        cursor: 'pointer'
      }
    }) : /*#__PURE__*/React.createElement("span", {
      style: {
        width: 14
      }
    }), /*#__PURE__*/React.createElement("i", {
      className: "pi pi-building",
      style: {
        fontSize: 14,
        color: on ? 'var(--mg-primary-900)' : 'var(--c-muted)'
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 13.5,
        fontWeight: on ? 600 : 500,
        color: on ? 'var(--mg-primary-900)' : 'var(--c-text)',
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, node.name), /*#__PURE__*/React.createElement("span", {
      style: {
        display: 'inline-flex',
        alignItems: 'center',
        gap: 4,
        fontSize: 12,
        color: 'var(--c-muted)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-users",
      style: {
        fontSize: 11
      }
    }), node.members.length), editing && /*#__PURE__*/React.createElement("span", {
      className: "atacts",
      style: {
        display: 'inline-flex',
        gap: 2,
        opacity: 0,
        transition: 'opacity .12s'
      }
    }, /*#__PURE__*/React.createElement("button", {
      className: "uicon",
      onClick: e => {
        e.stopPropagation();
        onEdit(node);
      },
      title: "\u0420\u0435\u0434\u0430\u043A\u0442\u0438\u0440\u043E\u0432\u0430\u0442\u044C",
      style: {
        width: 28,
        height: 28,
        borderRadius: 7,
        border: 'none',
        background: 'transparent',
        cursor: 'pointer'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-pencil",
      style: {
        fontSize: 12,
        color: 'var(--c-text2)'
      }
    })), /*#__PURE__*/React.createElement("button", {
      className: "uicon",
      onClick: e => {
        e.stopPropagation();
        onDelete(node);
      },
      title: "\u0423\u0434\u0430\u043B\u0438\u0442\u044C",
      style: {
        width: 28,
        height: 28,
        borderRadius: 7,
        border: 'none',
        background: 'transparent',
        cursor: 'pointer'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-trash",
      style: {
        fontSize: 12,
        color: 'var(--mg-status-danger-text)'
      }
    })))), isOpen && node.children.map(c => /*#__PURE__*/React.createElement(A_TreeNode, {
      key: c.id,
      node: c,
      depth: depth + 1,
      selectedId: selectedId,
      onSelect: onSelect,
      onEdit: onEdit,
      onDelete: onDelete,
      expanded: expanded,
      toggle: toggle,
      editing: editing
    })));
  }

  /* ── Org chart (Схема) ── */
  function A_ChartNode({
    node
  }) {
    const [open, setOpen] = useState(false);
    return /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center'
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: () => setOpen(o => !o),
      style: {
        width: 210,
        background: 'var(--c-card)',
        border: '1px solid ' + (open ? 'var(--mg-primary-900)' : 'var(--c-border2)'),
        borderRadius: 'var(--mg-radius-md)',
        boxShadow: 'var(--mg-shadow-sm)',
        padding: '11px 14px',
        cursor: 'pointer',
        textAlign: 'center'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 13.5,
        fontWeight: 600,
        color: 'var(--c-text)'
      }
    }, node.name), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 12,
        color: 'var(--c-muted)',
        marginTop: 3
      }
    }, node.manager), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'inline-flex',
        alignItems: 'center',
        gap: 5,
        marginTop: 7,
        fontSize: 11.5,
        fontWeight: 600,
        padding: '2px 9px',
        borderRadius: 999,
        background: 'var(--mg-primary-100)',
        color: 'var(--mg-primary-900)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-users",
      style: {
        fontSize: 10
      }
    }), node.members.length, /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + (open ? 'pi-chevron-up' : 'pi-chevron-down'),
      style: {
        fontSize: 9,
        marginInlineStart: 1
      }
    })), open && /*#__PURE__*/React.createElement("div", {
      style: {
        marginTop: 11,
        paddingTop: 11,
        borderTop: '1px solid var(--c-border)',
        display: 'flex',
        flexDirection: 'column',
        gap: 8,
        textAlign: 'start'
      }
    }, node.members.length ? node.members.map(m => /*#__PURE__*/React.createElement("div", {
      key: m.id,
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8
      }
    }, A_avatar(m.full_name, m.role, 24), /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        minWidth: 0,
        fontSize: 12,
        fontWeight: 500,
        color: 'var(--c-text)',
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, m.full_name))) : /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 12,
        color: 'var(--c-muted)'
      }
    }, A_T.dep.noMembers))), node.children.length > 0 && /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("div", {
      style: {
        width: 1,
        height: 20,
        background: 'var(--c-border2)'
      }
    }), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 26,
        position: 'relative',
        paddingTop: 20,
        borderTop: node.children.length > 1 ? '1px solid var(--c-border2)' : 'none',
        alignItems: 'flex-start'
      }
    }, node.children.map(c => /*#__PURE__*/React.createElement("div", {
      key: c.id,
      style: {
        position: 'relative'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        position: 'absolute',
        top: -20,
        insetInlineStart: '50%',
        width: 1,
        height: 20,
        background: 'var(--c-border2)'
      }
    }), /*#__PURE__*/React.createElement(A_ChartNode, {
      node: c
    }))))));
  }

  /* ── Department side panel (drawer) ── */
  function A_SidePanel({
    mode,
    form,
    setForm,
    parentOptions,
    userOptions,
    onClose,
    onSave,
    onOpenPicker,
    onRemoveMember
  }) {
    const set = (k, v) => setForm(s => ({
      ...s,
      [k]: v
    }));
    return /*#__PURE__*/React.createElement("div", {
      onClick: onClose,
      style: {
        position: 'fixed',
        inset: 0,
        zIndex: 1300,
        background: 'rgba(9,16,32,0.45)',
        display: 'flex',
        justifyContent: 'flex-end'
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: e => e.stopPropagation(),
      style: {
        width: 440,
        maxWidth: '92vw',
        height: '100%',
        background: 'var(--c-card)',
        borderInlineStart: '1px solid var(--c-border)',
        boxShadow: 'var(--mg-shadow-lg)',
        display: 'flex',
        flexDirection: 'column'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '16px 20px',
        borderBottom: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 16,
        fontWeight: 600
      }
    }, mode === 'create' ? A_T.dep.add : A_T.dep.edit), /*#__PURE__*/React.createElement("i", {
      className: "pi pi-times",
      onClick: onClose,
      style: {
        fontSize: 14,
        color: 'var(--c-muted)',
        cursor: 'pointer'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        flex: 1,
        overflowY: 'auto',
        padding: 20,
        display: 'flex',
        flexDirection: 'column',
        gap: 16
      }
    }, /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("label", {
      style: A_lbl
    }, A_T.dep.name), /*#__PURE__*/React.createElement("input", {
      className: "inp",
      autoFocus: true,
      value: form.name,
      onChange: e => set('name', e.target.value),
      placeholder: A_T.dep.name,
      style: A_inp
    })), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("label", {
      style: A_lbl
    }, A_T.dep.parent), /*#__PURE__*/React.createElement(A_Dropdown, {
      value: form.parentId,
      onChange: v => set('parentId', v),
      options: [{
        value: null,
        label: A_T.dep.root
      }].concat(parentOptions),
      placeholder: A_T.dep.root
    })), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("label", {
      style: A_lbl
    }, A_T.dep.manager), /*#__PURE__*/React.createElement(A_Dropdown, {
      value: form.managerId,
      onChange: v => set('managerId', v),
      options: userOptions,
      placeholder: "\u2014",
      filter: true
    })), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        marginBottom: 8
      }
    }, /*#__PURE__*/React.createElement("label", {
      style: {
        ...A_lbl,
        margin: 0,
        flex: 1
      }
    }, A_T.dep.members), /*#__PURE__*/React.createElement("button", {
      style: {
        ...A_btn('text'),
        height: 30,
        padding: '0 8px',
        fontSize: 12.5,
        color: 'var(--mg-primary-900)'
      },
      onClick: onOpenPicker
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-plus",
      style: {
        fontSize: 11
      }
    }), A_T.dep.addMember)), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        gap: 6
      }
    }, form.members.length === 0 && /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 13,
        color: 'var(--c-muted)',
        padding: '10px 0'
      }
    }, A_T.dep.noMembers), form.members.map(m => /*#__PURE__*/React.createElement("div", {
      key: m.id,
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '7px 10px',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-md)'
      }
    }, A_avatar(m.full_name, m.role, 28), /*#__PURE__*/React.createElement("div", {
      style: {
        flex: 1,
        minWidth: 0
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 13,
        fontWeight: 500,
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, m.full_name)), /*#__PURE__*/React.createElement("i", {
      className: "pi pi-times",
      onClick: () => onRemoveMember(m.id),
      title: A_T.dep.removeMember,
      style: {
        fontSize: 12,
        color: 'var(--c-muted)',
        cursor: 'pointer'
      }
    })))))), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        justifyContent: 'flex-end',
        gap: 10,
        padding: '14px 20px',
        borderTop: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("button", {
      style: A_btn('ghost'),
      onClick: onClose
    }, "\u041E\u0442\u043C\u0435\u043D\u0430"), /*#__PURE__*/React.createElement("button", {
      style: A_btn('primary'),
      onClick: onSave
    }, "\u0421\u043E\u0445\u0440\u0430\u043D\u0438\u0442\u044C"))));
  }
  const A_lbl = {
    display: 'block',
    fontSize: 13,
    fontWeight: 500,
    color: 'var(--c-text)',
    marginBottom: 6
  };
  const A_inp = {
    width: '100%',
    height: 40,
    padding: '0 13px',
    borderRadius: 'var(--mg-radius-md)',
    border: '1px solid var(--mg-input-border)',
    background: 'var(--c-card)',
    color: 'var(--c-text)',
    fontFamily: 'var(--mg-font-sans)',
    fontSize: 14,
    boxSizing: 'border-box'
  };

  /* ── member picker dialog ── */
  function A_MemberPicker({
    available,
    close,
    onAdd
  }) {
    const [sel, setSel] = useState([]);
    const [q, setQ] = useState('');
    const list = available.filter(u => u.full_name.toLowerCase().includes(q.toLowerCase()));
    const toggle = id => setSel(s => s.includes(id) ? s.filter(x => x !== id) : [...s, id]);
    return /*#__PURE__*/React.createElement("div", {
      onClick: close,
      style: {
        position: 'fixed',
        inset: 0,
        zIndex: 1400,
        background: 'rgba(9,16,32,0.55)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: 20
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: e => e.stopPropagation(),
      style: {
        width: 440,
        maxWidth: '92vw',
        maxHeight: 'calc(100vh - 40px)',
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-lg)',
        display: 'flex',
        flexDirection: 'column'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '16px 20px',
        borderBottom: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 16,
        fontWeight: 600
      }
    }, A_T.dep.addMember), /*#__PURE__*/React.createElement("i", {
      className: "pi pi-times",
      onClick: close,
      style: {
        fontSize: 14,
        color: 'var(--c-muted)',
        cursor: 'pointer'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        padding: '14px 20px'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        height: 40,
        padding: '0 12px',
        border: '1px solid var(--mg-input-border)',
        borderRadius: 'var(--mg-radius-md)',
        background: 'var(--c-page)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-search",
      style: {
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }), /*#__PURE__*/React.createElement("input", {
      autoFocus: true,
      value: q,
      onChange: e => setQ(e.target.value),
      placeholder: "\u041F\u043E\u0438\u0441\u043A\u2026",
      style: {
        flex: 1,
        border: 'none',
        outline: 'none',
        background: 'transparent',
        fontFamily: 'var(--mg-font-sans)',
        fontSize: 14,
        color: 'var(--c-text)'
      }
    }))), /*#__PURE__*/React.createElement("div", {
      style: {
        flex: 1,
        overflowY: 'auto',
        padding: '0 12px 8px'
      }
    }, list.map(u => {
      const on = sel.includes(u.id);
      return /*#__PURE__*/React.createElement("div", {
        key: u.id,
        className: "urow",
        onClick: () => toggle(u.id),
        style: {
          display: 'flex',
          alignItems: 'center',
          gap: 11,
          padding: '8px 10px',
          borderRadius: 8,
          cursor: 'pointer'
        }
      }, /*#__PURE__*/React.createElement(A_Check, {
        checked: on,
        onChange: () => toggle(u.id)
      }), A_avatar(u.full_name, u.role, 30), /*#__PURE__*/React.createElement("div", {
        style: {
          flex: 1,
          minWidth: 0
        }
      }, /*#__PURE__*/React.createElement("div", {
        style: {
          fontSize: 13.5,
          fontWeight: 500
        }
      }, u.full_name), /*#__PURE__*/React.createElement("div", {
        style: {
          fontSize: 12,
          color: 'var(--c-muted)'
        }
      }, u.email)));
    }), list.length === 0 && /*#__PURE__*/React.createElement("div", {
      style: {
        padding: '20px 10px',
        fontSize: 13,
        color: 'var(--c-muted)',
        textAlign: 'center'
      }
    }, "\u041D\u0438\u0447\u0435\u0433\u043E \u043D\u0435 \u043D\u0430\u0439\u0434\u0435\u043D\u043E")), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        justifyContent: 'flex-end',
        gap: 10,
        padding: '14px 20px',
        borderTop: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("button", {
      style: A_btn('ghost'),
      onClick: close
    }, "\u041E\u0442\u043C\u0435\u043D\u0430"), /*#__PURE__*/React.createElement("button", {
      style: {
        ...A_btn('primary'),
        opacity: sel.length ? 1 : 0.5,
        pointerEvents: sel.length ? 'auto' : 'none'
      },
      onClick: () => onAdd(sel)
    }, "\u0414\u043E\u0431\u0430\u0432\u0438\u0442\u044C"))));
  }

  /* ── confirm delete ── */
  function A_Confirm({
    message,
    onAccept,
    close
  }) {
    return /*#__PURE__*/React.createElement("div", {
      onClick: close,
      style: {
        position: 'fixed',
        inset: 0,
        zIndex: 1500,
        background: 'rgba(9,16,32,0.55)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: 20
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: e => e.stopPropagation(),
      style: {
        width: 430,
        maxWidth: '92vw',
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-lg)'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '16px 20px',
        borderBottom: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 16,
        fontWeight: 600
      }
    }, A_T.dep.del), /*#__PURE__*/React.createElement("i", {
      className: "pi pi-times",
      onClick: close,
      style: {
        fontSize: 14,
        color: 'var(--c-muted)',
        cursor: 'pointer'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        padding: 20,
        display: 'flex',
        gap: 14,
        alignItems: 'flex-start'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        width: 40,
        height: 40,
        borderRadius: '50%',
        background: 'var(--mg-status-danger-bg)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        flexShrink: 0
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-exclamation-triangle",
      style: {
        fontSize: 17,
        color: 'var(--mg-status-danger-text)'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 14,
        color: 'var(--c-text2)',
        lineHeight: 1.55,
        paddingTop: 3
      }
    }, message)), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        justifyContent: 'flex-end',
        gap: 10,
        padding: '14px 20px',
        borderTop: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("button", {
      style: A_btn('ghost'),
      onClick: close
    }, "\u041E\u0442\u043C\u0435\u043D\u0430"), /*#__PURE__*/React.createElement("button", {
      style: A_btn('danger'),
      onClick: onAccept
    }, "\u0423\u0434\u0430\u043B\u0438\u0442\u044C"))));
  }
  function A_Toasts({
    items
  }) {
    return /*#__PURE__*/React.createElement("div", {
      style: {
        position: 'fixed',
        insetBlockEnd: 20,
        insetInlineEnd: 20,
        zIndex: 2000,
        display: 'flex',
        flexDirection: 'column',
        gap: 10
      }
    }, items.map(t => /*#__PURE__*/React.createElement("div", {
      key: t.id,
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        minWidth: 240,
        maxWidth: 360,
        padding: '12px 14px',
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderInlineStart: '3px solid var(--mg-status-success-text)',
        borderRadius: 'var(--mg-radius-md)',
        boxShadow: 'var(--mg-shadow-lg)',
        animation: 'toastIn .2s ease'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-check-circle",
      style: {
        fontSize: 16,
        color: 'var(--mg-status-success-text)'
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 13.5,
        fontWeight: 500
      }
    }, t.summary))));
  }

  /* ═══════════════ TABS ═══════════════ */
  function A_DepartmentsTab({
    pushToast
  }) {
    const [tree, setTree] = useState(A_TREE0);
    const [search, setSearch] = useState('');
    const [viewMode, setViewMode] = useState('tree');
    const [selectedId, setSelectedId] = useState(2);
    const [expanded, setExpanded] = useState({});
    const [panel, setPanel] = useState(null);
    const [form, setForm] = useState({
      name: '',
      parentId: null,
      managerId: null,
      members: []
    });
    const [picker, setPicker] = useState(false);
    const [confirm, setConfirm] = useState(null);
    const [editing, setEditing] = useState(false);
    const toggle = id => setExpanded(e => ({
      ...e,
      [id]: e[id] === false ? true : false
    }));
    const findNode = (nodes, id) => {
      for (const n of nodes) {
        if (n.id === id) return n;
        const f = findNode(n.children, id);
        if (f) return f;
      }
      return null;
    };
    const selected = findNode(tree, selectedId);
    const parentOptions = A_flatten(tree).map(d => ({
      value: d.id,
      label: '— '.repeat(d.depth) + d.name
    }));
    const userOptions = A_ALL_USERS.map(u => ({
      value: u.id,
      label: u.full_name
    }));
    function openCreate() {
      setForm({
        name: '',
        parentId: selectedId || null,
        managerId: null,
        members: []
      });
      setPanel('create');
    }
    function openEdit(n) {
      setForm({
        id: n.id,
        name: n.name,
        parentId: null,
        managerId: (A_ALL_USERS.find(u => u.full_name === n.manager) || {}).id ?? null,
        members: n.members.slice()
      });
      setPanel('edit');
    }
    function savePanel() {
      if (!form.name.trim()) return;
      const mgr = (A_ALL_USERS.find(u => u.id === form.managerId) || {}).full_name || '—';
      if (panel === 'edit') {
        setTree(t => A_walkUpdate(t, form.id, n => ({
          ...n,
          name: form.name.trim(),
          manager: mgr,
          members: form.members
        })));
      } else {
        const id = Date.now();
        const node = {
          id,
          name: form.name.trim(),
          manager: mgr,
          members: form.members,
          children: []
        };
        setTree(t => form.parentId ? A_walkUpdate(t, form.parentId, n => ({
          ...n,
          children: [...n.children, node]
        })) : [...t, node]);
        if (form.parentId) setExpanded(e => ({
          ...e,
          [form.parentId]: false
        }));
      }
      pushToast(A_T.dep.saved);
      setPanel(null);
    }
    function doDelete(n) {
      setTree(t => A_removeNode(t, n.id).nodes);
      if (selectedId === n.id) setSelectedId(null);
      pushToast(A_T.dep.deleted);
      setConfirm(null);
    }
    function addMembers(ids) {
      const add = A_ALL_USERS.filter(u => ids.includes(u.id) && !form.members.some(m => m.id === u.id));
      setForm(f => ({
        ...f,
        members: [...f.members, ...add]
      }));
      setPicker(false);
    }
    function removeMember(id) {
      setForm(f => ({
        ...f,
        members: f.members.filter(m => m.id !== id)
      }));
    }
    const q = search.trim().toLowerCase();
    const filterTree = nodes => nodes.map(n => ({
      ...n,
      children: filterTree(n.children)
    })).filter(n => !q || n.name.toLowerCase().includes(q) || n.children.length);
    const shown = filterTree(tree);
    return /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        gap: 16
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        flexWrap: 'wrap'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        height: 40,
        padding: '0 12px',
        flex: 1,
        minWidth: 180,
        maxWidth: 280,
        border: '1px solid var(--mg-input-border)',
        borderRadius: 'var(--mg-radius-md)',
        background: 'var(--c-card)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-search",
      style: {
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }), /*#__PURE__*/React.createElement("input", {
      value: search,
      onChange: e => setSearch(e.target.value),
      placeholder: "\u041F\u043E\u0438\u0441\u043A\u2026",
      style: {
        flex: 1,
        border: 'none',
        outline: 'none',
        background: 'transparent',
        fontFamily: 'var(--mg-font-sans)',
        fontSize: 14,
        color: 'var(--c-text)'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'inline-flex',
        padding: 3,
        gap: 2,
        background: 'var(--c-muted2)',
        borderRadius: 'var(--mg-radius-md)'
      }
    }, [['tree', A_T.dep.viewTree, 'pi-sitemap'], ['chart', A_T.dep.viewChart, 'pi-share-alt']].map(([k, l, ic]) => /*#__PURE__*/React.createElement("button", {
      key: k,
      onClick: () => setViewMode(k),
      style: {
        display: 'inline-flex',
        alignItems: 'center',
        gap: 6,
        height: 32,
        padding: '0 12px',
        border: 'none',
        borderRadius: 'var(--mg-radius-sm)',
        cursor: 'pointer',
        fontFamily: 'var(--mg-font-sans)',
        fontSize: 12.5,
        fontWeight: 600,
        background: viewMode === k ? 'var(--c-card)' : 'transparent',
        color: viewMode === k ? 'var(--mg-primary-900)' : 'var(--c-text2)',
        boxShadow: viewMode === k ? 'var(--mg-shadow-sm)' : 'none'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + ic,
      style: {
        fontSize: 12
      }
    }), l))), /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1
      }
    }), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 10
      }
    }, /*#__PURE__*/React.createElement("button", {
      style: editing ? {
        ...A_btn('ghost'),
        color: 'var(--mg-primary-900)',
        borderColor: 'var(--mg-primary-900)'
      } : A_btn('ghost'),
      onClick: () => setEditing(e => !e)
    }, /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + (editing ? 'pi-check' : 'pi-pencil'),
      style: {
        fontSize: 12
      }
    }), editing ? 'Завершить редактирование' : 'Редактировать'), /*#__PURE__*/React.createElement("button", {
      style: A_btn('primary'),
      onClick: openCreate
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-plus",
      style: {
        fontSize: 12
      }
    }), A_T.dep.add))), viewMode === 'tree' ? /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'grid',
        gridTemplateColumns: '1fr 340px',
        gap: 16,
        alignItems: 'start'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-sm)',
        padding: 8
      }
    }, shown.length ? shown.map(n => /*#__PURE__*/React.createElement(A_TreeNode, {
      key: n.id,
      node: n,
      depth: 0,
      selectedId: selectedId,
      onSelect: d => setSelectedId(d.id),
      onEdit: openEdit,
      onDelete: d => setConfirm(d),
      expanded: expanded,
      toggle: toggle,
      editing: editing
    })) : /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        gap: 10,
        padding: '48px 20px',
        color: 'var(--c-muted)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-building",
      style: {
        fontSize: 28,
        opacity: 0.3
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 14,
        color: 'var(--c-text2)',
        fontWeight: 500
      }
    }, A_T.dep.empty), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 13,
        textAlign: 'center',
        maxWidth: 240
      }
    }, A_T.dep.emptyHint), /*#__PURE__*/React.createElement("button", {
      style: {
        ...A_btn('ghost'),
        marginTop: 4
      },
      onClick: openCreate
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-plus",
      style: {
        fontSize: 12
      }
    }), A_T.dep.add))), selected ? /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-sm)',
        overflow: 'hidden'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '14px 16px',
        borderBottom: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        flex: 1,
        minWidth: 0
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 15,
        fontWeight: 600,
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, selected.name), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 12,
        color: 'var(--c-muted)',
        marginTop: 2
      }
    }, "\u0420\u0443\u043A\u043E\u0432\u043E\u0434\u0438\u0442\u0435\u043B\u044C: ", selected.manager)), editing && /*#__PURE__*/React.createElement("button", {
      className: "uicon",
      onClick: () => openEdit(selected),
      title: "\u0420\u0435\u0434\u0430\u043A\u0442\u0438\u0440\u043E\u0432\u0430\u0442\u044C",
      style: {
        width: 30,
        height: 30,
        borderRadius: 7,
        border: 'none',
        background: 'transparent',
        cursor: 'pointer'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-pencil",
      style: {
        fontSize: 13,
        color: 'var(--c-text2)'
      }
    }))), /*#__PURE__*/React.createElement("div", {
      style: {
        padding: 4
      }
    }, selected.members.length ? selected.members.map(m => /*#__PURE__*/React.createElement("div", {
      key: m.id,
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '8px 12px'
      }
    }, A_avatar(m.full_name, m.role, 30), /*#__PURE__*/React.createElement("div", {
      style: {
        flex: 1,
        minWidth: 0
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 13,
        fontWeight: 500,
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, m.full_name), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 11.5,
        color: 'var(--c-muted)',
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, m.email)), /*#__PURE__*/React.createElement(A_Tag, {
      value: A_ROLE_LABEL[m.role],
      severity: A_ROLE_SEV[m.role]
    }))) : /*#__PURE__*/React.createElement("div", {
      style: {
        padding: '28px 12px',
        fontSize: 13,
        color: 'var(--c-muted)',
        textAlign: 'center'
      }
    }, A_T.dep.noMembers))) : /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px dashed var(--c-border2)',
        borderRadius: 'var(--mg-radius-lg)',
        padding: '40px 20px',
        textAlign: 'center',
        color: 'var(--c-muted)',
        fontSize: 13
      }
    }, "\u0412\u044B\u0431\u0435\u0440\u0438\u0442\u0435 \u043E\u0442\u0434\u0435\u043B, \u0447\u0442\u043E\u0431\u044B \u0443\u0432\u0438\u0434\u0435\u0442\u044C \u0441\u043E\u0442\u0440\u0443\u0434\u043D\u0438\u043A\u043E\u0432")) : /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-page)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        padding: '32px 20px',
        overflowX: 'auto'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 40,
        justifyContent: 'center',
        minWidth: 'min-content',
        alignItems: 'flex-start'
      }
    }, shown.map(n => /*#__PURE__*/React.createElement(A_ChartNode, {
      key: n.id,
      node: n
    })))), panel && /*#__PURE__*/React.createElement(A_SidePanel, {
      mode: panel,
      form: form,
      setForm: setForm,
      parentOptions: parentOptions.filter(o => o.value !== form.id),
      userOptions: userOptions,
      onClose: () => setPanel(null),
      onSave: savePanel,
      onOpenPicker: () => setPicker(true),
      onRemoveMember: removeMember
    }), picker && /*#__PURE__*/React.createElement(A_MemberPicker, {
      available: A_ALL_USERS.filter(u => !form.members.some(m => m.id === u.id)),
      close: () => setPicker(false),
      onAdd: addMembers
    }), confirm && /*#__PURE__*/React.createElement(A_Confirm, {
      message: A_T.dep.delConfirm(confirm.name),
      onAccept: () => doDelete(confirm),
      close: () => setConfirm(null)
    }));
  }
  function A_RolesTab({
    pushToast
  }) {
    const [perms, setPerms] = useState(() => {
      const m = {};
      A_ROLES.forEach(r => {
        m[r] = new Set(A_DEFAULT_PERMS[r]);
      });
      return m;
    });
    const [collapsed, setCollapsed] = useState({});
    const [dirty, setDirty] = useState(false);
    const has = (r, p) => perms[r].has(p);
    function toggle(p, r, v) {
      if (r === 'admin') return;
      setPerms(m => {
        const s = new Set(m[r]);
        v ? s.add(p) : s.delete(p);
        return {
          ...m,
          [r]: s
        };
      });
      setDirty(true);
    }
    function reset() {
      const m = {};
      A_ROLES.forEach(r => {
        m[r] = new Set(A_DEFAULT_PERMS[r]);
      });
      setPerms(m);
      setDirty(false);
    }
    function save() {
      pushToast(A_T.roles.saved);
      setDirty(false);
    }
    const th = {
      padding: '9px 12px',
      fontSize: 11,
      fontWeight: 600,
      letterSpacing: '.02em',
      textTransform: 'uppercase',
      color: 'var(--c-muted)',
      borderBottom: '1px solid var(--c-border)'
    };
    return /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        gap: 16
      }
    }, /*#__PURE__*/React.createElement(A_Msg, {
      severity: "secondary"
    }, A_T.roles.adminNote), A_GROUPS.map(g => {
      const open = !collapsed[g.key];
      return /*#__PURE__*/React.createElement("div", {
        key: g.key,
        style: {
          background: 'var(--c-card)',
          border: '1px solid var(--c-border)',
          borderRadius: 'var(--mg-radius-lg)',
          boxShadow: 'var(--mg-shadow-sm)',
          overflow: 'hidden'
        }
      }, /*#__PURE__*/React.createElement("div", {
        onClick: () => setCollapsed(c => ({
          ...c,
          [g.key]: !c[g.key]
        })),
        style: {
          display: 'flex',
          alignItems: 'center',
          gap: 10,
          padding: '12px 16px',
          cursor: 'pointer',
          background: 'var(--c-hover)',
          borderBottom: open ? '1px solid var(--c-border)' : 'none'
        }
      }, /*#__PURE__*/React.createElement("i", {
        className: 'pi ' + (open ? 'pi-chevron-down' : 'pi-chevron-right'),
        style: {
          fontSize: 12,
          color: 'var(--c-muted)'
        }
      }), /*#__PURE__*/React.createElement("span", {
        style: {
          flex: 1,
          fontSize: 14,
          fontWeight: 600
        }
      }, g.label), /*#__PURE__*/React.createElement("span", {
        style: {
          fontSize: 11.5,
          fontWeight: 600,
          padding: '2px 8px',
          borderRadius: 999,
          background: 'var(--c-muted2)',
          color: 'var(--c-text2)'
        }
      }, g.perms.length)), open && /*#__PURE__*/React.createElement("div", {
        style: {
          overflowX: 'auto'
        }
      }, /*#__PURE__*/React.createElement("table", {
        style: {
          width: '100%',
          borderCollapse: 'collapse',
          minWidth: 760
        }
      }, /*#__PURE__*/React.createElement("thead", null, /*#__PURE__*/React.createElement("tr", null, /*#__PURE__*/React.createElement("th", {
        style: {
          ...th,
          textAlign: 'start',
          minWidth: 260
        }
      }, A_T.roles.permissionLabel), A_ROLES.map(r => /*#__PURE__*/React.createElement("th", {
        key: r,
        style: {
          ...th,
          textAlign: 'center',
          width: 92
        }
      }, A_ROLE_SHORT[r])))), /*#__PURE__*/React.createElement("tbody", null, g.perms.map(p => /*#__PURE__*/React.createElement("tr", {
        key: p,
        className: "urow"
      }, /*#__PURE__*/React.createElement("td", {
        style: {
          padding: '10px 12px',
          borderBottom: '1px solid var(--c-border)'
        }
      }, /*#__PURE__*/React.createElement("div", {
        style: {
          display: 'flex',
          flexDirection: 'column',
          gap: 3
        }
      }, /*#__PURE__*/React.createElement("span", {
        style: {
          fontSize: 13.5,
          color: 'var(--c-text)'
        }
      }, A_PERM_LABEL[p] || p), /*#__PURE__*/React.createElement("code", {
        style: {
          fontFamily: 'ui-monospace,monospace',
          fontSize: 11,
          color: 'var(--c-muted)',
          background: 'var(--c-muted2)',
          padding: '1px 6px',
          borderRadius: 4,
          alignSelf: 'flex-start'
        }
      }, p))), A_ROLES.map(r => /*#__PURE__*/React.createElement("td", {
        key: r,
        style: {
          padding: '10px 12px',
          borderBottom: '1px solid var(--c-border)',
          textAlign: 'center'
        }
      }, /*#__PURE__*/React.createElement("div", {
        style: {
          display: 'flex',
          justifyContent: 'center'
        }
      }, /*#__PURE__*/React.createElement(A_Check, {
        checked: r === 'admin' ? true : has(r, p),
        disabled: r === 'admin',
        onChange: v => toggle(p, r, v)
      }))))))))));
    }), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        justifyContent: 'flex-end',
        gap: 10,
        paddingTop: 2
      }
    }, /*#__PURE__*/React.createElement("button", {
      style: {
        ...A_btn('ghost'),
        opacity: dirty ? 1 : 0.5,
        pointerEvents: dirty ? 'auto' : 'none'
      },
      onClick: reset
    }, A_T.roles.reset), /*#__PURE__*/React.createElement("button", {
      style: {
        ...A_btn('primary'),
        opacity: dirty ? 1 : 0.5,
        pointerEvents: dirty ? 'auto' : 'none'
      },
      onClick: save
    }, A_T.roles.save)));
  }
  function A_VisibilityTab({
    pushToast
  }) {
    const [vis, setVis] = useState(() => ({
      ...A_DEFAULT_VIS
    }));
    const [dirty, setDirty] = useState(false);
    const opts = [{
      value: 'all',
      label: A_T.vis.scopeAll
    }, {
      value: 'department',
      label: A_T.vis.scopeDepartment
    }, {
      value: 'own',
      label: A_T.vis.scopeOwn
    }];
    function setScope(r, v) {
      setVis(m => ({
        ...m,
        [r]: v
      }));
      setDirty(true);
    }
    function reset() {
      setVis({
        ...A_DEFAULT_VIS
      });
      setDirty(false);
    }
    function save() {
      pushToast(A_T.vis.saved);
      setDirty(false);
    }
    const th = {
      padding: '11px 16px',
      fontSize: 11.5,
      fontWeight: 600,
      letterSpacing: '.03em',
      textTransform: 'uppercase',
      color: 'var(--c-muted)',
      textAlign: 'start',
      borderBottom: '1px solid var(--c-border)'
    };
    return /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        gap: 16,
        maxWidth: 640
      }
    }, /*#__PURE__*/React.createElement(A_Msg, {
      severity: "warn"
    }, A_T.vis.warning), /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-sm)',
        overflow: 'hidden'
      }
    }, /*#__PURE__*/React.createElement("table", {
      style: {
        width: '100%',
        borderCollapse: 'collapse'
      }
    }, /*#__PURE__*/React.createElement("thead", null, /*#__PURE__*/React.createElement("tr", null, /*#__PURE__*/React.createElement("th", {
      style: {
        ...th,
        width: 200
      }
    }, A_T.vis.roleColumn), /*#__PURE__*/React.createElement("th", {
      style: th
    }, A_T.vis.scopeColumn))), /*#__PURE__*/React.createElement("tbody", null, A_ROLES.map(r => /*#__PURE__*/React.createElement("tr", {
      key: r,
      className: "urow"
    }, /*#__PURE__*/React.createElement("td", {
      style: {
        padding: '12px 16px',
        borderBottom: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement(A_Tag, {
      value: A_ROLE_LABEL[r],
      severity: A_ROLE_SEV[r]
    })), /*#__PURE__*/React.createElement("td", {
      style: {
        padding: '10px 16px',
        borderBottom: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement(A_Dropdown, {
      value: vis[r],
      onChange: v => setScope(r, v),
      options: opts,
      width: 240
    }))))))), /*#__PURE__*/React.createElement(A_Msg, {
      severity: "secondary"
    }, A_T.vis.departmentHint), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        justifyContent: 'flex-end',
        gap: 10
      }
    }, /*#__PURE__*/React.createElement("button", {
      style: {
        ...A_btn('ghost'),
        opacity: dirty ? 1 : 0.5,
        pointerEvents: dirty ? 'auto' : 'none'
      },
      onClick: reset
    }, A_T.vis.reset), /*#__PURE__*/React.createElement("button", {
      style: {
        ...A_btn('primary'),
        opacity: dirty ? 1 : 0.5,
        pointerEvents: dirty ? 'auto' : 'none'
      },
      onClick: save
    }, A_T.vis.save)));
  }
  function AccessTab() {
    const [tab, setTab] = useState('departments');
    const [toasts, setToasts] = useState([]);
    const pushToast = summary => {
      const id = Date.now() + Math.random();
      setToasts(t => [...t, {
        id,
        summary
      }]);
      setTimeout(() => setToasts(t => t.filter(x => x.id !== id)), 2600);
    };
    const TABS = [['departments', A_T.tabs.departments], ['roles', A_T.tabs.roles], ['visibility', A_T.tabs.visibility]];
    return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("div", {
      style: {
        marginBottom: 18
      }
    }, /*#__PURE__*/React.createElement("h2", {
      style: {
        margin: 0,
        fontSize: 20,
        fontWeight: 600
      }
    }, A_T.title), /*#__PURE__*/React.createElement("p", {
      style: {
        margin: '5px 0 0',
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }, A_T.subtitle)), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 4,
        borderBottom: '1px solid var(--c-border)',
        marginBottom: 20
      }
    }, TABS.map(([k, l]) => {
      const on = tab === k;
      return /*#__PURE__*/React.createElement("button", {
        key: k,
        onClick: () => setTab(k),
        style: {
          background: 'transparent',
          border: 'none',
          cursor: 'pointer',
          padding: '10px 14px',
          marginBottom: -1,
          whiteSpace: 'nowrap',
          fontFamily: 'var(--mg-font-sans)',
          fontSize: 14,
          fontWeight: on ? 600 : 500,
          color: on ? 'var(--mg-primary-900)' : 'var(--c-muted)',
          borderBottom: '2px solid ' + (on ? 'var(--mg-primary-900)' : 'transparent')
        }
      }, l);
    })), tab === 'departments' && /*#__PURE__*/React.createElement(A_DepartmentsTab, {
      pushToast: pushToast
    }), tab === 'roles' && /*#__PURE__*/React.createElement(A_RolesTab, {
      pushToast: pushToast
    }), tab === 'visibility' && /*#__PURE__*/React.createElement(A_VisibilityTab, {
      pushToast: pushToast
    }), /*#__PURE__*/React.createElement(A_Toasts, {
      items: toasts
    }));
  }
  window.AccessTab = AccessTab;
})();
})(); } catch (e) { __ds_ns.__errors.push({ path: "redesign/handoff_settings_redesign/design/access-section.jsx", error: String((e && e.message) || e) }); }

// redesign/handoff_settings_redesign/design/system-section.jsx
try { (() => {
/* System sections for Настройки → Система:
   - Журнал автоматизаций (AutomationsTab): read-only execution log of automation rules.
   - Сброс системы (ResetTab): selective data wipe — checkbox choice of which datasets to delete
     (unlike the product's full-wipe behaviour, per request).
   Built to match the fresh MACRO CRM look (shared --c-* / --mg-* tokens, table th/td, tags).
   Exposes window.AutomationsTab and window.ResetTab. */
(function () {
  const {
    useState
  } = React;

  /* ── shared button/tag helpers (mirror settings.html btn()) ── */
  function sBtn(kind) {
    const base = {
      display: 'inline-flex',
      alignItems: 'center',
      gap: 7,
      height: 38,
      padding: '0 15px',
      borderRadius: 'var(--mg-radius-md)',
      fontFamily: 'var(--mg-font-sans)',
      fontSize: 13,
      fontWeight: 600,
      cursor: 'pointer',
      border: 'none',
      whiteSpace: 'nowrap'
    };
    if (kind === 'primary') return {
      ...base,
      background: 'var(--mg-primary-900)',
      color: '#fff'
    };
    if (kind === 'danger') return {
      ...base,
      background: 'var(--mg-status-danger-solid)',
      color: '#fff'
    };
    return {
      ...base,
      background: 'transparent',
      color: 'var(--c-text2)',
      border: '1px solid var(--c-border2)'
    };
  }
  const sTh = {
    padding: '11px 16px',
    fontSize: 12,
    fontWeight: 600,
    color: 'var(--c-text2)',
    textAlign: 'start',
    borderBottom: '1px solid var(--c-border)',
    whiteSpace: 'nowrap',
    background: 'var(--c-card)'
  };
  const sTd = {
    padding: '12px 16px',
    fontSize: 13.5,
    color: 'var(--c-text)',
    borderBottom: '1px solid var(--c-border)',
    verticalAlign: 'middle'
  };
  function SCheck({
    checked,
    onChange,
    disabled,
    indeterminate
  }) {
    const active = checked || indeterminate;
    return /*#__PURE__*/React.createElement("span", {
      onClick: disabled ? undefined : e => {
        e.stopPropagation();
        onChange();
      },
      role: "checkbox",
      "aria-checked": checked,
      style: {
        width: 20,
        height: 20,
        borderRadius: 6,
        border: '1.5px solid ' + (active ? 'var(--mg-primary-900)' : 'var(--c-border2)'),
        background: active ? 'var(--mg-primary-900)' : 'var(--c-card)',
        display: 'inline-flex',
        alignItems: 'center',
        justifyContent: 'center',
        cursor: disabled ? 'not-allowed' : 'pointer',
        flexShrink: 0,
        opacity: disabled ? 0.45 : 1,
        transition: 'background .12s, border-color .12s'
      }
    }, checked ? /*#__PURE__*/React.createElement("i", {
      className: "pi pi-check",
      style: {
        fontSize: 11,
        color: '#fff'
      }
    }) : indeterminate ? /*#__PURE__*/React.createElement("span", {
      style: {
        width: 9,
        height: 2,
        borderRadius: 1,
        background: '#fff'
      }
    }) : null);
  }

  /* ═══════════════ Журнал автоматизаций ═══════════════ */
  const S_TRIG = {
    created: {
      label: 'Сделка создана',
      ic: 'pi-plus-circle'
    },
    stage: {
      label: 'Этап изменён',
      ic: 'pi-sort-alt'
    },
    overdue: {
      label: 'Задача просрочена',
      ic: 'pi-exclamation-circle'
    },
    webform: {
      label: 'Заявка с сайта',
      ic: 'pi-globe'
    },
    lost: {
      label: 'Статус «Отказ»',
      ic: 'pi-times-circle'
    },
    product: {
      label: 'Товар добавлен',
      ic: 'pi-box'
    },
    contact: {
      label: 'Контакт создан',
      ic: 'pi-user-plus'
    },
    noreply: {
      label: 'Нет ответа 24ч',
      ic: 'pi-clock'
    },
    won: {
      label: 'Сделка выиграна',
      ic: 'pi-check-circle'
    },
    birthday: {
      label: 'Дата рождения',
      ic: 'pi-calendar'
    }
  };
  const S_LOG = [{
    time: '02.07 · 09:41',
    name: 'Приветственное сообщение',
    trig: 'created',
    obj: 'Сделка #10485',
    link: true,
    action: 'Отправлено сообщение в Telegram',
    status: 'ok'
  }, {
    time: '02.07 · 09:38',
    name: 'Задача при смене этапа',
    trig: 'stage',
    obj: 'Сделка #10477',
    link: true,
    action: 'Создана задача «Позвонить клиенту»',
    status: 'ok'
  }, {
    time: '02.07 · 09:36',
    name: 'Автоназначение ответственного',
    trig: 'webform',
    obj: 'Сделка #10484',
    link: true,
    action: 'Назначен Иванов А. С.',
    status: 'ok'
  }, {
    time: '02.07 · 09:30',
    name: 'Уведомление при отказе',
    trig: 'lost',
    obj: 'Сделка #10460',
    link: true,
    action: 'Email руководителю — SMTP timeout',
    status: 'err'
  }, {
    time: '02.07 · 09:24',
    name: 'Пересчёт суммы сделки',
    trig: 'product',
    obj: 'Сделка #10483',
    link: true,
    action: 'Обновлено поле «Сумма» → 480 000 ₽',
    status: 'ok'
  }, {
    time: '02.07 · 09:19',
    name: 'Напоминание о просрочке',
    trig: 'overdue',
    obj: 'Задача #3391',
    link: false,
    action: 'Уведомление ответственному',
    status: 'ok'
  }, {
    time: '02.07 · 09:12',
    name: 'Дедупликация контактов',
    trig: 'contact',
    obj: 'Контакт «Петров И.»',
    link: true,
    action: 'Найден дубль — объединено',
    status: 'ok'
  }, {
    time: '02.07 · 08:58',
    name: 'SLA-эскалация',
    trig: 'noreply',
    obj: 'Сделка #10471',
    link: true,
    action: 'Правило отключено — пропущено',
    status: 'skip'
  }, {
    time: '02.07 · 08:45',
    name: 'Синхронизация с 1С',
    trig: 'won',
    obj: 'Сделка #10455',
    link: true,
    action: 'Выгрузка в 1С — нет связи с сервером',
    status: 'err'
  }, {
    time: '02.07 · 08:30',
    name: 'Поздравление с днём рождения',
    trig: 'birthday',
    obj: 'Контакт «Сидорова М.»',
    link: true,
    action: 'Email отправлен',
    status: 'ok'
  }];
  const S_STATUS = {
    ok: {
      label: 'Успешно',
      bg: 'var(--mg-status-success-bg)',
      fg: 'var(--mg-status-success-text)',
      ic: 'pi-check-circle'
    },
    err: {
      label: 'Ошибка',
      bg: 'var(--mg-status-danger-bg)',
      fg: 'var(--mg-status-danger-text)',
      ic: 'pi-times-circle'
    },
    skip: {
      label: 'Пропущено',
      bg: 'var(--c-muted2)',
      fg: 'var(--c-text2)',
      ic: 'pi-minus-circle'
    }
  };
  function AutomationsTab() {
    const [q, setQ] = useState('');
    const [f, setF] = useState('all');
    const counts = {
      all: S_LOG.length,
      ok: 0,
      err: 0,
      skip: 0
    };
    S_LOG.forEach(r => counts[r.status]++);
    const ql = q.trim().toLowerCase();
    const rows = S_LOG.filter(r => (f === 'all' || r.status === f) && (!ql || r.name.toLowerCase().includes(ql) || r.obj.toLowerCase().includes(ql) || S_TRIG[r.trig].label.toLowerCase().includes(ql)));
    return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("h2", {
      style: {
        margin: '2px 0 4px',
        fontSize: 22,
        fontWeight: 600
      }
    }, "\u0416\u0443\u0440\u043D\u0430\u043B \u0430\u0432\u0442\u043E\u043C\u0430\u0442\u0438\u0437\u0430\u0446\u0438\u0439"), /*#__PURE__*/React.createElement("p", {
      style: {
        margin: '0 0 20px',
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }, "\u0418\u0441\u0442\u043E\u0440\u0438\u044F \u0441\u0440\u0430\u0431\u0430\u0442\u044B\u0432\u0430\u043D\u0438\u044F \u043F\u0440\u0430\u0432\u0438\u043B \u0430\u0432\u0442\u043E\u043C\u0430\u0442\u0438\u0437\u0430\u0446\u0438\u0438 \u0437\u0430 \u043F\u043E\u0441\u043B\u0435\u0434\u043D\u0438\u0435 24 \u0447\u0430\u0441\u0430"), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        flexWrap: 'wrap',
        marginBottom: 14
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        height: 40,
        padding: '0 12px',
        flex: 1,
        minWidth: 200,
        maxWidth: 300,
        border: '1px solid var(--mg-input-border)',
        borderRadius: 'var(--mg-radius-md)',
        background: 'var(--c-card)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-search",
      style: {
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }), /*#__PURE__*/React.createElement("input", {
      value: q,
      onChange: e => setQ(e.target.value),
      placeholder: "\u041F\u043E\u0438\u0441\u043A \u043F\u043E \u043F\u0440\u0430\u0432\u0438\u043B\u0443 \u0438\u043B\u0438 \u043E\u0431\u044A\u0435\u043A\u0442\u0443\u2026",
      style: {
        flex: 1,
        border: 'none',
        outline: 'none',
        background: 'transparent',
        fontFamily: 'var(--mg-font-sans)',
        fontSize: 14,
        color: 'var(--c-text)'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'inline-flex',
        padding: 3,
        gap: 2,
        background: 'var(--c-muted2)',
        borderRadius: 'var(--mg-radius-md)'
      }
    }, [['all', 'Все'], ['ok', 'Успешно'], ['err', 'Ошибка'], ['skip', 'Пропущено']].map(([k, l]) => /*#__PURE__*/React.createElement("button", {
      key: k,
      onClick: () => setF(k),
      style: {
        display: 'inline-flex',
        alignItems: 'center',
        gap: 6,
        height: 32,
        padding: '0 12px',
        border: 'none',
        borderRadius: 'var(--mg-radius-sm)',
        cursor: 'pointer',
        fontFamily: 'var(--mg-font-sans)',
        fontSize: 12.5,
        fontWeight: 600,
        background: f === k ? 'var(--c-card)' : 'transparent',
        color: f === k ? 'var(--mg-primary-900)' : 'var(--c-text2)',
        boxShadow: f === k ? 'var(--mg-shadow-sm)' : 'none'
      }
    }, l, /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 11,
        color: 'var(--c-muted)'
      }
    }, counts[k])))), /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1
      }
    }), /*#__PURE__*/React.createElement("button", {
      style: sBtn('ghost')
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-refresh",
      style: {
        fontSize: 12
      }
    }), "\u041E\u0431\u043D\u043E\u0432\u0438\u0442\u044C")), /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-sm)',
        overflow: 'hidden'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        overflowX: 'auto'
      }
    }, /*#__PURE__*/React.createElement("table", {
      style: {
        width: '100%',
        borderCollapse: 'collapse',
        minWidth: 860
      }
    }, /*#__PURE__*/React.createElement("thead", null, /*#__PURE__*/React.createElement("tr", null, /*#__PURE__*/React.createElement("th", {
      style: {
        ...sTh,
        width: 108
      }
    }, "\u0412\u0440\u0435\u043C\u044F"), /*#__PURE__*/React.createElement("th", {
      style: sTh
    }, "\u0410\u0432\u0442\u043E\u043C\u0430\u0442\u0438\u0437\u0430\u0446\u0438\u044F"), /*#__PURE__*/React.createElement("th", {
      style: sTh
    }, "\u0422\u0440\u0438\u0433\u0433\u0435\u0440"), /*#__PURE__*/React.createElement("th", {
      style: sTh
    }, "\u041E\u0431\u044A\u0435\u043A\u0442"), /*#__PURE__*/React.createElement("th", {
      style: sTh
    }, "\u0414\u0435\u0439\u0441\u0442\u0432\u0438\u0435"), /*#__PURE__*/React.createElement("th", {
      style: {
        ...sTh,
        width: 130
      }
    }, "\u0421\u0442\u0430\u0442\u0443\u0441"))), /*#__PURE__*/React.createElement("tbody", null, rows.map((r, i) => {
      const st = S_STATUS[r.status];
      const tg = S_TRIG[r.trig];
      return /*#__PURE__*/React.createElement("tr", {
        key: i,
        className: "urow",
        style: {
          background: i % 2 ? 'var(--c-hover)' : 'var(--c-card)'
        }
      }, /*#__PURE__*/React.createElement("td", {
        style: {
          ...sTd,
          fontFamily: 'ui-monospace,monospace',
          fontSize: 12,
          color: 'var(--c-muted)',
          whiteSpace: 'nowrap'
        }
      }, r.time), /*#__PURE__*/React.createElement("td", {
        style: {
          ...sTd,
          fontWeight: 600
        }
      }, r.name), /*#__PURE__*/React.createElement("td", {
        style: sTd
      }, /*#__PURE__*/React.createElement("span", {
        style: {
          display: 'inline-flex',
          alignItems: 'center',
          gap: 6,
          fontSize: 12.5,
          fontWeight: 500,
          padding: '3px 9px',
          borderRadius: 6,
          background: 'var(--c-muted2)',
          color: 'var(--c-text2)',
          whiteSpace: 'nowrap'
        }
      }, /*#__PURE__*/React.createElement("i", {
        className: 'pi ' + tg.ic,
        style: {
          fontSize: 11
        }
      }), tg.label)), /*#__PURE__*/React.createElement("td", {
        style: {
          ...sTd,
          whiteSpace: 'nowrap'
        }
      }, /*#__PURE__*/React.createElement("span", {
        style: {
          color: r.link ? 'var(--mg-primary-900)' : 'var(--c-text)',
          fontWeight: r.link ? 500 : 400,
          cursor: r.link ? 'pointer' : 'default'
        }
      }, r.obj)), /*#__PURE__*/React.createElement("td", {
        style: {
          ...sTd,
          color: 'var(--c-text2)'
        }
      }, r.action), /*#__PURE__*/React.createElement("td", {
        style: sTd
      }, /*#__PURE__*/React.createElement("span", {
        style: {
          display: 'inline-flex',
          alignItems: 'center',
          gap: 6,
          fontSize: 12,
          fontWeight: 600,
          padding: '3px 10px',
          borderRadius: 999,
          background: st.bg,
          color: st.fg,
          whiteSpace: 'nowrap'
        }
      }, /*#__PURE__*/React.createElement("i", {
        className: 'pi ' + st.ic,
        style: {
          fontSize: 11
        }
      }), st.label)));
    })))), rows.length === 0 && /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        gap: 8,
        padding: '48px 20px',
        color: 'var(--c-muted)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-clock",
      style: {
        fontSize: 26,
        opacity: 0.4
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 14,
        color: 'var(--c-text2)',
        fontWeight: 500
      }
    }, "\u0417\u0430\u043F\u0438\u0441\u0438 \u043D\u0435 \u043D\u0430\u0439\u0434\u0435\u043D\u044B"))));
  }

  /* ═══════════════ Сброс системы ═══════════════ */
  const S_CATS = [{
    key: 'deals',
    icon: 'pi-briefcase',
    name: 'Сделки',
    desc: 'Все сделки и их история изменений',
    count: '1 248 записей'
  }, {
    key: 'contacts',
    icon: 'pi-user',
    name: 'Контакты',
    desc: 'Контактные лица и их данные',
    count: '3 972 записи'
  }, {
    key: 'companies',
    icon: 'pi-building',
    name: 'Компании',
    desc: 'Организации-клиенты',
    count: '864 записи'
  }, {
    key: 'tasks',
    icon: 'pi-check-square',
    name: 'Задачи и активности',
    desc: 'Задачи, звонки, встречи, заметки',
    count: '5 310 записей'
  }, {
    key: 'docs',
    icon: 'pi-file',
    name: 'Документы и файлы',
    desc: 'Договоры и вложения',
    count: '2 140 файлов'
  }, {
    key: 'finance',
    icon: 'pi-wallet',
    name: 'Финансовые операции',
    desc: 'Платежи, счета, проводки',
    count: '918 записей'
  }, {
    key: 'logs',
    icon: 'pi-history',
    name: 'Журналы и история',
    desc: 'Журнал автоматизаций и аудита',
    count: '42 300 событий'
  }, {
    key: 'automations',
    icon: 'pi-bolt',
    name: 'Правила автоматизаций',
    desc: 'Настроенные сценарии',
    count: '36 правил'
  }, {
    key: 'directories',
    icon: 'pi-folder-open',
    name: 'Справочники',
    desc: 'Товары, теги, поля, курсы валют',
    count: '512 записей'
  }];
  const S_CONFIRM_WORD = 'СБРОСИТЬ';
  function ResetTab() {
    const [sel, setSel] = useState({});
    const [word, setWord] = useState('');
    const [confirm, setConfirm] = useState(false);
    const [done, setDone] = useState(false);
    const selKeys = S_CATS.filter(c => sel[c.key]).map(c => c.key);
    const n = selKeys.length;
    const allOn = n === S_CATS.length;
    const toggle = k => setSel(s => ({
      ...s,
      [k]: !s[k]
    }));
    const toggleAll = () => {
      const v = !allOn;
      const m = {};
      S_CATS.forEach(c => {
        m[c.key] = v;
      });
      setSel(m);
    };
    const canReset = n > 0 && word.trim().toUpperCase() === S_CONFIRM_WORD;
    function doReset() {
      setConfirm(false);
      setDone(true);
      setSel({});
      setWord('');
      setTimeout(() => setDone(false), 3600);
    }
    return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("h2", {
      style: {
        margin: '2px 0 4px',
        fontSize: 22,
        fontWeight: 600
      }
    }, "\u0421\u0431\u0440\u043E\u0441 \u0441\u0438\u0441\u0442\u0435\u043C\u044B"), /*#__PURE__*/React.createElement("p", {
      style: {
        margin: '0 0 20px',
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }, "\u0412\u044B\u0431\u043E\u0440\u043E\u0447\u043D\u043E\u0435 \u0443\u0434\u0430\u043B\u0435\u043D\u0438\u0435 \u0434\u0430\u043D\u043D\u044B\u0445 \u0438\u0437 \u0441\u0438\u0441\u0442\u0435\u043C\u044B. \u041D\u0430\u0441\u0442\u0440\u043E\u0439\u043A\u0438 \u0430\u043A\u043A\u0430\u0443\u043D\u0442\u0430 \u0438 \u0443\u0447\u0451\u0442\u043D\u044B\u0435 \u0437\u0430\u043F\u0438\u0441\u0438 \u0441\u043E\u0445\u0440\u0430\u043D\u044F\u044E\u0442\u0441\u044F."), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 12,
        alignItems: 'flex-start',
        padding: '14px 16px',
        marginBottom: 16,
        background: 'var(--mg-status-danger-bg)',
        border: '1px solid var(--mg-status-danger-border)',
        borderRadius: 'var(--mg-radius-lg)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-exclamation-triangle",
      style: {
        fontSize: 18,
        color: 'var(--mg-status-danger-text)',
        marginTop: 1,
        flexShrink: 0
      }
    }), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 13.5,
        color: 'var(--c-text2)',
        lineHeight: 1.55
      }
    }, /*#__PURE__*/React.createElement("b", {
      style: {
        color: 'var(--mg-status-danger-text)',
        fontWeight: 600
      }
    }, "\u041E\u043F\u0435\u0440\u0430\u0446\u0438\u044F \u043D\u0435\u043E\u0431\u0440\u0430\u0442\u0438\u043C\u0430."), " \u0412\u044B\u0431\u0440\u0430\u043D\u043D\u044B\u0435 \u0434\u0430\u043D\u043D\u044B\u0435 \u0431\u0443\u0434\u0443\u0442 \u0443\u0434\u0430\u043B\u0435\u043D\u044B \u0431\u0435\u0437\u0432\u043E\u0437\u0432\u0440\u0430\u0442\u043D\u043E. \u041F\u0435\u0440\u0435\u0434 \u0441\u0431\u0440\u043E\u0441\u043E\u043C \u0440\u0435\u043A\u043E\u043C\u0435\u043D\u0434\u0443\u0435\u043C \u0432\u044B\u0433\u0440\u0443\u0437\u0438\u0442\u044C \u0440\u0435\u0437\u0435\u0440\u0432\u043D\u0443\u044E \u043A\u043E\u043F\u0438\u044E.")), /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-sm)',
        overflow: 'hidden',
        marginBottom: 16
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: toggleAll,
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 12,
        padding: '13px 16px',
        borderBottom: '1px solid var(--c-border)',
        background: 'var(--c-hover)',
        cursor: 'pointer'
      }
    }, /*#__PURE__*/React.createElement(SCheck, {
      checked: allOn,
      indeterminate: n > 0 && !allOn,
      onChange: toggleAll
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 13.5,
        fontWeight: 600
      }
    }, "\u0412\u044B\u0431\u0440\u0430\u0442\u044C \u0432\u0441\u0451"), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 12.5,
        color: 'var(--c-muted)',
        whiteSpace: 'nowrap',
        flexShrink: 0
      }
    }, "\u0412\u044B\u0431\u0440\u0430\u043D\u043E: ", n, " \u0438\u0437 ", S_CATS.length)), S_CATS.map((c, i) => {
      const on = !!sel[c.key];
      return /*#__PURE__*/React.createElement("div", {
        key: c.key,
        onClick: () => toggle(c.key),
        style: {
          display: 'flex',
          alignItems: 'center',
          gap: 13,
          padding: '13px 16px',
          borderBottom: i < S_CATS.length - 1 ? '1px solid var(--c-border)' : 'none',
          cursor: 'pointer',
          background: on ? 'var(--mg-primary-100)' : 'transparent',
          transition: 'background .12s'
        }
      }, /*#__PURE__*/React.createElement(SCheck, {
        checked: on,
        onChange: () => toggle(c.key)
      }), /*#__PURE__*/React.createElement("span", {
        style: {
          width: 36,
          height: 36,
          borderRadius: 'var(--mg-radius-md)',
          background: 'var(--c-muted2)',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          flexShrink: 0
        }
      }, /*#__PURE__*/React.createElement("i", {
        className: 'pi ' + c.icon,
        style: {
          fontSize: 15,
          color: 'var(--c-text2)'
        }
      })), /*#__PURE__*/React.createElement("div", {
        style: {
          flex: 1,
          minWidth: 0
        }
      }, /*#__PURE__*/React.createElement("div", {
        style: {
          fontSize: 14,
          fontWeight: 600,
          color: 'var(--c-text)'
        }
      }, c.name), /*#__PURE__*/React.createElement("div", {
        style: {
          fontSize: 12.5,
          color: 'var(--c-muted)',
          marginTop: 2
        }
      }, c.desc)), /*#__PURE__*/React.createElement("span", {
        style: {
          fontSize: 12,
          fontWeight: 600,
          padding: '3px 10px',
          borderRadius: 999,
          background: 'var(--c-muted2)',
          color: 'var(--c-text2)',
          whiteSpace: 'nowrap'
        }
      }, c.count));
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-sm)',
        padding: '18px 20px'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 14,
        fontWeight: 600,
        marginBottom: 4
      }
    }, "\u041F\u043E\u0434\u0442\u0432\u0435\u0440\u0436\u0434\u0435\u043D\u0438\u0435"), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 13,
        color: 'var(--c-muted)',
        marginBottom: 12
      }
    }, "\u0427\u0442\u043E\u0431\u044B \u0443\u0434\u0430\u043B\u0438\u0442\u044C ", n > 0 ? 'выбранные данные (' + n + ')' : 'выбранные данные', ", \u0432\u0432\u0435\u0434\u0438\u0442\u0435 ", /*#__PURE__*/React.createElement("b", {
      style: {
        color: 'var(--c-text2)',
        fontFamily: 'ui-monospace,monospace'
      }
    }, S_CONFIRM_WORD), " \u0432 \u043F\u043E\u043B\u0435 \u043D\u0438\u0436\u0435."), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 12,
        alignItems: 'center',
        flexWrap: 'wrap'
      }
    }, /*#__PURE__*/React.createElement("input", {
      className: "inp",
      value: word,
      onChange: e => setWord(e.target.value),
      placeholder: S_CONFIRM_WORD,
      style: {
        flex: 1,
        minWidth: 200,
        maxWidth: 280,
        height: 40,
        padding: '0 13px',
        borderRadius: 'var(--mg-radius-md)',
        border: '1px solid ' + (word && !canReset && n > 0 ? 'var(--mg-status-danger-border)' : 'var(--mg-input-border)'),
        background: 'var(--c-card)',
        color: 'var(--c-text)',
        fontFamily: 'ui-monospace,monospace',
        fontSize: 14,
        letterSpacing: '0.05em'
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1
      }
    }), /*#__PURE__*/React.createElement("button", {
      disabled: !canReset,
      onClick: () => setConfirm(true),
      style: {
        ...sBtn('danger'),
        opacity: canReset ? 1 : 0.45,
        pointerEvents: canReset ? 'auto' : 'none'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-trash",
      style: {
        fontSize: 13
      }
    }), "\u0423\u0434\u0430\u043B\u0438\u0442\u044C \u0432\u044B\u0431\u0440\u0430\u043D\u043D\u043E\u0435", n > 0 ? ' (' + n + ')' : ''))), confirm && /*#__PURE__*/React.createElement("div", {
      onClick: () => setConfirm(false),
      style: {
        position: 'fixed',
        inset: 0,
        zIndex: 1500,
        background: 'rgba(9,16,32,0.55)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: 20
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: e => e.stopPropagation(),
      style: {
        width: 460,
        maxWidth: '92vw',
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-lg)'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '16px 20px',
        borderBottom: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 16,
        fontWeight: 600
      }
    }, "\u0423\u0434\u0430\u043B\u0438\u0442\u044C \u0434\u0430\u043D\u043D\u044B\u0435?"), /*#__PURE__*/React.createElement("i", {
      className: "pi pi-times",
      onClick: () => setConfirm(false),
      style: {
        fontSize: 14,
        color: 'var(--c-muted)',
        cursor: 'pointer'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        padding: 20,
        display: 'flex',
        gap: 14,
        alignItems: 'flex-start'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        width: 42,
        height: 42,
        borderRadius: '50%',
        background: 'var(--mg-status-danger-bg)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        flexShrink: 0
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-exclamation-triangle",
      style: {
        fontSize: 18,
        color: 'var(--mg-status-danger-text)'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 14,
        color: 'var(--c-text2)',
        lineHeight: 1.55,
        paddingTop: 2
      }
    }, "\u0411\u0443\u0434\u0435\u0442 \u0431\u0435\u0437\u0432\u043E\u0437\u0432\u0440\u0430\u0442\u043D\u043E \u0443\u0434\u0430\u043B\u0435\u043D\u043E ", /*#__PURE__*/React.createElement("b", {
      style: {
        color: 'var(--c-text)'
      }
    }, n), " ", n === 1 ? 'категория' : n < 5 ? 'категории' : 'категорий', " \u0434\u0430\u043D\u043D\u044B\u0445: ", selKeys.map(k => (S_CATS.find(c => c.key === k) || {}).name).join(', '), ". \u041F\u0440\u043E\u0434\u043E\u043B\u0436\u0438\u0442\u044C?")), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        justifyContent: 'flex-end',
        gap: 10,
        padding: '14px 20px',
        borderTop: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("button", {
      style: sBtn('ghost'),
      onClick: () => setConfirm(false)
    }, "\u041E\u0442\u043C\u0435\u043D\u0430"), /*#__PURE__*/React.createElement("button", {
      style: sBtn('danger'),
      onClick: doReset
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-trash",
      style: {
        fontSize: 13
      }
    }), "\u0423\u0434\u0430\u043B\u0438\u0442\u044C \u0431\u0435\u0437\u0432\u043E\u0437\u0432\u0440\u0430\u0442\u043D\u043E")))), done && /*#__PURE__*/React.createElement("div", {
      style: {
        position: 'fixed',
        insetBlockEnd: 20,
        insetInlineEnd: 20,
        zIndex: 2000,
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        minWidth: 260,
        padding: '12px 14px',
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderInlineStart: '3px solid var(--mg-status-success-text)',
        borderRadius: 'var(--mg-radius-md)',
        boxShadow: 'var(--mg-shadow-lg)',
        animation: 'toastIn .2s ease'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-check-circle",
      style: {
        fontSize: 16,
        color: 'var(--mg-status-success-text)'
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 13.5,
        fontWeight: 500
      }
    }, "\u0412\u044B\u0431\u0440\u0430\u043D\u043D\u044B\u0435 \u0434\u0430\u043D\u043D\u044B\u0435 \u0443\u0434\u0430\u043B\u0435\u043D\u044B")));
  }
  window.AutomationsTab = AutomationsTab;
  window.ResetTab = ResetTab;
})();
})(); } catch (e) { __ds_ns.__errors.push({ path: "redesign/handoff_settings_redesign/design/system-section.jsx", error: String((e && e.message) || e) }); }

// redesign/handoff_settings_redesign/design/users-section.jsx
try { (() => {
/* Users section for Настройки → Система → Пользователи
   Mirrors front/src/pages/UsersPage (index.vue + composable + dialogs, ru.json),
   redesigned to the fresh MACRO CRM look. Exposes window.UsersTab. */
(function () {
  const {
    useState,
    useRef,
    useEffect
  } = React;
  const U_T = {
    title: 'Пользователи',
    subtitle: 'Управление учётными записями сотрудников',
    addUser: 'Добавить пользователя',
    editUser: 'Редактировать пользователя',
    active: 'Активен',
    inactive: 'Неактивен',
    passwordHint: 'Если не задать пароль, он будет сгенерирован автоматически. Сообщите учётные данные сотруднику.',
    fields: {
      full_name: 'ФИО',
      full_name_ph: 'Иванов Иван Иванович',
      email: 'Email',
      email_ph: 'user@company.com',
      phone: 'Телефон',
      phone_ph: '+7 (999) 000-00-00',
      job_title: 'Должность',
      job_title_ph: 'Менеджер по продажам',
      department: 'Отдел',
      department_ph: 'Выберите отдел',
      manager: 'Руководитель',
      manager_ph: 'Выберите руководителя',
      role: 'Роль',
      role_ph: 'Выберите роль (по умолчанию: Менеджер)',
      password: 'Пароль',
      password_ph: 'Оставьте пустым для автогенерации',
      password_edit_ph: 'Оставьте пустым, чтобы не менять'
    },
    filters: {
      search: 'Поиск по имени, email…',
      role: 'Роль',
      department: 'Отдел',
      status: 'Статус',
      active: 'Активен',
      inactive: 'Неактивен'
    },
    deactivate: 'Деактивировать',
    deactivateTitle: 'Деактивация пользователя',
    deactivateConfirm: n => 'Деактивировать пользователя «' + n + '»? Он не сможет войти в систему, но останется в истории.',
    activate: 'Активировать',
    reset: {
      action: 'Сбросить пароль',
      title: 'Сброс пароля',
      confirm: n => 'Сбросить пароль пользователя «' + n + '»? Текущий пароль перестанет работать, будет сгенерирован новый.',
      resultTitle: 'Новый пароль сгенерирован',
      oneTimeWarning: 'Сохраните пароль — он показывается только один раз. Передайте его пользователю по защищённому каналу.',
      newPassword: 'Новый пароль',
      copy: 'Копировать пароль'
    }
  };
  const U_ROLE_LABEL = {
    admin: 'Администратор',
    director: 'Директор',
    manager: 'Менеджер',
    lawyer: 'Юрист',
    accountant: 'Бухгалтер',
    cfo: 'Финансовый директор'
  };
  const U_ROLE_SEV = {
    admin: 'danger',
    director: 'warn',
    lawyer: 'info',
    cfo: 'warn',
    manager: 'secondary',
    accountant: 'secondary'
  };
  const U_ROLE_OPTS = ['admin', 'director', 'manager', 'lawyer', 'accountant', 'cfo'].map(v => ({
    value: v,
    label: U_ROLE_LABEL[v]
  }));
  const U_ROLE_GRAD = {
    admin: 'linear-gradient(135deg,#4C7DF0,#2B4987)',
    director: 'linear-gradient(135deg,#E6A46E,#C67E3C)',
    cfo: 'linear-gradient(135deg,#E6A46E,#C67E3C)',
    lawyer: 'linear-gradient(135deg,#78B0E0,#4A82BC)',
    manager: 'linear-gradient(135deg,#4FBF8F,#2E9469)',
    accountant: 'linear-gradient(135deg,#9AA3B2,#6C7688)'
  };
  const U_DEPTS = ['Отдел продаж', 'Бухгалтерия', 'Финансы', 'Юридический отдел', 'Руководство'].map((n, i) => ({
    id: i + 1,
    name: n
  }));
  const U_ME = 2;
  const U_USERS = [{
    id: 1,
    full_name: 'Admin',
    email: 'svkv42@gmail.com',
    phone: '+7 (495) 120-33-01',
    job_title: null,
    department_name: null,
    role: 'admin',
    is_active: true
  }, {
    id: 2,
    full_name: 'Bogdan Yadykin',
    email: 'b.yadykin@macroglobaltech.com',
    phone: '+7 (495) 120-33-02',
    job_title: null,
    department_name: null,
    role: 'admin',
    is_active: true
  }, {
    id: 3,
    full_name: 'Lawyer Test',
    email: 'lawyer@mgcrm.test',
    phone: '+7 (495) 120-33-03',
    job_title: null,
    department_name: null,
    role: 'lawyer',
    is_active: true
  }, {
    id: 4,
    full_name: 'MG CRM Admin',
    email: 'admin@mgcrm.test',
    phone: '+7 (495) 120-33-04',
    job_title: null,
    department_name: null,
    role: 'admin',
    is_active: true
  }, {
    id: 5,
    full_name: 'Георгий Некрасов',
    email: 'g.nekrasov@macroglobaltech.com',
    phone: '+7 (701) 555-21-05',
    job_title: 'Менеджер по продажам',
    department_name: 'Отдел продаж',
    role: 'manager',
    is_active: true
  }, {
    id: 6,
    full_name: 'Директор Петров П.П.',
    email: 'director@mgcrm.test',
    phone: '+7 (701) 555-21-06',
    job_title: 'Директор по продажам',
    department_name: 'Отдел продаж',
    role: 'director',
    is_active: true
  }, {
    id: 7,
    full_name: 'Иванов Алексей Сергеевич',
    email: 'manager1@mgcrm.test',
    phone: '+7 (701) 555-21-07',
    job_title: 'Менеджер по продажам',
    department_name: 'Отдел продаж',
    role: 'manager',
    is_active: true
  }, {
    id: 8,
    full_name: 'Илья Рогов',
    email: 'ilyarogov.mera@gmail.com',
    phone: '+7 (701) 555-21-08',
    job_title: 'Менеджер по продажам',
    department_name: 'Отдел продаж',
    role: 'manager',
    is_active: true
  }, {
    id: 9,
    full_name: 'Клим Федорин',
    email: 'k.fedorin@macroglobaltech.com',
    phone: '+7 (701) 555-21-09',
    job_title: 'Менеджер по продажам',
    department_name: 'Отдел продаж',
    role: 'manager',
    is_active: true
  }, {
    id: 10,
    full_name: 'Олеся Моисеева',
    email: 'o.moiseeva@macroglobaltech.com',
    phone: '+7 (701) 555-21-10',
    job_title: 'Менеджер по продажам',
    department_name: 'Отдел продаж',
    role: 'manager',
    is_active: true
  }, {
    id: 11,
    full_name: 'Петрова Мария Сергеевна',
    email: 'manager2@mgcrm.test',
    phone: '+7 (701) 555-21-11',
    job_title: 'Старший менеджер',
    department_name: 'Отдел продаж',
    role: 'manager',
    is_active: true
  }, {
    id: 12,
    full_name: 'Анна Счётова',
    email: 'accountant@mgcrm.test',
    phone: '+7 (701) 340-11-22',
    job_title: 'Главный бухгалтер',
    department_name: 'Бухгалтерия',
    role: 'accountant',
    is_active: true
  }, {
    id: 13,
    full_name: 'Сергей Казначеев',
    email: 'cfo@mgcrm.test',
    phone: '+7 (701) 555-21-13',
    job_title: 'Финансовый директор',
    department_name: 'Финансы',
    role: 'cfo',
    is_active: true
  }, {
    id: 14,
    full_name: 'Роман Уваров',
    email: 'roman.old@mgcrm.test',
    phone: '+7 (701) 555-21-14',
    job_title: 'Менеджер по продажам',
    department_name: 'Отдел продаж',
    role: 'manager',
    is_active: false
  }];
  const U_SEV = {
    success: {
      bg: 'var(--mg-status-success-bg)',
      fg: 'var(--mg-status-success-text)'
    },
    danger: {
      bg: 'var(--mg-status-danger-bg)',
      fg: 'var(--mg-status-danger-text)'
    },
    warn: {
      bg: 'var(--mg-status-warning-bg)',
      fg: 'var(--mg-status-warning-text)'
    },
    info: {
      bg: 'var(--mg-status-info-bg)',
      fg: 'var(--mg-status-info-text)'
    },
    secondary: {
      bg: 'var(--c-muted2)',
      fg: 'var(--c-text2)'
    }
  };
  const uInp = {
    width: '100%',
    height: 40,
    padding: '0 13px',
    borderRadius: 'var(--mg-radius-md)',
    border: '1px solid var(--mg-input-border)',
    background: 'var(--c-card)',
    color: 'var(--c-text)',
    fontFamily: 'var(--mg-font-sans)',
    fontSize: 14,
    boxSizing: 'border-box'
  };
  function uBtn(kind) {
    const base = {
      display: 'inline-flex',
      alignItems: 'center',
      gap: 7,
      height: 38,
      padding: '0 15px',
      borderRadius: 'var(--mg-radius-md)',
      fontFamily: 'var(--mg-font-sans)',
      fontSize: 13,
      fontWeight: 600,
      cursor: 'pointer',
      border: 'none',
      whiteSpace: 'nowrap'
    };
    if (kind === 'primary') return {
      ...base,
      background: 'var(--mg-primary-900)',
      color: '#fff'
    };
    if (kind === 'danger') return {
      ...base,
      background: 'var(--mg-status-danger-solid)',
      color: '#fff'
    };
    if (kind === 'warn') return {
      ...base,
      background: 'var(--mg-status-warning-solid)',
      color: '#3A2A12'
    };
    if (kind === 'text') return {
      ...base,
      background: 'transparent',
      color: 'var(--c-text2)'
    };
    return {
      ...base,
      background: 'transparent',
      color: 'var(--c-text2)',
      border: '1px solid var(--c-border2)'
    };
  }
  function uInitials(name) {
    const w = String(name).trim().split(/\s+/);
    return (w.length >= 2 ? w[0][0] + w[1][0] : w[0].slice(0, 2)).toUpperCase();
  }
  function UTag({
    value,
    severity
  }) {
    const c = U_SEV[severity] || U_SEV.secondary;
    return /*#__PURE__*/React.createElement("span", {
      style: {
        display: 'inline-flex',
        alignItems: 'center',
        fontSize: 11.5,
        fontWeight: 600,
        padding: '3px 9px',
        borderRadius: 6,
        background: c.bg,
        color: c.fg,
        whiteSpace: 'nowrap'
      }
    }, value);
  }
  function UStatus({
    active
  }) {
    const c = active ? U_SEV.success : U_SEV.secondary;
    return /*#__PURE__*/React.createElement("span", {
      style: {
        display: 'inline-flex',
        alignItems: 'center',
        gap: 6,
        fontSize: 12,
        fontWeight: 500,
        color: active ? 'var(--mg-status-success-text)' : 'var(--c-muted)'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        width: 7,
        height: 7,
        borderRadius: '50%',
        background: active ? 'var(--mg-status-success-solid, var(--mg-status-success-text))' : 'var(--c-border2)'
      }
    }), active ? U_T.active : U_T.inactive);
  }
  function UDropdown({
    value,
    onChange,
    options,
    placeholder,
    width,
    filter
  }) {
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const ref = useRef(null);
    useEffect(() => {
      const h = e => {
        if (ref.current && !ref.current.contains(e.target)) setOpen(false);
      };
      document.addEventListener('mousedown', h);
      return () => document.removeEventListener('mousedown', h);
    }, []);
    const sel = options.find(o => o.value === value);
    const list = filter && q ? options.filter(o => o.label.toLowerCase().includes(q.toLowerCase())) : options;
    return /*#__PURE__*/React.createElement("div", {
      ref: ref,
      style: {
        position: 'relative',
        width: width || '100%'
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: () => setOpen(!open),
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        height: 40,
        padding: '0 12px',
        border: '1px solid ' + (open ? 'var(--mg-input-focus-border)' : 'var(--mg-input-border)'),
        borderRadius: 'var(--mg-radius-md)',
        background: 'var(--c-card)',
        cursor: 'pointer',
        boxShadow: open ? 'var(--mg-focus-ring)' : 'none'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 14,
        color: sel ? 'var(--c-text)' : 'var(--c-muted)',
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, sel ? sel.label : placeholder), sel && /*#__PURE__*/React.createElement("i", {
      className: "pi pi-times",
      onClick: e => {
        e.stopPropagation();
        onChange(null);
        setOpen(false);
      },
      style: {
        fontSize: 12,
        color: 'var(--c-muted)',
        cursor: 'pointer'
      }
    }), /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + (open ? 'pi-chevron-up' : 'pi-chevron-down'),
      style: {
        fontSize: 12,
        color: 'var(--c-muted)'
      }
    })), open && /*#__PURE__*/React.createElement("div", {
      style: {
        position: 'absolute',
        top: 44,
        insetInlineStart: 0,
        zIndex: 40,
        width: '100%',
        minWidth: 180,
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-md)',
        boxShadow: 'var(--mg-shadow-lg)',
        padding: 5,
        maxHeight: 280,
        overflowY: 'auto'
      }
    }, filter && /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 7,
        height: 34,
        padding: '0 10px',
        margin: '2px 2px 6px',
        border: '1px solid var(--c-border2)',
        borderRadius: 'var(--mg-radius-sm)',
        background: 'var(--c-page)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-search",
      style: {
        fontSize: 12,
        color: 'var(--c-muted)'
      }
    }), /*#__PURE__*/React.createElement("input", {
      autoFocus: true,
      value: q,
      onChange: e => setQ(e.target.value),
      placeholder: "\u041F\u043E\u0438\u0441\u043A\u2026",
      style: {
        flex: 1,
        border: 'none',
        outline: 'none',
        background: 'transparent',
        fontFamily: 'var(--mg-font-sans)',
        fontSize: 13,
        color: 'var(--c-text)'
      }
    })), list.map(o => {
      const on = o.value === value;
      return /*#__PURE__*/React.createElement("div", {
        key: String(o.value),
        onClick: () => {
          onChange(o.value);
          setOpen(false);
          setQ('');
        },
        style: {
          display: 'flex',
          alignItems: 'center',
          gap: 8,
          padding: '8px 10px',
          borderRadius: 7,
          cursor: 'pointer',
          fontSize: 13.5,
          fontWeight: on ? 600 : 500,
          color: on ? 'var(--mg-primary-900)' : 'var(--c-text)',
          background: on ? 'var(--mg-primary-100)' : 'transparent'
        }
      }, o.label, on && /*#__PURE__*/React.createElement("i", {
        className: "pi pi-check",
        style: {
          marginInlineStart: 'auto',
          fontSize: 12
        }
      }));
    }), list.length === 0 && /*#__PURE__*/React.createElement("div", {
      style: {
        padding: '8px 10px',
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }, "\u041D\u0438\u0447\u0435\u0433\u043E \u043D\u0435 \u043D\u0430\u0439\u0434\u0435\u043D\u043E")));
  }
  function UModal({
    title,
    close,
    footer,
    width,
    children
  }) {
    return /*#__PURE__*/React.createElement("div", {
      onClick: close,
      style: {
        position: 'fixed',
        inset: 0,
        zIndex: 1300,
        background: 'rgba(9,16,32,0.55)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: 20
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: e => e.stopPropagation(),
      style: {
        width: '100%',
        maxWidth: width || 544,
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-lg)',
        overflow: 'hidden',
        maxHeight: 'calc(100vh - 40px)',
        display: 'flex',
        flexDirection: 'column'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '16px 20px',
        borderBottom: '1px solid var(--c-border)',
        flexShrink: 0
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 16,
        fontWeight: 600,
        color: 'var(--c-text)'
      }
    }, title), /*#__PURE__*/React.createElement("i", {
      className: "pi pi-times",
      onClick: close,
      style: {
        fontSize: 14,
        color: 'var(--c-muted)',
        cursor: 'pointer'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        padding: 20,
        overflowY: 'auto'
      }
    }, children), footer && /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'flex-end',
        gap: 10,
        padding: '14px 20px',
        borderTop: '1px solid var(--c-border)',
        flexShrink: 0
      }
    }, footer)));
  }
  function ULabel({
    children
  }) {
    return /*#__PURE__*/React.createElement("label", {
      style: {
        display: 'block',
        fontSize: 13,
        fontWeight: 500,
        color: 'var(--c-text)',
        marginBottom: 6
      }
    }, children);
  }
  function UUserDialog({
    editing,
    close,
    onSave
  }) {
    const isEdit = !!editing;
    const [f, setF] = useState(editing ? {
      full_name: editing.full_name,
      email: editing.email,
      phone: editing.phone || '',
      job_title: editing.job_title || '',
      department_id: (U_DEPTS.find(d => d.name === editing.department_name) || {}).id ?? null,
      manager_id: null,
      role: editing.role,
      password: ''
    } : {
      full_name: '',
      email: '',
      phone: '',
      job_title: '',
      department_id: null,
      manager_id: null,
      role: null,
      password: ''
    });
    const [err, setErr] = useState({});
    const [showPw, setShowPw] = useState(false);
    const set = (k, v) => setF(s => ({
      ...s,
      [k]: v
    }));
    const managers = U_USERS.filter(u => u.id !== (editing || {}).id).map(u => ({
      value: u.id,
      label: u.full_name
    }));
    function submit() {
      const e = {};
      if (!f.full_name.trim()) e.full_name = 'Обязательное поле';
      if (!f.email.trim()) e.email = 'Обязательное поле';else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(f.email.trim())) e.email = 'Некорректный email';
      if (f.password && f.password.length < 8) e.password = 'Минимум 8 символов';
      setErr(e);
      if (Object.keys(e).length) return;
      onSave(f, isEdit);
    }
    const half = {
      flex: 1,
      minWidth: 0
    };
    return /*#__PURE__*/React.createElement(UModal, {
      title: isEdit ? U_T.editUser : U_T.addUser,
      close: close,
      width: 544,
      footer: /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("button", {
        style: uBtn('text'),
        onClick: close
      }, "\u041E\u0442\u043C\u0435\u043D\u0430"), /*#__PURE__*/React.createElement("button", {
        style: uBtn('primary'),
        onClick: submit
      }, isEdit ? 'Сохранить' : 'Создать'))
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        gap: 14
      }
    }, /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement(ULabel, null, U_T.fields.full_name, " *"), /*#__PURE__*/React.createElement("input", {
      className: "inp",
      autoFocus: true,
      value: f.full_name,
      onChange: e => set('full_name', e.target.value),
      placeholder: U_T.fields.full_name_ph,
      style: {
        ...uInp,
        borderColor: err.full_name ? 'var(--mg-status-danger-border)' : 'var(--mg-input-border)'
      }
    }), err.full_name && /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 12,
        color: 'var(--mg-status-danger-text)',
        marginTop: 5
      }
    }, err.full_name)), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement(ULabel, null, U_T.fields.email, " *"), /*#__PURE__*/React.createElement("input", {
      className: "inp",
      type: "email",
      value: f.email,
      onChange: e => set('email', e.target.value),
      placeholder: U_T.fields.email_ph,
      style: {
        ...uInp,
        borderColor: err.email ? 'var(--mg-status-danger-border)' : 'var(--mg-input-border)'
      }
    }), err.email && /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 12,
        color: 'var(--mg-status-danger-text)',
        marginTop: 5
      }
    }, err.email)), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 14
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: half
    }, /*#__PURE__*/React.createElement(ULabel, null, U_T.fields.phone), /*#__PURE__*/React.createElement("input", {
      className: "inp",
      value: f.phone,
      onChange: e => set('phone', e.target.value),
      placeholder: U_T.fields.phone_ph,
      style: uInp
    })), /*#__PURE__*/React.createElement("div", {
      style: half
    }, /*#__PURE__*/React.createElement(ULabel, null, U_T.fields.job_title), /*#__PURE__*/React.createElement("input", {
      className: "inp",
      value: f.job_title,
      onChange: e => set('job_title', e.target.value),
      placeholder: U_T.fields.job_title_ph,
      style: uInp
    }))), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 14
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: half
    }, /*#__PURE__*/React.createElement(ULabel, null, U_T.fields.department), /*#__PURE__*/React.createElement(UDropdown, {
      value: f.department_id,
      onChange: v => set('department_id', v),
      options: U_DEPTS.map(d => ({
        value: d.id,
        label: d.name
      })),
      placeholder: U_T.fields.department_ph
    })), /*#__PURE__*/React.createElement("div", {
      style: half
    }, /*#__PURE__*/React.createElement(ULabel, null, U_T.fields.role), /*#__PURE__*/React.createElement(UDropdown, {
      value: f.role,
      onChange: v => set('role', v),
      options: U_ROLE_OPTS,
      placeholder: U_T.fields.role_ph
    }))), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement(ULabel, null, U_T.fields.manager), /*#__PURE__*/React.createElement(UDropdown, {
      value: f.manager_id,
      onChange: v => set('manager_id', v),
      options: managers,
      placeholder: U_T.fields.manager_ph,
      filter: true
    })), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement(ULabel, null, U_T.fields.password), /*#__PURE__*/React.createElement("div", {
      style: {
        position: 'relative'
      }
    }, /*#__PURE__*/React.createElement("input", {
      className: "inp",
      type: showPw ? 'text' : 'password',
      value: f.password,
      onChange: e => set('password', e.target.value),
      placeholder: isEdit ? U_T.fields.password_edit_ph : U_T.fields.password_ph,
      style: {
        ...uInp,
        paddingInlineEnd: 42,
        borderColor: err.password ? 'var(--mg-status-danger-border)' : 'var(--mg-input-border)'
      }
    }), /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + (showPw ? 'pi-eye-slash' : 'pi-eye'),
      onClick: () => setShowPw(!showPw),
      style: {
        position: 'absolute',
        insetInlineEnd: 13,
        top: 13,
        fontSize: 15,
        color: 'var(--c-muted)',
        cursor: 'pointer'
      }
    })), err.password && /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 12,
        color: 'var(--mg-status-danger-text)',
        marginTop: 5
      }
    }, err.password)), !isEdit && /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 9,
        alignItems: 'flex-start',
        padding: '11px 13px',
        background: 'var(--c-hover)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-md)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-info-circle",
      style: {
        fontSize: 14,
        color: 'var(--c-muted)',
        marginTop: 1
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 12.5,
        color: 'var(--c-text2)',
        lineHeight: 1.5
      }
    }, U_T.passwordHint))));
  }
  function UConfirm({
    header,
    icon,
    iconColor,
    message,
    acceptLabel,
    acceptKind,
    onAccept,
    close
  }) {
    return /*#__PURE__*/React.createElement(UModal, {
      title: header,
      close: close,
      width: 430,
      footer: /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("button", {
        style: uBtn('ghost'),
        onClick: close
      }, "\u041E\u0442\u043C\u0435\u043D\u0430"), /*#__PURE__*/React.createElement("button", {
        style: uBtn(acceptKind),
        onClick: onAccept
      }, acceptLabel))
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 14,
        alignItems: 'flex-start'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        width: 40,
        height: 40,
        borderRadius: '50%',
        background: U_SEV[acceptKind === 'danger' ? 'danger' : 'warn'].bg,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        flexShrink: 0
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + icon,
      style: {
        fontSize: 17,
        color: iconColor
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 14,
        color: 'var(--c-text2)',
        lineHeight: 1.55,
        paddingTop: 3
      }
    }, message)));
  }
  function UResetResult({
    password,
    close
  }) {
    const [copied, setCopied] = useState(false);
    return /*#__PURE__*/React.createElement(UModal, {
      title: U_T.reset.resultTitle,
      close: close,
      width: 448,
      footer: /*#__PURE__*/React.createElement("button", {
        style: uBtn('ghost'),
        onClick: close
      }, "\u0417\u0430\u043A\u0440\u044B\u0442\u044C")
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'flex-start',
        gap: 10,
        padding: 13,
        marginBottom: 18,
        background: 'var(--mg-status-warning-bg)',
        border: '1px solid var(--mg-status-warning-border)',
        borderRadius: 'var(--mg-radius-md)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-exclamation-triangle",
      style: {
        fontSize: 15,
        color: 'var(--mg-status-warning-solid)',
        marginTop: 1,
        flexShrink: 0
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 13,
        color: 'var(--mg-status-warning-text)',
        lineHeight: 1.5
      }
    }, U_T.reset.oneTimeWarning)), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        gap: 8
      }
    }, /*#__PURE__*/React.createElement("label", {
      style: {
        fontSize: 13,
        fontWeight: 500,
        color: 'var(--c-text)'
      }
    }, U_T.reset.newPassword), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        padding: '8px 12px',
        background: 'var(--c-hover)',
        border: '1px solid var(--c-border2)',
        borderRadius: 'var(--mg-radius-md)'
      }
    }, /*#__PURE__*/React.createElement("code", {
      style: {
        flex: 1,
        fontFamily: 'ui-monospace,monospace',
        fontSize: 15,
        fontWeight: 500,
        color: 'var(--c-text)',
        letterSpacing: '0.04em',
        wordBreak: 'break-all'
      }
    }, password), /*#__PURE__*/React.createElement("button", {
      onClick: () => {
        navigator.clipboard && navigator.clipboard.writeText(password);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
      },
      title: U_T.reset.copy,
      style: {
        width: 32,
        height: 32,
        borderRadius: '50%',
        border: 'none',
        background: 'transparent',
        cursor: 'pointer',
        display: 'inline-flex',
        alignItems: 'center',
        justifyContent: 'center'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + (copied ? 'pi-check' : 'pi-copy'),
      style: {
        fontSize: 14,
        color: copied ? 'var(--mg-status-success-text)' : 'var(--c-muted)'
      }
    })))));
  }
  function UToasts({
    items
  }) {
    return /*#__PURE__*/React.createElement("div", {
      style: {
        position: 'fixed',
        insetBlockEnd: 20,
        insetInlineEnd: 20,
        zIndex: 2000,
        display: 'flex',
        flexDirection: 'column',
        gap: 10
      }
    }, items.map(t => {
      const c = U_SEV[t.severity] || U_SEV.success;
      return /*#__PURE__*/React.createElement("div", {
        key: t.id,
        style: {
          display: 'flex',
          alignItems: 'center',
          gap: 10,
          minWidth: 260,
          maxWidth: 360,
          padding: '12px 14px',
          background: 'var(--c-card)',
          border: '1px solid var(--c-border)',
          borderInlineStart: '3px solid ' + c.fg,
          borderRadius: 'var(--mg-radius-md)',
          boxShadow: 'var(--mg-shadow-lg)',
          animation: 'toastIn .2s ease'
        }
      }, /*#__PURE__*/React.createElement("i", {
        className: "pi pi-check-circle",
        style: {
          fontSize: 16,
          color: c.fg
        }
      }), /*#__PURE__*/React.createElement("span", {
        style: {
          fontSize: 13.5,
          fontWeight: 500,
          color: 'var(--c-text)'
        }
      }, t.summary));
    }));
  }
  function UIconBtn({
    icon,
    color,
    title,
    onClick
  }) {
    return /*#__PURE__*/React.createElement("button", {
      className: "uicon",
      onClick: onClick,
      title: title,
      "aria-label": title,
      style: {
        width: 32,
        height: 32,
        borderRadius: 8,
        border: 'none',
        background: 'transparent',
        cursor: 'pointer',
        display: 'inline-flex',
        alignItems: 'center',
        justifyContent: 'center'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + icon,
      style: {
        fontSize: 14,
        color
      }
    }));
  }
  function UsersTab() {
    const [rows, setRows] = useState(U_USERS);
    const [search, setSearch] = useState('');
    const [roleF, setRoleF] = useState(null);
    const [deptF, setDeptF] = useState(null);
    const [activeF, setActiveF] = useState(null);
    const [dialog, setDialog] = useState(null);
    const [toasts, setToasts] = useState([]);
    const [editing, setEditing] = useState(false);
    const pushToast = summary => {
      const id = Date.now() + Math.random();
      setToasts(t => [...t, {
        id,
        summary,
        severity: 'success'
      }]);
      setTimeout(() => setToasts(t => t.filter(x => x.id !== id)), 2600);
    };
    const th = {
      padding: '11px 16px',
      fontSize: 11.5,
      fontWeight: 600,
      letterSpacing: '.03em',
      textTransform: 'uppercase',
      color: 'var(--c-muted)',
      textAlign: 'start',
      borderBottom: '1px solid var(--c-border)',
      whiteSpace: 'nowrap'
    };
    const td = {
      padding: '10px 16px',
      fontSize: 13.5,
      color: 'var(--c-text)',
      borderBottom: '1px solid var(--c-border)',
      verticalAlign: 'middle'
    };
    const filtered = rows.filter(u => {
      if (search.trim()) {
        const q = search.trim().toLowerCase();
        if (!(u.full_name.toLowerCase().includes(q) || u.email.toLowerCase().includes(q))) return false;
      }
      if (roleF && u.role !== roleF) return false;
      if (deptF !== null && (U_DEPTS.find(d => d.id === deptF) || {}).name !== u.department_name) return false;
      return true;
    });
    const active = filtered.filter(u => u.is_active);
    const inactive = filtered.filter(u => !u.is_active);
    const activeCount = rows.filter(u => u.is_active).length;
    function saveUser(form, isEdit) {
      if (isEdit) {
        setRows(rs => rs.map(u => u.id === dialog.editing.id ? {
          ...u,
          full_name: form.full_name.trim(),
          email: form.email.trim(),
          phone: form.phone.trim() || null,
          job_title: form.job_title.trim() || null,
          department_name: (U_DEPTS.find(d => d.id === form.department_id) || {}).name ?? null,
          role: form.role
        } : u));
        pushToast('Изменения сохранены');
      } else {
        const id = Math.max(...rows.map(r => r.id)) + 1;
        setRows(rs => [...rs, {
          id,
          full_name: form.full_name.trim(),
          email: form.email.trim(),
          phone: form.phone.trim() || null,
          job_title: form.job_title.trim() || null,
          department_name: (U_DEPTS.find(d => d.id === form.department_id) || {}).name ?? null,
          role: form.role || 'manager',
          is_active: true
        }]);
        pushToast('Пользователь создан');
      }
      setDialog(null);
    }
    function doDeactivate(u) {
      setRows(rs => rs.map(x => x.id === u.id ? {
        ...x,
        is_active: false
      } : x));
      pushToast('Пользователь деактивирован');
      setDialog(null);
    }
    function doReactivate(u) {
      setRows(rs => rs.map(x => x.id === u.id ? {
        ...x,
        is_active: true
      } : x));
      pushToast('Пользователь активирован');
    }
    function doReset() {
      setDialog({
        type: 'resetResult',
        pw: uGenPw()
      });
    }
    const columns = /*#__PURE__*/React.createElement("thead", null, /*#__PURE__*/React.createElement("tr", null, /*#__PURE__*/React.createElement("th", {
      style: th
    }, "\u0421\u043E\u0442\u0440\u0443\u0434\u043D\u0438\u043A"), /*#__PURE__*/React.createElement("th", {
      style: {
        ...th,
        width: 170
      }
    }, "\u0422\u0435\u043B\u0435\u0444\u043E\u043D"), /*#__PURE__*/React.createElement("th", {
      style: {
        ...th,
        width: 180
      }
    }, "\u0414\u043E\u043B\u0436\u043D\u043E\u0441\u0442\u044C"), /*#__PURE__*/React.createElement("th", {
      style: {
        ...th,
        width: 150
      }
    }, "\u041E\u0442\u0434\u0435\u043B"), /*#__PURE__*/React.createElement("th", {
      style: {
        ...th,
        width: 150
      }
    }, "\u0420\u043E\u043B\u044C"), editing && /*#__PURE__*/React.createElement("th", {
      style: {
        ...th,
        width: 120,
        textAlign: 'end'
      }
    }, "\u0414\u0435\u0439\u0441\u0442\u0432\u0438\u044F")));
    const renderRow = (u, archive) => /*#__PURE__*/React.createElement("tr", {
      key: u.id,
      className: "urow",
      style: {
        transition: 'background .12s',
        opacity: archive ? 0.72 : 1
      }
    }, /*#__PURE__*/React.createElement("td", {
      style: td
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 11
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        width: 36,
        height: 36,
        borderRadius: '50%',
        flexShrink: 0,
        background: archive ? 'var(--c-muted2)' : U_ROLE_GRAD[u.role] || U_ROLE_GRAD.accountant,
        color: archive ? 'var(--c-muted)' : '#fff',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        fontSize: 12.5,
        fontWeight: 600,
        letterSpacing: '.02em'
      }
    }, uInitials(u.full_name)), /*#__PURE__*/React.createElement("div", {
      style: {
        minWidth: 0
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        fontWeight: 600,
        fontSize: 13.5,
        color: 'var(--c-text)',
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, u.full_name), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 12,
        color: 'var(--c-muted)',
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, u.email)))), /*#__PURE__*/React.createElement("td", {
      style: {
        ...td,
        color: u.phone ? 'var(--c-text2)' : 'var(--c-muted)',
        whiteSpace: 'nowrap',
        fontVariantNumeric: 'tabular-nums'
      }
    }, u.phone ?? '—'), /*#__PURE__*/React.createElement("td", {
      style: {
        ...td,
        color: u.job_title ? 'var(--c-text2)' : 'var(--c-muted)'
      }
    }, u.job_title ?? '—'), /*#__PURE__*/React.createElement("td", {
      style: {
        ...td,
        color: u.department_name ? 'var(--c-text2)' : 'var(--c-muted)'
      }
    }, u.department_name ?? '—'), /*#__PURE__*/React.createElement("td", {
      style: td
    }, u.role ? /*#__PURE__*/React.createElement(UTag, {
      value: U_ROLE_LABEL[u.role],
      severity: U_ROLE_SEV[u.role]
    }) : /*#__PURE__*/React.createElement("span", {
      style: {
        color: 'var(--c-muted)'
      }
    }, "\u2014")), editing && /*#__PURE__*/React.createElement("td", {
      style: td
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 2,
        justifyContent: 'flex-end'
      }
    }, /*#__PURE__*/React.createElement(UIconBtn, {
      icon: "pi-pencil",
      color: "var(--c-text2)",
      title: "\u0420\u0435\u0434\u0430\u043A\u0442\u0438\u0440\u043E\u0432\u0430\u0442\u044C",
      onClick: () => setDialog({
        type: 'form',
        editing: u
      })
    }), u.id !== U_ME && /*#__PURE__*/React.createElement(UIconBtn, {
      icon: "pi-key",
      color: "var(--c-text2)",
      title: U_T.reset.action,
      onClick: () => setDialog({
        type: 'confirm',
        kind: 'reset',
        user: u
      })
    }), archive ? /*#__PURE__*/React.createElement(UIconBtn, {
      icon: "pi-replay",
      color: "var(--mg-status-success-text)",
      title: U_T.activate,
      onClick: () => doReactivate(u)
    }) : /*#__PURE__*/React.createElement(UIconBtn, {
      icon: "pi-ban",
      color: "var(--mg-status-danger-text)",
      title: U_T.deactivate,
      onClick: () => setDialog({
        type: 'confirm',
        kind: 'deactivate',
        user: u
      })
    }))));
    return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'flex-start',
        gap: 16,
        marginBottom: 20
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        flex: 1,
        minWidth: 0
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10
      }
    }, /*#__PURE__*/React.createElement("h2", {
      style: {
        margin: 0,
        fontSize: 20,
        fontWeight: 600
      }
    }, U_T.title), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 12,
        fontWeight: 600,
        padding: '2px 9px',
        borderRadius: 999,
        background: 'var(--mg-primary-100)',
        color: 'var(--mg-primary-900)'
      }
    }, rows.length)), /*#__PURE__*/React.createElement("p", {
      style: {
        margin: '5px 0 0',
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }, U_T.subtitle, " \xB7 ", activeCount, " \u0430\u043A\u0442\u0438\u0432\u043D\u044B\u0445")), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 10,
        flexShrink: 0
      }
    }, /*#__PURE__*/React.createElement("button", {
      style: editing ? {
        ...uBtn('ghost'),
        color: 'var(--mg-primary-900)',
        borderColor: 'var(--mg-primary-900)'
      } : uBtn('ghost'),
      onClick: () => setEditing(e => !e)
    }, /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + (editing ? 'pi-check' : 'pi-pencil'),
      style: {
        fontSize: 12
      }
    }), editing ? 'Завершить редактирование' : 'Редактировать'), /*#__PURE__*/React.createElement("button", {
      style: uBtn('primary'),
      onClick: () => setDialog({
        type: 'form',
        editing: null
      })
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-plus",
      style: {
        fontSize: 12
      }
    }), U_T.addUser))), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        flexWrap: 'wrap',
        marginBottom: 14
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        height: 40,
        padding: '0 12px',
        flex: 1,
        minWidth: 200,
        maxWidth: 320,
        border: '1px solid var(--mg-input-border)',
        borderRadius: 'var(--mg-radius-md)',
        background: 'var(--c-card)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-search",
      style: {
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }), /*#__PURE__*/React.createElement("input", {
      value: search,
      onChange: e => setSearch(e.target.value),
      placeholder: U_T.filters.search,
      style: {
        flex: 1,
        border: 'none',
        outline: 'none',
        background: 'transparent',
        fontFamily: 'var(--mg-font-sans)',
        fontSize: 14,
        color: 'var(--c-text)'
      }
    })), /*#__PURE__*/React.createElement(UDropdown, {
      value: roleF,
      onChange: setRoleF,
      options: U_ROLE_OPTS,
      placeholder: U_T.filters.role,
      width: 150
    }), /*#__PURE__*/React.createElement(UDropdown, {
      value: deptF,
      onChange: setDeptF,
      options: U_DEPTS.map(d => ({
        value: d.id,
        label: d.name
      })),
      placeholder: U_T.filters.department,
      width: 168
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-sm)',
        overflow: 'hidden'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        overflowX: 'auto'
      }
    }, /*#__PURE__*/React.createElement("table", {
      style: {
        width: '100%',
        borderCollapse: 'collapse',
        minWidth: 820
      }
    }, columns, /*#__PURE__*/React.createElement("tbody", null, active.map(u => renderRow(u, false))))), active.length === 0 && /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        gap: 12,
        padding: '52px 20px',
        color: 'var(--c-muted)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-users",
      style: {
        fontSize: 30,
        opacity: 0.3
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 14,
        color: 'var(--c-text2)'
      }
    }, "\u0410\u043A\u0442\u0438\u0432\u043D\u044B\u0435 \u043F\u043E\u043B\u044C\u0437\u043E\u0432\u0430\u0442\u0435\u043B\u0438 \u043D\u0435 \u043D\u0430\u0439\u0434\u0435\u043D\u044B"))), inactive.length > 0 && /*#__PURE__*/React.createElement("div", {
      style: {
        marginTop: 28
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 9,
        marginBottom: 12
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-user-minus",
      style: {
        fontSize: 14,
        color: 'var(--c-muted)'
      }
    }), /*#__PURE__*/React.createElement("h3", {
      style: {
        margin: 0,
        fontSize: 15,
        fontWeight: 600,
        color: 'var(--c-text2)'
      }
    }, "\u0423\u0432\u043E\u043B\u0435\u043D\u043D\u044B\u0435"), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 11.5,
        fontWeight: 600,
        padding: '2px 8px',
        borderRadius: 999,
        background: 'var(--c-muted2)',
        color: 'var(--c-text2)'
      }
    }, inactive.length)), /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-sm)',
        overflow: 'hidden'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        overflowX: 'auto'
      }
    }, /*#__PURE__*/React.createElement("table", {
      style: {
        width: '100%',
        borderCollapse: 'collapse',
        minWidth: 820
      }
    }, columns, /*#__PURE__*/React.createElement("tbody", null, inactive.map(u => renderRow(u, true))))))), dialog && dialog.type === 'form' && /*#__PURE__*/React.createElement(UUserDialog, {
      key: dialog.editing ? 'edit-' + dialog.editing.id : 'new',
      editing: dialog.editing,
      close: () => setDialog(null),
      onSave: saveUser
    }), dialog && dialog.type === 'confirm' && dialog.kind === 'deactivate' && /*#__PURE__*/React.createElement(UConfirm, {
      header: U_T.deactivateTitle,
      icon: "pi-exclamation-triangle",
      iconColor: "var(--mg-status-warning-solid)",
      message: U_T.deactivateConfirm(dialog.user.full_name),
      acceptLabel: U_T.deactivate,
      acceptKind: "danger",
      onAccept: () => doDeactivate(dialog.user),
      close: () => setDialog(null)
    }), dialog && dialog.type === 'confirm' && dialog.kind === 'reset' && /*#__PURE__*/React.createElement(UConfirm, {
      header: U_T.reset.title,
      icon: "pi-key",
      iconColor: "var(--mg-status-warning-solid)",
      message: U_T.reset.confirm(dialog.user.full_name),
      acceptLabel: U_T.reset.action,
      acceptKind: "warn",
      onAccept: doReset,
      close: () => setDialog(null)
    }), dialog && dialog.type === 'resetResult' && /*#__PURE__*/React.createElement(UResetResult, {
      password: dialog.pw,
      close: () => setDialog(null)
    }), /*#__PURE__*/React.createElement(UToasts, {
      items: toasts
    }));
  }
  function uGenPw() {
    const a = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    let s = '';
    for (let i = 0; i < 12; i++) s += a[Math.floor(Math.random() * a.length)];
    return s;
  }
  window.UsersTab = UsersTab;
})();
})(); } catch (e) { __ds_ns.__errors.push({ path: "redesign/handoff_settings_redesign/design/users-section.jsx", error: String((e && e.message) || e) }); }

// redesign/system-section.jsx
try { (() => {
/* System sections for Настройки → Система:
   - Журнал автоматизаций (AutomationsTab): read-only execution log of automation rules.
   - Сброс системы (ResetTab): selective data wipe — checkbox choice of which datasets to delete
     (unlike the product's full-wipe behaviour, per request).
   Built to match the fresh MACRO CRM look (shared --c-* / --mg-* tokens, table th/td, tags).
   Exposes window.AutomationsTab and window.ResetTab. */
(function () {
  const {
    useState
  } = React;

  /* ── shared button/tag helpers (mirror settings.html btn()) ── */
  function sBtn(kind) {
    const base = {
      display: 'inline-flex',
      alignItems: 'center',
      gap: 7,
      height: 38,
      padding: '0 15px',
      borderRadius: 'var(--mg-radius-md)',
      fontFamily: 'var(--mg-font-sans)',
      fontSize: 13,
      fontWeight: 600,
      cursor: 'pointer',
      border: 'none',
      whiteSpace: 'nowrap'
    };
    if (kind === 'primary') return {
      ...base,
      background: 'var(--mg-primary-900)',
      color: '#fff'
    };
    if (kind === 'danger') return {
      ...base,
      background: 'var(--mg-status-danger-solid)',
      color: '#fff'
    };
    return {
      ...base,
      background: 'transparent',
      color: 'var(--c-text2)',
      border: '1px solid var(--c-border2)'
    };
  }
  const sTh = {
    padding: '11px 16px',
    fontSize: 12,
    fontWeight: 600,
    color: 'var(--c-text2)',
    textAlign: 'start',
    borderBottom: '1px solid var(--c-border)',
    whiteSpace: 'nowrap',
    background: 'var(--c-card)'
  };
  const sTd = {
    padding: '12px 16px',
    fontSize: 13.5,
    color: 'var(--c-text)',
    borderBottom: '1px solid var(--c-border)',
    verticalAlign: 'middle'
  };
  function SCheck({
    checked,
    onChange,
    disabled,
    indeterminate
  }) {
    const active = checked || indeterminate;
    return /*#__PURE__*/React.createElement("span", {
      onClick: disabled ? undefined : e => {
        e.stopPropagation();
        onChange();
      },
      role: "checkbox",
      "aria-checked": checked,
      style: {
        width: 20,
        height: 20,
        borderRadius: 6,
        border: '1.5px solid ' + (active ? 'var(--mg-primary-900)' : 'var(--c-border2)'),
        background: active ? 'var(--mg-primary-900)' : 'var(--c-card)',
        display: 'inline-flex',
        alignItems: 'center',
        justifyContent: 'center',
        cursor: disabled ? 'not-allowed' : 'pointer',
        flexShrink: 0,
        opacity: disabled ? 0.45 : 1,
        transition: 'background .12s, border-color .12s'
      }
    }, checked ? /*#__PURE__*/React.createElement("i", {
      className: "pi pi-check",
      style: {
        fontSize: 11,
        color: '#fff'
      }
    }) : indeterminate ? /*#__PURE__*/React.createElement("span", {
      style: {
        width: 9,
        height: 2,
        borderRadius: 1,
        background: '#fff'
      }
    }) : null);
  }

  /* ═══════════════ Журнал автоматизаций ═══════════════ */
  const S_TRIG = {
    created: {
      label: 'Сделка создана',
      ic: 'pi-plus-circle'
    },
    stage: {
      label: 'Этап изменён',
      ic: 'pi-sort-alt'
    },
    overdue: {
      label: 'Задача просрочена',
      ic: 'pi-exclamation-circle'
    },
    webform: {
      label: 'Заявка с сайта',
      ic: 'pi-globe'
    },
    lost: {
      label: 'Статус «Отказ»',
      ic: 'pi-times-circle'
    },
    product: {
      label: 'Товар добавлен',
      ic: 'pi-box'
    },
    contact: {
      label: 'Контакт создан',
      ic: 'pi-user-plus'
    },
    noreply: {
      label: 'Нет ответа 24ч',
      ic: 'pi-clock'
    },
    won: {
      label: 'Сделка выиграна',
      ic: 'pi-check-circle'
    },
    birthday: {
      label: 'Дата рождения',
      ic: 'pi-calendar'
    }
  };
  const S_LOG = [{
    time: '02.07 · 09:41',
    name: 'Приветственное сообщение',
    trig: 'created',
    obj: 'Сделка #10485',
    link: true,
    action: 'Отправлено сообщение в Telegram',
    status: 'ok'
  }, {
    time: '02.07 · 09:38',
    name: 'Задача при смене этапа',
    trig: 'stage',
    obj: 'Сделка #10477',
    link: true,
    action: 'Создана задача «Позвонить клиенту»',
    status: 'ok'
  }, {
    time: '02.07 · 09:36',
    name: 'Автоназначение ответственного',
    trig: 'webform',
    obj: 'Сделка #10484',
    link: true,
    action: 'Назначен Иванов А. С.',
    status: 'ok'
  }, {
    time: '02.07 · 09:30',
    name: 'Уведомление при отказе',
    trig: 'lost',
    obj: 'Сделка #10460',
    link: true,
    action: 'Email руководителю — SMTP timeout',
    status: 'err'
  }, {
    time: '02.07 · 09:24',
    name: 'Пересчёт суммы сделки',
    trig: 'product',
    obj: 'Сделка #10483',
    link: true,
    action: 'Обновлено поле «Сумма» → 480 000 ₽',
    status: 'ok'
  }, {
    time: '02.07 · 09:19',
    name: 'Напоминание о просрочке',
    trig: 'overdue',
    obj: 'Задача #3391',
    link: false,
    action: 'Уведомление ответственному',
    status: 'ok'
  }, {
    time: '02.07 · 09:12',
    name: 'Дедупликация контактов',
    trig: 'contact',
    obj: 'Контакт «Петров И.»',
    link: true,
    action: 'Найден дубль — объединено',
    status: 'ok'
  }, {
    time: '02.07 · 08:58',
    name: 'SLA-эскалация',
    trig: 'noreply',
    obj: 'Сделка #10471',
    link: true,
    action: 'Правило отключено — пропущено',
    status: 'skip'
  }, {
    time: '02.07 · 08:45',
    name: 'Синхронизация с 1С',
    trig: 'won',
    obj: 'Сделка #10455',
    link: true,
    action: 'Выгрузка в 1С — нет связи с сервером',
    status: 'err'
  }, {
    time: '02.07 · 08:30',
    name: 'Поздравление с днём рождения',
    trig: 'birthday',
    obj: 'Контакт «Сидорова М.»',
    link: true,
    action: 'Email отправлен',
    status: 'ok'
  }];
  const S_STATUS = {
    ok: {
      label: 'Успешно',
      bg: 'var(--mg-status-success-bg)',
      fg: 'var(--mg-status-success-text)',
      ic: 'pi-check-circle'
    },
    err: {
      label: 'Ошибка',
      bg: 'var(--mg-status-danger-bg)',
      fg: 'var(--mg-status-danger-text)',
      ic: 'pi-times-circle'
    },
    skip: {
      label: 'Пропущено',
      bg: 'var(--c-muted2)',
      fg: 'var(--c-text2)',
      ic: 'pi-minus-circle'
    }
  };
  function AutomationsTab() {
    const [q, setQ] = useState('');
    const [f, setF] = useState('all');
    const counts = {
      all: S_LOG.length,
      ok: 0,
      err: 0,
      skip: 0
    };
    S_LOG.forEach(r => counts[r.status]++);
    const ql = q.trim().toLowerCase();
    const rows = S_LOG.filter(r => (f === 'all' || r.status === f) && (!ql || r.name.toLowerCase().includes(ql) || r.obj.toLowerCase().includes(ql) || S_TRIG[r.trig].label.toLowerCase().includes(ql)));
    return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("h2", {
      style: {
        margin: '2px 0 4px',
        fontSize: 22,
        fontWeight: 600
      }
    }, "\u0416\u0443\u0440\u043D\u0430\u043B \u0430\u0432\u0442\u043E\u043C\u0430\u0442\u0438\u0437\u0430\u0446\u0438\u0439"), /*#__PURE__*/React.createElement("p", {
      style: {
        margin: '0 0 20px',
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }, "\u0418\u0441\u0442\u043E\u0440\u0438\u044F \u0441\u0440\u0430\u0431\u0430\u0442\u044B\u0432\u0430\u043D\u0438\u044F \u043F\u0440\u0430\u0432\u0438\u043B \u0430\u0432\u0442\u043E\u043C\u0430\u0442\u0438\u0437\u0430\u0446\u0438\u0438 \u0437\u0430 \u043F\u043E\u0441\u043B\u0435\u0434\u043D\u0438\u0435 24 \u0447\u0430\u0441\u0430"), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        flexWrap: 'wrap',
        marginBottom: 14
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        height: 40,
        padding: '0 12px',
        flex: 1,
        minWidth: 200,
        maxWidth: 300,
        border: '1px solid var(--mg-input-border)',
        borderRadius: 'var(--mg-radius-md)',
        background: 'var(--c-card)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-search",
      style: {
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }), /*#__PURE__*/React.createElement("input", {
      value: q,
      onChange: e => setQ(e.target.value),
      placeholder: "\u041F\u043E\u0438\u0441\u043A \u043F\u043E \u043F\u0440\u0430\u0432\u0438\u043B\u0443 \u0438\u043B\u0438 \u043E\u0431\u044A\u0435\u043A\u0442\u0443\u2026",
      style: {
        flex: 1,
        border: 'none',
        outline: 'none',
        background: 'transparent',
        fontFamily: 'var(--mg-font-sans)',
        fontSize: 14,
        color: 'var(--c-text)'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'inline-flex',
        padding: 3,
        gap: 2,
        background: 'var(--c-muted2)',
        borderRadius: 'var(--mg-radius-md)'
      }
    }, [['all', 'Все'], ['ok', 'Успешно'], ['err', 'Ошибка'], ['skip', 'Пропущено']].map(([k, l]) => /*#__PURE__*/React.createElement("button", {
      key: k,
      onClick: () => setF(k),
      style: {
        display: 'inline-flex',
        alignItems: 'center',
        gap: 6,
        height: 32,
        padding: '0 12px',
        border: 'none',
        borderRadius: 'var(--mg-radius-sm)',
        cursor: 'pointer',
        fontFamily: 'var(--mg-font-sans)',
        fontSize: 12.5,
        fontWeight: 600,
        background: f === k ? 'var(--c-card)' : 'transparent',
        color: f === k ? 'var(--mg-primary-900)' : 'var(--c-text2)',
        boxShadow: f === k ? 'var(--mg-shadow-sm)' : 'none'
      }
    }, l, /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 11,
        color: 'var(--c-muted)'
      }
    }, counts[k])))), /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1
      }
    }), /*#__PURE__*/React.createElement("button", {
      style: sBtn('ghost')
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-refresh",
      style: {
        fontSize: 12
      }
    }), "\u041E\u0431\u043D\u043E\u0432\u0438\u0442\u044C")), /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-sm)',
        overflow: 'hidden'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        overflowX: 'auto'
      }
    }, /*#__PURE__*/React.createElement("table", {
      style: {
        width: '100%',
        borderCollapse: 'collapse',
        minWidth: 860
      }
    }, /*#__PURE__*/React.createElement("thead", null, /*#__PURE__*/React.createElement("tr", null, /*#__PURE__*/React.createElement("th", {
      style: {
        ...sTh,
        width: 108
      }
    }, "\u0412\u0440\u0435\u043C\u044F"), /*#__PURE__*/React.createElement("th", {
      style: sTh
    }, "\u0410\u0432\u0442\u043E\u043C\u0430\u0442\u0438\u0437\u0430\u0446\u0438\u044F"), /*#__PURE__*/React.createElement("th", {
      style: sTh
    }, "\u0422\u0440\u0438\u0433\u0433\u0435\u0440"), /*#__PURE__*/React.createElement("th", {
      style: sTh
    }, "\u041E\u0431\u044A\u0435\u043A\u0442"), /*#__PURE__*/React.createElement("th", {
      style: sTh
    }, "\u0414\u0435\u0439\u0441\u0442\u0432\u0438\u0435"), /*#__PURE__*/React.createElement("th", {
      style: {
        ...sTh,
        width: 130
      }
    }, "\u0421\u0442\u0430\u0442\u0443\u0441"))), /*#__PURE__*/React.createElement("tbody", null, rows.map((r, i) => {
      const st = S_STATUS[r.status];
      const tg = S_TRIG[r.trig];
      return /*#__PURE__*/React.createElement("tr", {
        key: i,
        className: "urow",
        style: {
          background: i % 2 ? 'var(--c-hover)' : 'var(--c-card)'
        }
      }, /*#__PURE__*/React.createElement("td", {
        style: {
          ...sTd,
          fontFamily: 'ui-monospace,monospace',
          fontSize: 12,
          color: 'var(--c-muted)',
          whiteSpace: 'nowrap'
        }
      }, r.time), /*#__PURE__*/React.createElement("td", {
        style: {
          ...sTd,
          fontWeight: 600
        }
      }, r.name), /*#__PURE__*/React.createElement("td", {
        style: sTd
      }, /*#__PURE__*/React.createElement("span", {
        style: {
          display: 'inline-flex',
          alignItems: 'center',
          gap: 6,
          fontSize: 12.5,
          fontWeight: 500,
          padding: '3px 9px',
          borderRadius: 6,
          background: 'var(--c-muted2)',
          color: 'var(--c-text2)',
          whiteSpace: 'nowrap'
        }
      }, /*#__PURE__*/React.createElement("i", {
        className: 'pi ' + tg.ic,
        style: {
          fontSize: 11
        }
      }), tg.label)), /*#__PURE__*/React.createElement("td", {
        style: {
          ...sTd,
          whiteSpace: 'nowrap'
        }
      }, /*#__PURE__*/React.createElement("span", {
        style: {
          color: r.link ? 'var(--mg-primary-900)' : 'var(--c-text)',
          fontWeight: r.link ? 500 : 400,
          cursor: r.link ? 'pointer' : 'default'
        }
      }, r.obj)), /*#__PURE__*/React.createElement("td", {
        style: {
          ...sTd,
          color: 'var(--c-text2)'
        }
      }, r.action), /*#__PURE__*/React.createElement("td", {
        style: sTd
      }, /*#__PURE__*/React.createElement("span", {
        style: {
          display: 'inline-flex',
          alignItems: 'center',
          gap: 6,
          fontSize: 12,
          fontWeight: 600,
          padding: '3px 10px',
          borderRadius: 999,
          background: st.bg,
          color: st.fg,
          whiteSpace: 'nowrap'
        }
      }, /*#__PURE__*/React.createElement("i", {
        className: 'pi ' + st.ic,
        style: {
          fontSize: 11
        }
      }), st.label)));
    })))), rows.length === 0 && /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        gap: 8,
        padding: '48px 20px',
        color: 'var(--c-muted)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-clock",
      style: {
        fontSize: 26,
        opacity: 0.4
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 14,
        color: 'var(--c-text2)',
        fontWeight: 500
      }
    }, "\u0417\u0430\u043F\u0438\u0441\u0438 \u043D\u0435 \u043D\u0430\u0439\u0434\u0435\u043D\u044B"))));
  }

  /* ═══════════════ Сброс системы ═══════════════ */
  const S_CATS = [{
    key: 'deals',
    icon: 'pi-briefcase',
    name: 'Сделки',
    desc: 'Все сделки и их история изменений',
    count: '1 248 записей'
  }, {
    key: 'contacts',
    icon: 'pi-user',
    name: 'Контакты',
    desc: 'Контактные лица и их данные',
    count: '3 972 записи'
  }, {
    key: 'companies',
    icon: 'pi-building',
    name: 'Компании',
    desc: 'Организации-клиенты',
    count: '864 записи'
  }, {
    key: 'tasks',
    icon: 'pi-check-square',
    name: 'Задачи и активности',
    desc: 'Задачи, звонки, встречи, заметки',
    count: '5 310 записей'
  }, {
    key: 'docs',
    icon: 'pi-file',
    name: 'Документы и файлы',
    desc: 'Договоры и вложения',
    count: '2 140 файлов'
  }, {
    key: 'finance',
    icon: 'pi-wallet',
    name: 'Финансовые операции',
    desc: 'Платежи, счета, проводки',
    count: '918 записей'
  }, {
    key: 'logs',
    icon: 'pi-history',
    name: 'Журналы и история',
    desc: 'Журнал автоматизаций и аудита',
    count: '42 300 событий'
  }, {
    key: 'automations',
    icon: 'pi-bolt',
    name: 'Правила автоматизаций',
    desc: 'Настроенные сценарии',
    count: '36 правил'
  }, {
    key: 'directories',
    icon: 'pi-folder-open',
    name: 'Справочники',
    desc: 'Товары, теги, поля, курсы валют',
    count: '512 записей'
  }];
  const S_CONFIRM_WORD = 'СБРОСИТЬ';
  function ResetTab() {
    const [sel, setSel] = useState({});
    const [word, setWord] = useState('');
    const [confirm, setConfirm] = useState(false);
    const [done, setDone] = useState(false);
    const selKeys = S_CATS.filter(c => sel[c.key]).map(c => c.key);
    const n = selKeys.length;
    const allOn = n === S_CATS.length;
    const toggle = k => setSel(s => ({
      ...s,
      [k]: !s[k]
    }));
    const toggleAll = () => {
      const v = !allOn;
      const m = {};
      S_CATS.forEach(c => {
        m[c.key] = v;
      });
      setSel(m);
    };
    const canReset = n > 0 && word.trim().toUpperCase() === S_CONFIRM_WORD;
    function doReset() {
      setConfirm(false);
      setDone(true);
      setSel({});
      setWord('');
      setTimeout(() => setDone(false), 3600);
    }
    return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("h2", {
      style: {
        margin: '2px 0 4px',
        fontSize: 22,
        fontWeight: 600
      }
    }, "\u0421\u0431\u0440\u043E\u0441 \u0441\u0438\u0441\u0442\u0435\u043C\u044B"), /*#__PURE__*/React.createElement("p", {
      style: {
        margin: '0 0 20px',
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }, "\u0412\u044B\u0431\u043E\u0440\u043E\u0447\u043D\u043E\u0435 \u0443\u0434\u0430\u043B\u0435\u043D\u0438\u0435 \u0434\u0430\u043D\u043D\u044B\u0445 \u0438\u0437 \u0441\u0438\u0441\u0442\u0435\u043C\u044B. \u041D\u0430\u0441\u0442\u0440\u043E\u0439\u043A\u0438 \u0430\u043A\u043A\u0430\u0443\u043D\u0442\u0430 \u0438 \u0443\u0447\u0451\u0442\u043D\u044B\u0435 \u0437\u0430\u043F\u0438\u0441\u0438 \u0441\u043E\u0445\u0440\u0430\u043D\u044F\u044E\u0442\u0441\u044F."), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 12,
        alignItems: 'flex-start',
        padding: '14px 16px',
        marginBottom: 16,
        background: 'var(--mg-status-danger-bg)',
        border: '1px solid var(--mg-status-danger-border)',
        borderRadius: 'var(--mg-radius-lg)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-exclamation-triangle",
      style: {
        fontSize: 18,
        color: 'var(--mg-status-danger-text)',
        marginTop: 1,
        flexShrink: 0
      }
    }), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 13.5,
        color: 'var(--c-text2)',
        lineHeight: 1.55
      }
    }, /*#__PURE__*/React.createElement("b", {
      style: {
        color: 'var(--mg-status-danger-text)',
        fontWeight: 600
      }
    }, "\u041E\u043F\u0435\u0440\u0430\u0446\u0438\u044F \u043D\u0435\u043E\u0431\u0440\u0430\u0442\u0438\u043C\u0430."), " \u0412\u044B\u0431\u0440\u0430\u043D\u043D\u044B\u0435 \u0434\u0430\u043D\u043D\u044B\u0435 \u0431\u0443\u0434\u0443\u0442 \u0443\u0434\u0430\u043B\u0435\u043D\u044B \u0431\u0435\u0437\u0432\u043E\u0437\u0432\u0440\u0430\u0442\u043D\u043E. \u041F\u0435\u0440\u0435\u0434 \u0441\u0431\u0440\u043E\u0441\u043E\u043C \u0440\u0435\u043A\u043E\u043C\u0435\u043D\u0434\u0443\u0435\u043C \u0432\u044B\u0433\u0440\u0443\u0437\u0438\u0442\u044C \u0440\u0435\u0437\u0435\u0440\u0432\u043D\u0443\u044E \u043A\u043E\u043F\u0438\u044E.")), /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-sm)',
        overflow: 'hidden',
        marginBottom: 16
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: toggleAll,
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 12,
        padding: '13px 16px',
        borderBottom: '1px solid var(--c-border)',
        background: 'var(--c-hover)',
        cursor: 'pointer'
      }
    }, /*#__PURE__*/React.createElement(SCheck, {
      checked: allOn,
      indeterminate: n > 0 && !allOn,
      onChange: toggleAll
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 13.5,
        fontWeight: 600
      }
    }, "\u0412\u044B\u0431\u0440\u0430\u0442\u044C \u0432\u0441\u0451"), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 12.5,
        color: 'var(--c-muted)',
        whiteSpace: 'nowrap',
        flexShrink: 0
      }
    }, "\u0412\u044B\u0431\u0440\u0430\u043D\u043E: ", n, " \u0438\u0437 ", S_CATS.length)), S_CATS.map((c, i) => {
      const on = !!sel[c.key];
      return /*#__PURE__*/React.createElement("div", {
        key: c.key,
        onClick: () => toggle(c.key),
        style: {
          display: 'flex',
          alignItems: 'center',
          gap: 13,
          padding: '13px 16px',
          borderBottom: i < S_CATS.length - 1 ? '1px solid var(--c-border)' : 'none',
          cursor: 'pointer',
          background: on ? 'var(--mg-primary-100)' : 'transparent',
          transition: 'background .12s'
        }
      }, /*#__PURE__*/React.createElement(SCheck, {
        checked: on,
        onChange: () => toggle(c.key)
      }), /*#__PURE__*/React.createElement("span", {
        style: {
          width: 36,
          height: 36,
          borderRadius: 'var(--mg-radius-md)',
          background: 'var(--c-muted2)',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          flexShrink: 0
        }
      }, /*#__PURE__*/React.createElement("i", {
        className: 'pi ' + c.icon,
        style: {
          fontSize: 15,
          color: 'var(--c-text2)'
        }
      })), /*#__PURE__*/React.createElement("div", {
        style: {
          flex: 1,
          minWidth: 0
        }
      }, /*#__PURE__*/React.createElement("div", {
        style: {
          fontSize: 14,
          fontWeight: 600,
          color: 'var(--c-text)'
        }
      }, c.name), /*#__PURE__*/React.createElement("div", {
        style: {
          fontSize: 12.5,
          color: 'var(--c-muted)',
          marginTop: 2
        }
      }, c.desc)), /*#__PURE__*/React.createElement("span", {
        style: {
          fontSize: 12,
          fontWeight: 600,
          padding: '3px 10px',
          borderRadius: 999,
          background: 'var(--c-muted2)',
          color: 'var(--c-text2)',
          whiteSpace: 'nowrap'
        }
      }, c.count));
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-sm)',
        padding: '18px 20px'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 14,
        fontWeight: 600,
        marginBottom: 4
      }
    }, "\u041F\u043E\u0434\u0442\u0432\u0435\u0440\u0436\u0434\u0435\u043D\u0438\u0435"), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 13,
        color: 'var(--c-muted)',
        marginBottom: 12
      }
    }, "\u0427\u0442\u043E\u0431\u044B \u0443\u0434\u0430\u043B\u0438\u0442\u044C ", n > 0 ? 'выбранные данные (' + n + ')' : 'выбранные данные', ", \u0432\u0432\u0435\u0434\u0438\u0442\u0435 ", /*#__PURE__*/React.createElement("b", {
      style: {
        color: 'var(--c-text2)',
        fontFamily: 'ui-monospace,monospace'
      }
    }, S_CONFIRM_WORD), " \u0432 \u043F\u043E\u043B\u0435 \u043D\u0438\u0436\u0435."), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 12,
        alignItems: 'center',
        flexWrap: 'wrap'
      }
    }, /*#__PURE__*/React.createElement("input", {
      className: "inp",
      value: word,
      onChange: e => setWord(e.target.value),
      placeholder: S_CONFIRM_WORD,
      style: {
        flex: 1,
        minWidth: 200,
        maxWidth: 280,
        height: 40,
        padding: '0 13px',
        borderRadius: 'var(--mg-radius-md)',
        border: '1px solid ' + (word && !canReset && n > 0 ? 'var(--mg-status-danger-border)' : 'var(--mg-input-border)'),
        background: 'var(--c-card)',
        color: 'var(--c-text)',
        fontFamily: 'ui-monospace,monospace',
        fontSize: 14,
        letterSpacing: '0.05em'
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1
      }
    }), /*#__PURE__*/React.createElement("button", {
      disabled: !canReset,
      onClick: () => setConfirm(true),
      style: {
        ...sBtn('danger'),
        opacity: canReset ? 1 : 0.45,
        pointerEvents: canReset ? 'auto' : 'none'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-trash",
      style: {
        fontSize: 13
      }
    }), "\u0423\u0434\u0430\u043B\u0438\u0442\u044C \u0432\u044B\u0431\u0440\u0430\u043D\u043D\u043E\u0435", n > 0 ? ' (' + n + ')' : ''))), confirm && /*#__PURE__*/React.createElement("div", {
      onClick: () => setConfirm(false),
      style: {
        position: 'fixed',
        inset: 0,
        zIndex: 1500,
        background: 'rgba(9,16,32,0.55)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: 20
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: e => e.stopPropagation(),
      style: {
        width: 460,
        maxWidth: '92vw',
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-lg)'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '16px 20px',
        borderBottom: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 16,
        fontWeight: 600
      }
    }, "\u0423\u0434\u0430\u043B\u0438\u0442\u044C \u0434\u0430\u043D\u043D\u044B\u0435?"), /*#__PURE__*/React.createElement("i", {
      className: "pi pi-times",
      onClick: () => setConfirm(false),
      style: {
        fontSize: 14,
        color: 'var(--c-muted)',
        cursor: 'pointer'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        padding: 20,
        display: 'flex',
        gap: 14,
        alignItems: 'flex-start'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        width: 42,
        height: 42,
        borderRadius: '50%',
        background: 'var(--mg-status-danger-bg)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        flexShrink: 0
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-exclamation-triangle",
      style: {
        fontSize: 18,
        color: 'var(--mg-status-danger-text)'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 14,
        color: 'var(--c-text2)',
        lineHeight: 1.55,
        paddingTop: 2
      }
    }, "\u0411\u0443\u0434\u0435\u0442 \u0431\u0435\u0437\u0432\u043E\u0437\u0432\u0440\u0430\u0442\u043D\u043E \u0443\u0434\u0430\u043B\u0435\u043D\u043E ", /*#__PURE__*/React.createElement("b", {
      style: {
        color: 'var(--c-text)'
      }
    }, n), " ", n === 1 ? 'категория' : n < 5 ? 'категории' : 'категорий', " \u0434\u0430\u043D\u043D\u044B\u0445: ", selKeys.map(k => (S_CATS.find(c => c.key === k) || {}).name).join(', '), ". \u041F\u0440\u043E\u0434\u043E\u043B\u0436\u0438\u0442\u044C?")), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        justifyContent: 'flex-end',
        gap: 10,
        padding: '14px 20px',
        borderTop: '1px solid var(--c-border)'
      }
    }, /*#__PURE__*/React.createElement("button", {
      style: sBtn('ghost'),
      onClick: () => setConfirm(false)
    }, "\u041E\u0442\u043C\u0435\u043D\u0430"), /*#__PURE__*/React.createElement("button", {
      style: sBtn('danger'),
      onClick: doReset
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-trash",
      style: {
        fontSize: 13
      }
    }), "\u0423\u0434\u0430\u043B\u0438\u0442\u044C \u0431\u0435\u0437\u0432\u043E\u0437\u0432\u0440\u0430\u0442\u043D\u043E")))), done && /*#__PURE__*/React.createElement("div", {
      style: {
        position: 'fixed',
        insetBlockEnd: 20,
        insetInlineEnd: 20,
        zIndex: 2000,
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        minWidth: 260,
        padding: '12px 14px',
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderInlineStart: '3px solid var(--mg-status-success-text)',
        borderRadius: 'var(--mg-radius-md)',
        boxShadow: 'var(--mg-shadow-lg)',
        animation: 'toastIn .2s ease'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-check-circle",
      style: {
        fontSize: 16,
        color: 'var(--mg-status-success-text)'
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 13.5,
        fontWeight: 500
      }
    }, "\u0412\u044B\u0431\u0440\u0430\u043D\u043D\u044B\u0435 \u0434\u0430\u043D\u043D\u044B\u0435 \u0443\u0434\u0430\u043B\u0435\u043D\u044B")));
  }
  window.AutomationsTab = AutomationsTab;
  window.ResetTab = ResetTab;
})();
})(); } catch (e) { __ds_ns.__errors.push({ path: "redesign/system-section.jsx", error: String((e && e.message) || e) }); }

// redesign/tweaks-panel.jsx
try { (() => {
// @ds-adherence-ignore -- omelette starter scaffold (raw elements/hex/px by design)

/* BEGIN USAGE */
// tweaks-panel.jsx
// Reusable Tweaks shell + form-control helpers.
// Exports (to window): useTweaks, TweaksPanel, TweakSection, TweakRow, TweakSlider,
//   TweakToggle, TweakRadio, TweakSelect, TweakText, TweakNumber, TweakColor, TweakButton.
//
// Owns the host protocol (listens for __activate_edit_mode / __deactivate_edit_mode,
// posts __edit_mode_available / __edit_mode_set_keys / __edit_mode_dismissed) so
// individual prototypes don't re-roll it. Ships a consistent set of controls so you
// don't hand-draw <input type="range">, segmented radios, steppers, etc.
//
// Usage (in an HTML file that loads React + Babel):
//
//   const TWEAK_DEFAULTS = /*EDITMODE-BEGIN*/{
//     "primaryColor": "#D97757",
//     "palette": ["#D97757", "#29261b", "#f6f4ef"],
//     "fontSize": 16,
//     "density": "regular",
//     "dark": false
//   }/*EDITMODE-END*/;
//
//   function App() {
//     const [t, setTweak] = useTweaks(TWEAK_DEFAULTS);
//     return (
//       <div style={{ fontSize: t.fontSize, color: t.primaryColor }}>
//         Hello
//         <TweaksPanel>
//           <TweakSection label="Typography" />
//           <TweakSlider label="Font size" value={t.fontSize} min={10} max={32} unit="px"
//                        onChange={(v) => setTweak('fontSize', v)} />
//           <TweakRadio  label="Density" value={t.density}
//                        options={['compact', 'regular', 'comfy']}
//                        onChange={(v) => setTweak('density', v)} />
//           <TweakSection label="Theme" />
//           <TweakColor  label="Primary" value={t.primaryColor}
//                        options={['#D97757', '#2A6FDB', '#1F8A5B', '#7A5AE0']}
//                        onChange={(v) => setTweak('primaryColor', v)} />
//           <TweakColor  label="Palette" value={t.palette}
//                        options={[['#D97757', '#29261b', '#f6f4ef'],
//                                  ['#475569', '#0f172a', '#f1f5f9']]}
//                        onChange={(v) => setTweak('palette', v)} />
//           <TweakToggle label="Dark mode" value={t.dark}
//                        onChange={(v) => setTweak('dark', v)} />
//         </TweaksPanel>
//       </div>
//     );
//   }
//
// TweakRadio is the segmented control for 2–3 short options (auto-falls-back to
// TweakSelect past ~16/~10 chars per label); reach for TweakSelect directly when
// options are many or long. For color tweaks always curate 3-4 options rather than
// a free picker; an option can also be a whole 2–5 color palette (the stored value
// is the array). The Tweak* controls are a floor, not a ceiling — build custom
// controls inside the panel if a tweak calls for UI they don't cover.
/* END USAGE */
// ─────────────────────────────────────────────────────────────────────────────

const __TWEAKS_STYLE = `
  .twk-panel{position:fixed;right:16px;bottom:16px;z-index:2147483646;width:280px;
    max-height:calc(100vh - 32px);display:flex;flex-direction:column;
    transform:scale(var(--dc-inv-zoom,1));transform-origin:bottom right;
    background:rgba(250,249,247,.78);color:#29261b;
    -webkit-backdrop-filter:blur(24px) saturate(160%);backdrop-filter:blur(24px) saturate(160%);
    border:.5px solid rgba(255,255,255,.6);border-radius:14px;
    box-shadow:0 1px 0 rgba(255,255,255,.5) inset,0 12px 40px rgba(0,0,0,.18);
    font:11.5px/1.4 ui-sans-serif,system-ui,-apple-system,sans-serif;overflow:hidden}
  .twk-hd{display:flex;align-items:center;justify-content:space-between;
    padding:10px 8px 10px 14px;cursor:move;user-select:none}
  .twk-hd b{font-size:12px;font-weight:600;letter-spacing:.01em}
  .twk-x{appearance:none;border:0;background:transparent;color:rgba(41,38,27,.55);
    width:22px;height:22px;border-radius:6px;cursor:default;font-size:13px;line-height:1}
  .twk-x:hover{background:rgba(0,0,0,.06);color:#29261b}
  .twk-body{padding:2px 14px 14px;display:flex;flex-direction:column;gap:10px;
    overflow-y:auto;overflow-x:hidden;min-height:0;
    scrollbar-width:thin;scrollbar-color:rgba(0,0,0,.15) transparent}
  .twk-body::-webkit-scrollbar{width:8px}
  .twk-body::-webkit-scrollbar-track{background:transparent;margin:2px}
  .twk-body::-webkit-scrollbar-thumb{background:rgba(0,0,0,.15);border-radius:4px;
    border:2px solid transparent;background-clip:content-box}
  .twk-body::-webkit-scrollbar-thumb:hover{background:rgba(0,0,0,.25);
    border:2px solid transparent;background-clip:content-box}
  .twk-row{display:flex;flex-direction:column;gap:5px}
  .twk-row-h{flex-direction:row;align-items:center;justify-content:space-between;gap:10px}
  .twk-lbl{display:flex;justify-content:space-between;align-items:baseline;
    color:rgba(41,38,27,.72)}
  .twk-lbl>span:first-child{font-weight:500}
  .twk-val{color:rgba(41,38,27,.5);font-variant-numeric:tabular-nums}

  .twk-sect{font-size:10px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;
    color:rgba(41,38,27,.45);padding:10px 0 0}
  .twk-sect:first-child{padding-top:0}

  .twk-field{appearance:none;box-sizing:border-box;width:100%;min-width:0;height:26px;padding:0 8px;
    border:.5px solid rgba(0,0,0,.1);border-radius:7px;
    background:rgba(255,255,255,.6);color:inherit;font:inherit;outline:none}
  .twk-field:focus{border-color:rgba(0,0,0,.25);background:rgba(255,255,255,.85)}
  select.twk-field{padding-right:22px;
    background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'><path fill='rgba(0,0,0,.5)' d='M0 0h10L5 6z'/></svg>");
    background-repeat:no-repeat;background-position:right 8px center}

  .twk-slider{appearance:none;-webkit-appearance:none;width:100%;height:4px;margin:6px 0;
    border-radius:999px;background:rgba(0,0,0,.12);outline:none}
  .twk-slider::-webkit-slider-thumb{-webkit-appearance:none;appearance:none;
    width:14px;height:14px;border-radius:50%;background:#fff;
    border:.5px solid rgba(0,0,0,.12);box-shadow:0 1px 3px rgba(0,0,0,.2);cursor:default}
  .twk-slider::-moz-range-thumb{width:14px;height:14px;border-radius:50%;
    background:#fff;border:.5px solid rgba(0,0,0,.12);box-shadow:0 1px 3px rgba(0,0,0,.2);cursor:default}

  .twk-seg{position:relative;display:flex;padding:2px;border-radius:8px;
    background:rgba(0,0,0,.06);user-select:none}
  .twk-seg-thumb{position:absolute;top:2px;bottom:2px;border-radius:6px;
    background:rgba(255,255,255,.9);box-shadow:0 1px 2px rgba(0,0,0,.12);
    transition:left .15s cubic-bezier(.3,.7,.4,1),width .15s}
  .twk-seg.dragging .twk-seg-thumb{transition:none}
  .twk-seg button{appearance:none;position:relative;z-index:1;flex:1;border:0;
    background:transparent;color:inherit;font:inherit;font-weight:500;min-height:22px;
    border-radius:6px;cursor:default;padding:4px 6px;line-height:1.2;
    overflow-wrap:anywhere}

  .twk-toggle{position:relative;width:32px;height:18px;border:0;border-radius:999px;
    background:rgba(0,0,0,.15);transition:background .15s;cursor:default;padding:0}
  .twk-toggle[data-on="1"]{background:#34c759}
  .twk-toggle i{position:absolute;top:2px;left:2px;width:14px;height:14px;border-radius:50%;
    background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.25);transition:transform .15s}
  .twk-toggle[data-on="1"] i{transform:translateX(14px)}

  .twk-num{display:flex;align-items:center;box-sizing:border-box;min-width:0;height:26px;padding:0 0 0 8px;
    border:.5px solid rgba(0,0,0,.1);border-radius:7px;background:rgba(255,255,255,.6)}
  .twk-num-lbl{font-weight:500;color:rgba(41,38,27,.6);cursor:ew-resize;
    user-select:none;padding-right:8px}
  .twk-num input{flex:1;min-width:0;height:100%;border:0;background:transparent;
    font:inherit;font-variant-numeric:tabular-nums;text-align:right;padding:0 8px 0 0;
    outline:none;color:inherit;-moz-appearance:textfield}
  .twk-num input::-webkit-inner-spin-button,.twk-num input::-webkit-outer-spin-button{
    -webkit-appearance:none;margin:0}
  .twk-num-unit{padding-right:8px;color:rgba(41,38,27,.45)}

  .twk-btn{appearance:none;height:26px;padding:0 12px;border:0;border-radius:7px;
    background:rgba(0,0,0,.78);color:#fff;font:inherit;font-weight:500;cursor:default}
  .twk-btn:hover{background:rgba(0,0,0,.88)}
  .twk-btn.secondary{background:rgba(0,0,0,.06);color:inherit}
  .twk-btn.secondary:hover{background:rgba(0,0,0,.1)}

  .twk-swatch{appearance:none;-webkit-appearance:none;width:56px;height:22px;
    border:.5px solid rgba(0,0,0,.1);border-radius:6px;padding:0;cursor:default;
    background:transparent;flex-shrink:0}
  .twk-swatch::-webkit-color-swatch-wrapper{padding:0}
  .twk-swatch::-webkit-color-swatch{border:0;border-radius:5.5px}
  .twk-swatch::-moz-color-swatch{border:0;border-radius:5.5px}

  .twk-chips{display:flex;gap:6px}
  .twk-chip{position:relative;appearance:none;flex:1;min-width:0;height:46px;
    padding:0;border:0;border-radius:6px;overflow:hidden;cursor:default;
    box-shadow:0 0 0 .5px rgba(0,0,0,.12),0 1px 2px rgba(0,0,0,.06);
    transition:transform .12s cubic-bezier(.3,.7,.4,1),box-shadow .12s}
  .twk-chip:hover{transform:translateY(-1px);
    box-shadow:0 0 0 .5px rgba(0,0,0,.18),0 4px 10px rgba(0,0,0,.12)}
  .twk-chip[data-on="1"]{box-shadow:0 0 0 1.5px rgba(0,0,0,.85),
    0 2px 6px rgba(0,0,0,.15)}
  .twk-chip>span{position:absolute;top:0;bottom:0;right:0;width:34%;
    display:flex;flex-direction:column;box-shadow:-1px 0 0 rgba(0,0,0,.1)}
  .twk-chip>span>i{flex:1;box-shadow:0 -1px 0 rgba(0,0,0,.1)}
  .twk-chip>span>i:first-child{box-shadow:none}
  .twk-chip svg{position:absolute;top:6px;left:6px;width:13px;height:13px;
    filter:drop-shadow(0 1px 1px rgba(0,0,0,.3))}
`;

// ── useTweaks ───────────────────────────────────────────────────────────────
// Single source of truth for tweak values. setTweak persists via the host
// (__edit_mode_set_keys → host rewrites the EDITMODE block on disk).
function useTweaks(defaults) {
  const [values, setValues] = React.useState(defaults);
  // Accepts either setTweak('key', value) or setTweak({ key: value, ... }) so a
  // useState-style call doesn't write a "[object Object]" key into the persisted
  // JSON block.
  const setTweak = React.useCallback((keyOrEdits, val) => {
    const edits = typeof keyOrEdits === 'object' && keyOrEdits !== null ? keyOrEdits : {
      [keyOrEdits]: val
    };
    setValues(prev => ({
      ...prev,
      ...edits
    }));
    window.parent.postMessage({
      type: '__edit_mode_set_keys',
      edits
    }, '*');
    // Same-window signal so in-page listeners (deck-stage rail thumbnails)
    // can react — the parent message only reaches the host, not peers.
    window.dispatchEvent(new CustomEvent('tweakchange', {
      detail: edits
    }));
  }, []);
  return [values, setTweak];
}

// ── TweaksPanel ─────────────────────────────────────────────────────────────
// Floating shell. Registers the protocol listener BEFORE announcing
// availability — if the announce ran first, the host's activate could land
// before our handler exists and the toolbar toggle would silently no-op.
// The close button posts __edit_mode_dismissed so the host's toolbar toggle
// flips off in lockstep; the host echoes __deactivate_edit_mode back which
// is what actually hides the panel.
function TweaksPanel({
  title = 'Tweaks',
  children
}) {
  const [open, setOpen] = React.useState(false);
  const dragRef = React.useRef(null);
  const offsetRef = React.useRef({
    x: 16,
    y: 16
  });
  const PAD = 16;
  const clampToViewport = React.useCallback(() => {
    const panel = dragRef.current;
    if (!panel) return;
    const w = panel.offsetWidth,
      h = panel.offsetHeight;
    const maxRight = Math.max(PAD, window.innerWidth - w - PAD);
    const maxBottom = Math.max(PAD, window.innerHeight - h - PAD);
    offsetRef.current = {
      x: Math.min(maxRight, Math.max(PAD, offsetRef.current.x)),
      y: Math.min(maxBottom, Math.max(PAD, offsetRef.current.y))
    };
    panel.style.right = offsetRef.current.x + 'px';
    panel.style.bottom = offsetRef.current.y + 'px';
  }, []);
  React.useEffect(() => {
    if (!open) return;
    clampToViewport();
    if (typeof ResizeObserver === 'undefined') {
      window.addEventListener('resize', clampToViewport);
      return () => window.removeEventListener('resize', clampToViewport);
    }
    const ro = new ResizeObserver(clampToViewport);
    ro.observe(document.documentElement);
    return () => ro.disconnect();
  }, [open, clampToViewport]);
  React.useEffect(() => {
    const onMsg = e => {
      const t = e?.data?.type;
      if (t === '__activate_edit_mode') setOpen(true);else if (t === '__deactivate_edit_mode') setOpen(false);
    };
    window.addEventListener('message', onMsg);
    window.parent.postMessage({
      type: '__edit_mode_available'
    }, '*');
    return () => window.removeEventListener('message', onMsg);
  }, []);
  const dismiss = () => {
    setOpen(false);
    window.parent.postMessage({
      type: '__edit_mode_dismissed'
    }, '*');
  };
  const onDragStart = e => {
    const panel = dragRef.current;
    if (!panel) return;
    const r = panel.getBoundingClientRect();
    const sx = e.clientX,
      sy = e.clientY;
    const startRight = window.innerWidth - r.right;
    const startBottom = window.innerHeight - r.bottom;
    const move = ev => {
      offsetRef.current = {
        x: startRight - (ev.clientX - sx),
        y: startBottom - (ev.clientY - sy)
      };
      clampToViewport();
    };
    const up = () => {
      window.removeEventListener('mousemove', move);
      window.removeEventListener('mouseup', up);
    };
    window.addEventListener('mousemove', move);
    window.addEventListener('mouseup', up);
  };
  if (!open) return null;
  return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("style", null, __TWEAKS_STYLE), /*#__PURE__*/React.createElement("div", {
    ref: dragRef,
    className: "twk-panel",
    "data-omelette-chrome": "",
    style: {
      right: offsetRef.current.x,
      bottom: offsetRef.current.y
    }
  }, /*#__PURE__*/React.createElement("div", {
    className: "twk-hd",
    onMouseDown: onDragStart
  }, /*#__PURE__*/React.createElement("b", null, title), /*#__PURE__*/React.createElement("button", {
    className: "twk-x",
    "aria-label": "Close tweaks",
    onMouseDown: e => e.stopPropagation(),
    onClick: dismiss
  }, "\u2715")), /*#__PURE__*/React.createElement("div", {
    className: "twk-body"
  }, children)));
}

// ── Layout helpers ──────────────────────────────────────────────────────────

function TweakSection({
  label,
  children
}) {
  return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("div", {
    className: "twk-sect"
  }, label), children);
}
function TweakRow({
  label,
  value,
  children,
  inline = false
}) {
  return /*#__PURE__*/React.createElement("div", {
    className: inline ? 'twk-row twk-row-h' : 'twk-row'
  }, /*#__PURE__*/React.createElement("div", {
    className: "twk-lbl"
  }, /*#__PURE__*/React.createElement("span", null, label), value != null && /*#__PURE__*/React.createElement("span", {
    className: "twk-val"
  }, value)), children);
}

// ── Controls ────────────────────────────────────────────────────────────────

function TweakSlider({
  label,
  value,
  min = 0,
  max = 100,
  step = 1,
  unit = '',
  onChange
}) {
  return /*#__PURE__*/React.createElement(TweakRow, {
    label: label,
    value: `${value}${unit}`
  }, /*#__PURE__*/React.createElement("input", {
    type: "range",
    className: "twk-slider",
    min: min,
    max: max,
    step: step,
    value: value,
    onChange: e => onChange(Number(e.target.value))
  }));
}
function TweakToggle({
  label,
  value,
  onChange
}) {
  return /*#__PURE__*/React.createElement("div", {
    className: "twk-row twk-row-h"
  }, /*#__PURE__*/React.createElement("div", {
    className: "twk-lbl"
  }, /*#__PURE__*/React.createElement("span", null, label)), /*#__PURE__*/React.createElement("button", {
    type: "button",
    className: "twk-toggle",
    "data-on": value ? '1' : '0',
    role: "switch",
    "aria-checked": !!value,
    onClick: () => onChange(!value)
  }, /*#__PURE__*/React.createElement("i", null)));
}
function TweakRadio({
  label,
  value,
  options,
  onChange
}) {
  const trackRef = React.useRef(null);
  const [dragging, setDragging] = React.useState(false);
  // The active value is read by pointer-move handlers attached for the lifetime
  // of a drag — ref it so a stale closure doesn't fire onChange for every move.
  const valueRef = React.useRef(value);
  valueRef.current = value;

  // Segments wrap mid-word once per-segment width runs out. The track is
  // ~248px (280 panel − 28 body pad − 4 seg pad), each button loses 12px
  // to its own padding, and 11.5px system-ui averages ~6.3px/char — so 2
  // options fit ~16 chars each, 3 fit ~10. Past that (or >3 options), fall
  // back to a dropdown rather than wrap.
  const labelLen = o => String(typeof o === 'object' ? o.label : o).length;
  const maxLen = options.reduce((m, o) => Math.max(m, labelLen(o)), 0);
  const fitsAsSegments = maxLen <= ({
    2: 16,
    3: 10
  }[options.length] ?? 0);
  if (!fitsAsSegments) {
    // <select> emits strings — map back to the original option value so the
    // fallback stays type-preserving (numbers, booleans) like the segment path.
    const resolve = s => {
      const m = options.find(o => String(typeof o === 'object' ? o.value : o) === s);
      return m === undefined ? s : typeof m === 'object' ? m.value : m;
    };
    return /*#__PURE__*/React.createElement(TweakSelect, {
      label: label,
      value: value,
      options: options,
      onChange: s => onChange(resolve(s))
    });
  }
  const opts = options.map(o => typeof o === 'object' ? o : {
    value: o,
    label: o
  });
  const idx = Math.max(0, opts.findIndex(o => o.value === value));
  const n = opts.length;
  const segAt = clientX => {
    const r = trackRef.current.getBoundingClientRect();
    const inner = r.width - 4;
    const i = Math.floor((clientX - r.left - 2) / inner * n);
    return opts[Math.max(0, Math.min(n - 1, i))].value;
  };
  const onPointerDown = e => {
    setDragging(true);
    const v0 = segAt(e.clientX);
    if (v0 !== valueRef.current) onChange(v0);
    const move = ev => {
      if (!trackRef.current) return;
      const v = segAt(ev.clientX);
      if (v !== valueRef.current) onChange(v);
    };
    const up = () => {
      setDragging(false);
      window.removeEventListener('pointermove', move);
      window.removeEventListener('pointerup', up);
    };
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', up);
  };
  return /*#__PURE__*/React.createElement(TweakRow, {
    label: label
  }, /*#__PURE__*/React.createElement("div", {
    ref: trackRef,
    role: "radiogroup",
    onPointerDown: onPointerDown,
    className: dragging ? 'twk-seg dragging' : 'twk-seg'
  }, /*#__PURE__*/React.createElement("div", {
    className: "twk-seg-thumb",
    style: {
      left: `calc(2px + ${idx} * (100% - 4px) / ${n})`,
      width: `calc((100% - 4px) / ${n})`
    }
  }), opts.map(o => /*#__PURE__*/React.createElement("button", {
    key: o.value,
    type: "button",
    role: "radio",
    "aria-checked": o.value === value
  }, o.label))));
}
function TweakSelect({
  label,
  value,
  options,
  onChange
}) {
  return /*#__PURE__*/React.createElement(TweakRow, {
    label: label
  }, /*#__PURE__*/React.createElement("select", {
    className: "twk-field",
    value: value,
    onChange: e => onChange(e.target.value)
  }, options.map(o => {
    const v = typeof o === 'object' ? o.value : o;
    const l = typeof o === 'object' ? o.label : o;
    return /*#__PURE__*/React.createElement("option", {
      key: v,
      value: v
    }, l);
  })));
}
function TweakText({
  label,
  value,
  placeholder,
  onChange
}) {
  return /*#__PURE__*/React.createElement(TweakRow, {
    label: label
  }, /*#__PURE__*/React.createElement("input", {
    className: "twk-field",
    type: "text",
    value: value,
    placeholder: placeholder,
    onChange: e => onChange(e.target.value)
  }));
}
function TweakNumber({
  label,
  value,
  min,
  max,
  step = 1,
  unit = '',
  onChange
}) {
  const clamp = n => {
    if (min != null && n < min) return min;
    if (max != null && n > max) return max;
    return n;
  };
  const startRef = React.useRef({
    x: 0,
    val: 0
  });
  const onScrubStart = e => {
    e.preventDefault();
    startRef.current = {
      x: e.clientX,
      val: value
    };
    const decimals = (String(step).split('.')[1] || '').length;
    const move = ev => {
      const dx = ev.clientX - startRef.current.x;
      const raw = startRef.current.val + dx * step;
      const snapped = Math.round(raw / step) * step;
      onChange(clamp(Number(snapped.toFixed(decimals))));
    };
    const up = () => {
      window.removeEventListener('pointermove', move);
      window.removeEventListener('pointerup', up);
    };
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', up);
  };
  return /*#__PURE__*/React.createElement("div", {
    className: "twk-num"
  }, /*#__PURE__*/React.createElement("span", {
    className: "twk-num-lbl",
    onPointerDown: onScrubStart
  }, label), /*#__PURE__*/React.createElement("input", {
    type: "number",
    value: value,
    min: min,
    max: max,
    step: step,
    onChange: e => onChange(clamp(Number(e.target.value)))
  }), unit && /*#__PURE__*/React.createElement("span", {
    className: "twk-num-unit"
  }, unit));
}

// Relative-luminance contrast pick — checkmarks drawn over a swatch need to
// read on both #111 and #fafafa without per-option configuration. Hex input
// only (#rgb / #rrggbb); named or rgb()/hsl() colors fall through to "light".
function __twkIsLight(hex) {
  const h = String(hex).replace('#', '');
  const x = h.length === 3 ? h.replace(/./g, c => c + c) : h.padEnd(6, '0');
  const n = parseInt(x.slice(0, 6), 16);
  if (Number.isNaN(n)) return true;
  const r = n >> 16 & 255,
    g = n >> 8 & 255,
    b = n & 255;
  return r * 299 + g * 587 + b * 114 > 148000;
}
const __TwkCheck = ({
  light
}) => /*#__PURE__*/React.createElement("svg", {
  viewBox: "0 0 14 14",
  "aria-hidden": "true"
}, /*#__PURE__*/React.createElement("path", {
  d: "M3 7.2 5.8 10 11 4.2",
  fill: "none",
  strokeWidth: "2.2",
  strokeLinecap: "round",
  strokeLinejoin: "round",
  stroke: light ? 'rgba(0,0,0,.78)' : '#fff'
}));

// TweakColor — curated color/palette picker. Each option is either a single
// hex string or an array of 1-5 hex strings; the card adapts — a lone color
// renders solid, a palette renders colors[0] as the hero (left ~2/3) with the
// rest stacked in a sharp column on the right. onChange emits the
// option in the shape it was passed (string stays string, array stays array).
// Without options it falls back to the native color input for back-compat.
function TweakColor({
  label,
  value,
  options,
  onChange
}) {
  if (!options || !options.length) {
    return /*#__PURE__*/React.createElement("div", {
      className: "twk-row twk-row-h"
    }, /*#__PURE__*/React.createElement("div", {
      className: "twk-lbl"
    }, /*#__PURE__*/React.createElement("span", null, label)), /*#__PURE__*/React.createElement("input", {
      type: "color",
      className: "twk-swatch",
      value: value,
      onChange: e => onChange(e.target.value)
    }));
  }
  // Native <input type=color> emits lowercase hex per the HTML spec, so
  // compare case-insensitively. String() guards JSON.stringify(undefined),
  // which returns the primitive undefined (no .toLowerCase).
  const key = o => String(JSON.stringify(o)).toLowerCase();
  const cur = key(value);
  return /*#__PURE__*/React.createElement(TweakRow, {
    label: label
  }, /*#__PURE__*/React.createElement("div", {
    className: "twk-chips",
    role: "radiogroup"
  }, options.map((o, i) => {
    const colors = Array.isArray(o) ? o : [o];
    const [hero, ...rest] = colors;
    const sup = rest.slice(0, 4);
    const on = key(o) === cur;
    return /*#__PURE__*/React.createElement("button", {
      key: i,
      type: "button",
      className: "twk-chip",
      role: "radio",
      "aria-checked": on,
      "data-on": on ? '1' : '0',
      "aria-label": colors.join(', '),
      title: colors.join(' · '),
      style: {
        background: hero
      },
      onClick: () => onChange(o)
    }, sup.length > 0 && /*#__PURE__*/React.createElement("span", null, sup.map((c, j) => /*#__PURE__*/React.createElement("i", {
      key: j,
      style: {
        background: c
      }
    }))), on && /*#__PURE__*/React.createElement(__TwkCheck, {
      light: __twkIsLight(hero)
    }));
  })));
}
function TweakButton({
  label,
  onClick,
  secondary = false
}) {
  return /*#__PURE__*/React.createElement("button", {
    type: "button",
    className: secondary ? 'twk-btn secondary' : 'twk-btn',
    onClick: onClick
  }, label);
}
Object.assign(window, {
  useTweaks,
  TweaksPanel,
  TweakSection,
  TweakRow,
  TweakSlider,
  TweakToggle,
  TweakRadio,
  TweakSelect,
  TweakText,
  TweakNumber,
  TweakColor,
  TweakButton
});
})(); } catch (e) { __ds_ns.__errors.push({ path: "redesign/tweaks-panel.jsx", error: String((e && e.message) || e) }); }

// redesign/users-section.jsx
try { (() => {
/* Users section for Настройки → Система → Пользователи
   Mirrors front/src/pages/UsersPage (index.vue + composable + dialogs, ru.json),
   redesigned to the fresh MACRO CRM look. Exposes window.UsersTab. */
(function () {
  const {
    useState,
    useRef,
    useEffect
  } = React;
  const U_T = {
    title: 'Пользователи',
    subtitle: 'Управление учётными записями сотрудников',
    addUser: 'Добавить пользователя',
    editUser: 'Редактировать пользователя',
    active: 'Активен',
    inactive: 'Неактивен',
    passwordHint: 'Если не задать пароль, он будет сгенерирован автоматически. Сообщите учётные данные сотруднику.',
    fields: {
      full_name: 'ФИО',
      full_name_ph: 'Иванов Иван Иванович',
      email: 'Email',
      email_ph: 'user@company.com',
      phone: 'Телефон',
      phone_ph: '+7 (999) 000-00-00',
      job_title: 'Должность',
      job_title_ph: 'Менеджер по продажам',
      department: 'Отдел',
      department_ph: 'Выберите отдел',
      manager: 'Руководитель',
      manager_ph: 'Выберите руководителя',
      role: 'Роль',
      role_ph: 'Выберите роль (по умолчанию: Менеджер)',
      password: 'Пароль',
      password_ph: 'Оставьте пустым для автогенерации',
      password_edit_ph: 'Оставьте пустым, чтобы не менять'
    },
    filters: {
      search: 'Поиск по имени, email…',
      role: 'Роль',
      department: 'Отдел',
      status: 'Статус',
      active: 'Активен',
      inactive: 'Неактивен'
    },
    deactivate: 'Деактивировать',
    deactivateTitle: 'Деактивация пользователя',
    deactivateConfirm: n => 'Деактивировать пользователя «' + n + '»? Он не сможет войти в систему, но останется в истории.',
    activate: 'Активировать',
    reset: {
      action: 'Сбросить пароль',
      title: 'Сброс пароля',
      confirm: n => 'Сбросить пароль пользователя «' + n + '»? Текущий пароль перестанет работать, будет сгенерирован новый.',
      resultTitle: 'Новый пароль сгенерирован',
      oneTimeWarning: 'Сохраните пароль — он показывается только один раз. Передайте его пользователю по защищённому каналу.',
      newPassword: 'Новый пароль',
      copy: 'Копировать пароль'
    }
  };
  const U_ROLE_LABEL = {
    admin: 'Администратор',
    director: 'Директор',
    manager: 'Менеджер',
    lawyer: 'Юрист',
    accountant: 'Бухгалтер',
    cfo: 'Финансовый директор'
  };
  const U_ROLE_SEV = {
    admin: 'danger',
    director: 'warn',
    lawyer: 'info',
    cfo: 'warn',
    manager: 'secondary',
    accountant: 'secondary'
  };
  const U_ROLE_OPTS = ['admin', 'director', 'manager', 'lawyer', 'accountant', 'cfo'].map(v => ({
    value: v,
    label: U_ROLE_LABEL[v]
  }));
  const U_ROLE_GRAD = {
    admin: 'linear-gradient(135deg,#4C7DF0,#2B4987)',
    director: 'linear-gradient(135deg,#E6A46E,#C67E3C)',
    cfo: 'linear-gradient(135deg,#E6A46E,#C67E3C)',
    lawyer: 'linear-gradient(135deg,#78B0E0,#4A82BC)',
    manager: 'linear-gradient(135deg,#4FBF8F,#2E9469)',
    accountant: 'linear-gradient(135deg,#9AA3B2,#6C7688)'
  };
  const U_DEPTS = ['Отдел продаж', 'Бухгалтерия', 'Финансы', 'Юридический отдел', 'Руководство'].map((n, i) => ({
    id: i + 1,
    name: n
  }));
  const U_ME = 2;
  const U_USERS = [{
    id: 1,
    full_name: 'Admin',
    email: 'svkv42@gmail.com',
    phone: '+7 (495) 120-33-01',
    job_title: null,
    department_name: null,
    role: 'admin',
    is_active: true
  }, {
    id: 2,
    full_name: 'Bogdan Yadykin',
    email: 'b.yadykin@macroglobaltech.com',
    phone: '+7 (495) 120-33-02',
    job_title: null,
    department_name: null,
    role: 'admin',
    is_active: true
  }, {
    id: 3,
    full_name: 'Lawyer Test',
    email: 'lawyer@mgcrm.test',
    phone: '+7 (495) 120-33-03',
    job_title: null,
    department_name: null,
    role: 'lawyer',
    is_active: true
  }, {
    id: 4,
    full_name: 'MG CRM Admin',
    email: 'admin@mgcrm.test',
    phone: '+7 (495) 120-33-04',
    job_title: null,
    department_name: null,
    role: 'admin',
    is_active: true
  }, {
    id: 5,
    full_name: 'Георгий Некрасов',
    email: 'g.nekrasov@macroglobaltech.com',
    phone: '+7 (701) 555-21-05',
    job_title: 'Менеджер по продажам',
    department_name: 'Отдел продаж',
    role: 'manager',
    is_active: true
  }, {
    id: 6,
    full_name: 'Директор Петров П.П.',
    email: 'director@mgcrm.test',
    phone: '+7 (701) 555-21-06',
    job_title: 'Директор по продажам',
    department_name: 'Отдел продаж',
    role: 'director',
    is_active: true
  }, {
    id: 7,
    full_name: 'Иванов Алексей Сергеевич',
    email: 'manager1@mgcrm.test',
    phone: '+7 (701) 555-21-07',
    job_title: 'Менеджер по продажам',
    department_name: 'Отдел продаж',
    role: 'manager',
    is_active: true
  }, {
    id: 8,
    full_name: 'Илья Рогов',
    email: 'ilyarogov.mera@gmail.com',
    phone: '+7 (701) 555-21-08',
    job_title: 'Менеджер по продажам',
    department_name: 'Отдел продаж',
    role: 'manager',
    is_active: true
  }, {
    id: 9,
    full_name: 'Клим Федорин',
    email: 'k.fedorin@macroglobaltech.com',
    phone: '+7 (701) 555-21-09',
    job_title: 'Менеджер по продажам',
    department_name: 'Отдел продаж',
    role: 'manager',
    is_active: true
  }, {
    id: 10,
    full_name: 'Олеся Моисеева',
    email: 'o.moiseeva@macroglobaltech.com',
    phone: '+7 (701) 555-21-10',
    job_title: 'Менеджер по продажам',
    department_name: 'Отдел продаж',
    role: 'manager',
    is_active: true
  }, {
    id: 11,
    full_name: 'Петрова Мария Сергеевна',
    email: 'manager2@mgcrm.test',
    phone: '+7 (701) 555-21-11',
    job_title: 'Старший менеджер',
    department_name: 'Отдел продаж',
    role: 'manager',
    is_active: true
  }, {
    id: 12,
    full_name: 'Анна Счётова',
    email: 'accountant@mgcrm.test',
    phone: '+7 (701) 340-11-22',
    job_title: 'Главный бухгалтер',
    department_name: 'Бухгалтерия',
    role: 'accountant',
    is_active: true
  }, {
    id: 13,
    full_name: 'Сергей Казначеев',
    email: 'cfo@mgcrm.test',
    phone: '+7 (701) 555-21-13',
    job_title: 'Финансовый директор',
    department_name: 'Финансы',
    role: 'cfo',
    is_active: true
  }, {
    id: 14,
    full_name: 'Роман Уваров',
    email: 'roman.old@mgcrm.test',
    phone: '+7 (701) 555-21-14',
    job_title: 'Менеджер по продажам',
    department_name: 'Отдел продаж',
    role: 'manager',
    is_active: false
  }];
  const U_SEV = {
    success: {
      bg: 'var(--mg-status-success-bg)',
      fg: 'var(--mg-status-success-text)'
    },
    danger: {
      bg: 'var(--mg-status-danger-bg)',
      fg: 'var(--mg-status-danger-text)'
    },
    warn: {
      bg: 'var(--mg-status-warning-bg)',
      fg: 'var(--mg-status-warning-text)'
    },
    info: {
      bg: 'var(--mg-status-info-bg)',
      fg: 'var(--mg-status-info-text)'
    },
    secondary: {
      bg: 'var(--c-muted2)',
      fg: 'var(--c-text2)'
    }
  };
  const uInp = {
    width: '100%',
    height: 40,
    padding: '0 13px',
    borderRadius: 'var(--mg-radius-md)',
    border: '1px solid var(--mg-input-border)',
    background: 'var(--c-card)',
    color: 'var(--c-text)',
    fontFamily: 'var(--mg-font-sans)',
    fontSize: 14,
    boxSizing: 'border-box'
  };
  function uBtn(kind) {
    const base = {
      display: 'inline-flex',
      alignItems: 'center',
      gap: 7,
      height: 38,
      padding: '0 15px',
      borderRadius: 'var(--mg-radius-md)',
      fontFamily: 'var(--mg-font-sans)',
      fontSize: 13,
      fontWeight: 600,
      cursor: 'pointer',
      border: 'none',
      whiteSpace: 'nowrap'
    };
    if (kind === 'primary') return {
      ...base,
      background: 'var(--mg-primary-900)',
      color: '#fff'
    };
    if (kind === 'danger') return {
      ...base,
      background: 'var(--mg-status-danger-solid)',
      color: '#fff'
    };
    if (kind === 'warn') return {
      ...base,
      background: 'var(--mg-status-warning-solid)',
      color: '#3A2A12'
    };
    if (kind === 'text') return {
      ...base,
      background: 'transparent',
      color: 'var(--c-text2)'
    };
    return {
      ...base,
      background: 'transparent',
      color: 'var(--c-text2)',
      border: '1px solid var(--c-border2)'
    };
  }
  function uInitials(name) {
    const w = String(name).trim().split(/\s+/);
    return (w.length >= 2 ? w[0][0] + w[1][0] : w[0].slice(0, 2)).toUpperCase();
  }
  function UTag({
    value,
    severity
  }) {
    const c = U_SEV[severity] || U_SEV.secondary;
    return /*#__PURE__*/React.createElement("span", {
      style: {
        display: 'inline-flex',
        alignItems: 'center',
        fontSize: 11.5,
        fontWeight: 600,
        padding: '3px 9px',
        borderRadius: 6,
        background: c.bg,
        color: c.fg,
        whiteSpace: 'nowrap'
      }
    }, value);
  }
  function UStatus({
    active
  }) {
    const c = active ? U_SEV.success : U_SEV.secondary;
    return /*#__PURE__*/React.createElement("span", {
      style: {
        display: 'inline-flex',
        alignItems: 'center',
        gap: 6,
        fontSize: 12,
        fontWeight: 500,
        color: active ? 'var(--mg-status-success-text)' : 'var(--c-muted)'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        width: 7,
        height: 7,
        borderRadius: '50%',
        background: active ? 'var(--mg-status-success-solid, var(--mg-status-success-text))' : 'var(--c-border2)'
      }
    }), active ? U_T.active : U_T.inactive);
  }
  function UDropdown({
    value,
    onChange,
    options,
    placeholder,
    width,
    filter
  }) {
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const ref = useRef(null);
    useEffect(() => {
      const h = e => {
        if (ref.current && !ref.current.contains(e.target)) setOpen(false);
      };
      document.addEventListener('mousedown', h);
      return () => document.removeEventListener('mousedown', h);
    }, []);
    const sel = options.find(o => o.value === value);
    const list = filter && q ? options.filter(o => o.label.toLowerCase().includes(q.toLowerCase())) : options;
    return /*#__PURE__*/React.createElement("div", {
      ref: ref,
      style: {
        position: 'relative',
        width: width || '100%'
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: () => setOpen(!open),
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        height: 40,
        padding: '0 12px',
        border: '1px solid ' + (open ? 'var(--mg-input-focus-border)' : 'var(--mg-input-border)'),
        borderRadius: 'var(--mg-radius-md)',
        background: 'var(--c-card)',
        cursor: 'pointer',
        boxShadow: open ? 'var(--mg-focus-ring)' : 'none'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 14,
        color: sel ? 'var(--c-text)' : 'var(--c-muted)',
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, sel ? sel.label : placeholder), sel && /*#__PURE__*/React.createElement("i", {
      className: "pi pi-times",
      onClick: e => {
        e.stopPropagation();
        onChange(null);
        setOpen(false);
      },
      style: {
        fontSize: 12,
        color: 'var(--c-muted)',
        cursor: 'pointer'
      }
    }), /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + (open ? 'pi-chevron-up' : 'pi-chevron-down'),
      style: {
        fontSize: 12,
        color: 'var(--c-muted)'
      }
    })), open && /*#__PURE__*/React.createElement("div", {
      style: {
        position: 'absolute',
        top: 44,
        insetInlineStart: 0,
        zIndex: 40,
        width: '100%',
        minWidth: 180,
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-md)',
        boxShadow: 'var(--mg-shadow-lg)',
        padding: 5,
        maxHeight: 280,
        overflowY: 'auto'
      }
    }, filter && /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 7,
        height: 34,
        padding: '0 10px',
        margin: '2px 2px 6px',
        border: '1px solid var(--c-border2)',
        borderRadius: 'var(--mg-radius-sm)',
        background: 'var(--c-page)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-search",
      style: {
        fontSize: 12,
        color: 'var(--c-muted)'
      }
    }), /*#__PURE__*/React.createElement("input", {
      autoFocus: true,
      value: q,
      onChange: e => setQ(e.target.value),
      placeholder: "\u041F\u043E\u0438\u0441\u043A\u2026",
      style: {
        flex: 1,
        border: 'none',
        outline: 'none',
        background: 'transparent',
        fontFamily: 'var(--mg-font-sans)',
        fontSize: 13,
        color: 'var(--c-text)'
      }
    })), list.map(o => {
      const on = o.value === value;
      return /*#__PURE__*/React.createElement("div", {
        key: String(o.value),
        onClick: () => {
          onChange(o.value);
          setOpen(false);
          setQ('');
        },
        style: {
          display: 'flex',
          alignItems: 'center',
          gap: 8,
          padding: '8px 10px',
          borderRadius: 7,
          cursor: 'pointer',
          fontSize: 13.5,
          fontWeight: on ? 600 : 500,
          color: on ? 'var(--mg-primary-900)' : 'var(--c-text)',
          background: on ? 'var(--mg-primary-100)' : 'transparent'
        }
      }, o.label, on && /*#__PURE__*/React.createElement("i", {
        className: "pi pi-check",
        style: {
          marginInlineStart: 'auto',
          fontSize: 12
        }
      }));
    }), list.length === 0 && /*#__PURE__*/React.createElement("div", {
      style: {
        padding: '8px 10px',
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }, "\u041D\u0438\u0447\u0435\u0433\u043E \u043D\u0435 \u043D\u0430\u0439\u0434\u0435\u043D\u043E")));
  }
  function UModal({
    title,
    close,
    footer,
    width,
    children
  }) {
    return /*#__PURE__*/React.createElement("div", {
      onClick: close,
      style: {
        position: 'fixed',
        inset: 0,
        zIndex: 1300,
        background: 'rgba(9,16,32,0.55)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: 20
      }
    }, /*#__PURE__*/React.createElement("div", {
      onClick: e => e.stopPropagation(),
      style: {
        width: '100%',
        maxWidth: width || 544,
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-lg)',
        overflow: 'hidden',
        maxHeight: 'calc(100vh - 40px)',
        display: 'flex',
        flexDirection: 'column'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '16px 20px',
        borderBottom: '1px solid var(--c-border)',
        flexShrink: 0
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        fontSize: 16,
        fontWeight: 600,
        color: 'var(--c-text)'
      }
    }, title), /*#__PURE__*/React.createElement("i", {
      className: "pi pi-times",
      onClick: close,
      style: {
        fontSize: 14,
        color: 'var(--c-muted)',
        cursor: 'pointer'
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        padding: 20,
        overflowY: 'auto'
      }
    }, children), footer && /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'flex-end',
        gap: 10,
        padding: '14px 20px',
        borderTop: '1px solid var(--c-border)',
        flexShrink: 0
      }
    }, footer)));
  }
  function ULabel({
    children
  }) {
    return /*#__PURE__*/React.createElement("label", {
      style: {
        display: 'block',
        fontSize: 13,
        fontWeight: 500,
        color: 'var(--c-text)',
        marginBottom: 6
      }
    }, children);
  }
  function UUserDialog({
    editing,
    close,
    onSave
  }) {
    const isEdit = !!editing;
    const [f, setF] = useState(editing ? {
      full_name: editing.full_name,
      email: editing.email,
      phone: editing.phone || '',
      job_title: editing.job_title || '',
      department_id: (U_DEPTS.find(d => d.name === editing.department_name) || {}).id ?? null,
      manager_id: null,
      role: editing.role,
      password: ''
    } : {
      full_name: '',
      email: '',
      phone: '',
      job_title: '',
      department_id: null,
      manager_id: null,
      role: null,
      password: ''
    });
    const [err, setErr] = useState({});
    const [showPw, setShowPw] = useState(false);
    const set = (k, v) => setF(s => ({
      ...s,
      [k]: v
    }));
    const managers = U_USERS.filter(u => u.id !== (editing || {}).id).map(u => ({
      value: u.id,
      label: u.full_name
    }));
    function submit() {
      const e = {};
      if (!f.full_name.trim()) e.full_name = 'Обязательное поле';
      if (!f.email.trim()) e.email = 'Обязательное поле';else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(f.email.trim())) e.email = 'Некорректный email';
      if (f.password && f.password.length < 8) e.password = 'Минимум 8 символов';
      setErr(e);
      if (Object.keys(e).length) return;
      onSave(f, isEdit);
    }
    const half = {
      flex: 1,
      minWidth: 0
    };
    return /*#__PURE__*/React.createElement(UModal, {
      title: isEdit ? U_T.editUser : U_T.addUser,
      close: close,
      width: 544,
      footer: /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("button", {
        style: uBtn('text'),
        onClick: close
      }, "\u041E\u0442\u043C\u0435\u043D\u0430"), /*#__PURE__*/React.createElement("button", {
        style: uBtn('primary'),
        onClick: submit
      }, isEdit ? 'Сохранить' : 'Создать'))
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        gap: 14
      }
    }, /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement(ULabel, null, U_T.fields.full_name, " *"), /*#__PURE__*/React.createElement("input", {
      className: "inp",
      autoFocus: true,
      value: f.full_name,
      onChange: e => set('full_name', e.target.value),
      placeholder: U_T.fields.full_name_ph,
      style: {
        ...uInp,
        borderColor: err.full_name ? 'var(--mg-status-danger-border)' : 'var(--mg-input-border)'
      }
    }), err.full_name && /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 12,
        color: 'var(--mg-status-danger-text)',
        marginTop: 5
      }
    }, err.full_name)), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement(ULabel, null, U_T.fields.email, " *"), /*#__PURE__*/React.createElement("input", {
      className: "inp",
      type: "email",
      value: f.email,
      onChange: e => set('email', e.target.value),
      placeholder: U_T.fields.email_ph,
      style: {
        ...uInp,
        borderColor: err.email ? 'var(--mg-status-danger-border)' : 'var(--mg-input-border)'
      }
    }), err.email && /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 12,
        color: 'var(--mg-status-danger-text)',
        marginTop: 5
      }
    }, err.email)), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 14
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: half
    }, /*#__PURE__*/React.createElement(ULabel, null, U_T.fields.phone), /*#__PURE__*/React.createElement("input", {
      className: "inp",
      value: f.phone,
      onChange: e => set('phone', e.target.value),
      placeholder: U_T.fields.phone_ph,
      style: uInp
    })), /*#__PURE__*/React.createElement("div", {
      style: half
    }, /*#__PURE__*/React.createElement(ULabel, null, U_T.fields.job_title), /*#__PURE__*/React.createElement("input", {
      className: "inp",
      value: f.job_title,
      onChange: e => set('job_title', e.target.value),
      placeholder: U_T.fields.job_title_ph,
      style: uInp
    }))), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 14
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: half
    }, /*#__PURE__*/React.createElement(ULabel, null, U_T.fields.department), /*#__PURE__*/React.createElement(UDropdown, {
      value: f.department_id,
      onChange: v => set('department_id', v),
      options: U_DEPTS.map(d => ({
        value: d.id,
        label: d.name
      })),
      placeholder: U_T.fields.department_ph
    })), /*#__PURE__*/React.createElement("div", {
      style: half
    }, /*#__PURE__*/React.createElement(ULabel, null, U_T.fields.role), /*#__PURE__*/React.createElement(UDropdown, {
      value: f.role,
      onChange: v => set('role', v),
      options: U_ROLE_OPTS,
      placeholder: U_T.fields.role_ph
    }))), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement(ULabel, null, U_T.fields.manager), /*#__PURE__*/React.createElement(UDropdown, {
      value: f.manager_id,
      onChange: v => set('manager_id', v),
      options: managers,
      placeholder: U_T.fields.manager_ph,
      filter: true
    })), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement(ULabel, null, U_T.fields.password), /*#__PURE__*/React.createElement("div", {
      style: {
        position: 'relative'
      }
    }, /*#__PURE__*/React.createElement("input", {
      className: "inp",
      type: showPw ? 'text' : 'password',
      value: f.password,
      onChange: e => set('password', e.target.value),
      placeholder: isEdit ? U_T.fields.password_edit_ph : U_T.fields.password_ph,
      style: {
        ...uInp,
        paddingInlineEnd: 42,
        borderColor: err.password ? 'var(--mg-status-danger-border)' : 'var(--mg-input-border)'
      }
    }), /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + (showPw ? 'pi-eye-slash' : 'pi-eye'),
      onClick: () => setShowPw(!showPw),
      style: {
        position: 'absolute',
        insetInlineEnd: 13,
        top: 13,
        fontSize: 15,
        color: 'var(--c-muted)',
        cursor: 'pointer'
      }
    })), err.password && /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 12,
        color: 'var(--mg-status-danger-text)',
        marginTop: 5
      }
    }, err.password)), !isEdit && /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 9,
        alignItems: 'flex-start',
        padding: '11px 13px',
        background: 'var(--c-hover)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-md)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-info-circle",
      style: {
        fontSize: 14,
        color: 'var(--c-muted)',
        marginTop: 1
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 12.5,
        color: 'var(--c-text2)',
        lineHeight: 1.5
      }
    }, U_T.passwordHint))));
  }
  function UConfirm({
    header,
    icon,
    iconColor,
    message,
    acceptLabel,
    acceptKind,
    onAccept,
    close
  }) {
    return /*#__PURE__*/React.createElement(UModal, {
      title: header,
      close: close,
      width: 430,
      footer: /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("button", {
        style: uBtn('ghost'),
        onClick: close
      }, "\u041E\u0442\u043C\u0435\u043D\u0430"), /*#__PURE__*/React.createElement("button", {
        style: uBtn(acceptKind),
        onClick: onAccept
      }, acceptLabel))
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 14,
        alignItems: 'flex-start'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        width: 40,
        height: 40,
        borderRadius: '50%',
        background: U_SEV[acceptKind === 'danger' ? 'danger' : 'warn'].bg,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        flexShrink: 0
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + icon,
      style: {
        fontSize: 17,
        color: iconColor
      }
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 14,
        color: 'var(--c-text2)',
        lineHeight: 1.55,
        paddingTop: 3
      }
    }, message)));
  }
  function UResetResult({
    password,
    close
  }) {
    const [copied, setCopied] = useState(false);
    return /*#__PURE__*/React.createElement(UModal, {
      title: U_T.reset.resultTitle,
      close: close,
      width: 448,
      footer: /*#__PURE__*/React.createElement("button", {
        style: uBtn('ghost'),
        onClick: close
      }, "\u0417\u0430\u043A\u0440\u044B\u0442\u044C")
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'flex-start',
        gap: 10,
        padding: 13,
        marginBottom: 18,
        background: 'var(--mg-status-warning-bg)',
        border: '1px solid var(--mg-status-warning-border)',
        borderRadius: 'var(--mg-radius-md)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-exclamation-triangle",
      style: {
        fontSize: 15,
        color: 'var(--mg-status-warning-solid)',
        marginTop: 1,
        flexShrink: 0
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 13,
        color: 'var(--mg-status-warning-text)',
        lineHeight: 1.5
      }
    }, U_T.reset.oneTimeWarning)), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        gap: 8
      }
    }, /*#__PURE__*/React.createElement("label", {
      style: {
        fontSize: 13,
        fontWeight: 500,
        color: 'var(--c-text)'
      }
    }, U_T.reset.newPassword), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        padding: '8px 12px',
        background: 'var(--c-hover)',
        border: '1px solid var(--c-border2)',
        borderRadius: 'var(--mg-radius-md)'
      }
    }, /*#__PURE__*/React.createElement("code", {
      style: {
        flex: 1,
        fontFamily: 'ui-monospace,monospace',
        fontSize: 15,
        fontWeight: 500,
        color: 'var(--c-text)',
        letterSpacing: '0.04em',
        wordBreak: 'break-all'
      }
    }, password), /*#__PURE__*/React.createElement("button", {
      onClick: () => {
        navigator.clipboard && navigator.clipboard.writeText(password);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
      },
      title: U_T.reset.copy,
      style: {
        width: 32,
        height: 32,
        borderRadius: '50%',
        border: 'none',
        background: 'transparent',
        cursor: 'pointer',
        display: 'inline-flex',
        alignItems: 'center',
        justifyContent: 'center'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + (copied ? 'pi-check' : 'pi-copy'),
      style: {
        fontSize: 14,
        color: copied ? 'var(--mg-status-success-text)' : 'var(--c-muted)'
      }
    })))));
  }
  function UToasts({
    items
  }) {
    return /*#__PURE__*/React.createElement("div", {
      style: {
        position: 'fixed',
        insetBlockEnd: 20,
        insetInlineEnd: 20,
        zIndex: 2000,
        display: 'flex',
        flexDirection: 'column',
        gap: 10
      }
    }, items.map(t => {
      const c = U_SEV[t.severity] || U_SEV.success;
      return /*#__PURE__*/React.createElement("div", {
        key: t.id,
        style: {
          display: 'flex',
          alignItems: 'center',
          gap: 10,
          minWidth: 260,
          maxWidth: 360,
          padding: '12px 14px',
          background: 'var(--c-card)',
          border: '1px solid var(--c-border)',
          borderInlineStart: '3px solid ' + c.fg,
          borderRadius: 'var(--mg-radius-md)',
          boxShadow: 'var(--mg-shadow-lg)',
          animation: 'toastIn .2s ease'
        }
      }, /*#__PURE__*/React.createElement("i", {
        className: "pi pi-check-circle",
        style: {
          fontSize: 16,
          color: c.fg
        }
      }), /*#__PURE__*/React.createElement("span", {
        style: {
          fontSize: 13.5,
          fontWeight: 500,
          color: 'var(--c-text)'
        }
      }, t.summary));
    }));
  }
  function UIconBtn({
    icon,
    color,
    title,
    onClick
  }) {
    return /*#__PURE__*/React.createElement("button", {
      className: "uicon",
      onClick: onClick,
      title: title,
      "aria-label": title,
      style: {
        width: 32,
        height: 32,
        borderRadius: 8,
        border: 'none',
        background: 'transparent',
        cursor: 'pointer',
        display: 'inline-flex',
        alignItems: 'center',
        justifyContent: 'center'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + icon,
      style: {
        fontSize: 14,
        color
      }
    }));
  }
  function UsersTab() {
    const [rows, setRows] = useState(U_USERS);
    const [search, setSearch] = useState('');
    const [roleF, setRoleF] = useState(null);
    const [deptF, setDeptF] = useState(null);
    const [activeF, setActiveF] = useState(null);
    const [dialog, setDialog] = useState(null);
    const [toasts, setToasts] = useState([]);
    const [editing, setEditing] = useState(false);
    const pushToast = summary => {
      const id = Date.now() + Math.random();
      setToasts(t => [...t, {
        id,
        summary,
        severity: 'success'
      }]);
      setTimeout(() => setToasts(t => t.filter(x => x.id !== id)), 2600);
    };
    const th = {
      padding: '11px 16px',
      fontSize: 11.5,
      fontWeight: 600,
      letterSpacing: '.03em',
      textTransform: 'uppercase',
      color: 'var(--c-muted)',
      textAlign: 'start',
      borderBottom: '1px solid var(--c-border)',
      whiteSpace: 'nowrap'
    };
    const td = {
      padding: '10px 16px',
      fontSize: 13.5,
      color: 'var(--c-text)',
      borderBottom: '1px solid var(--c-border)',
      verticalAlign: 'middle'
    };
    const filtered = rows.filter(u => {
      if (search.trim()) {
        const q = search.trim().toLowerCase();
        if (!(u.full_name.toLowerCase().includes(q) || u.email.toLowerCase().includes(q))) return false;
      }
      if (roleF && u.role !== roleF) return false;
      if (deptF !== null && (U_DEPTS.find(d => d.id === deptF) || {}).name !== u.department_name) return false;
      return true;
    });
    const active = filtered.filter(u => u.is_active);
    const inactive = filtered.filter(u => !u.is_active);
    const activeCount = rows.filter(u => u.is_active).length;
    function saveUser(form, isEdit) {
      if (isEdit) {
        setRows(rs => rs.map(u => u.id === dialog.editing.id ? {
          ...u,
          full_name: form.full_name.trim(),
          email: form.email.trim(),
          phone: form.phone.trim() || null,
          job_title: form.job_title.trim() || null,
          department_name: (U_DEPTS.find(d => d.id === form.department_id) || {}).name ?? null,
          role: form.role
        } : u));
        pushToast('Изменения сохранены');
      } else {
        const id = Math.max(...rows.map(r => r.id)) + 1;
        setRows(rs => [...rs, {
          id,
          full_name: form.full_name.trim(),
          email: form.email.trim(),
          phone: form.phone.trim() || null,
          job_title: form.job_title.trim() || null,
          department_name: (U_DEPTS.find(d => d.id === form.department_id) || {}).name ?? null,
          role: form.role || 'manager',
          is_active: true
        }]);
        pushToast('Пользователь создан');
      }
      setDialog(null);
    }
    function doDeactivate(u) {
      setRows(rs => rs.map(x => x.id === u.id ? {
        ...x,
        is_active: false
      } : x));
      pushToast('Пользователь деактивирован');
      setDialog(null);
    }
    function doReactivate(u) {
      setRows(rs => rs.map(x => x.id === u.id ? {
        ...x,
        is_active: true
      } : x));
      pushToast('Пользователь активирован');
    }
    function doReset() {
      setDialog({
        type: 'resetResult',
        pw: uGenPw()
      });
    }
    const columns = /*#__PURE__*/React.createElement("thead", null, /*#__PURE__*/React.createElement("tr", null, /*#__PURE__*/React.createElement("th", {
      style: th
    }, "\u0421\u043E\u0442\u0440\u0443\u0434\u043D\u0438\u043A"), /*#__PURE__*/React.createElement("th", {
      style: {
        ...th,
        width: 170
      }
    }, "\u0422\u0435\u043B\u0435\u0444\u043E\u043D"), /*#__PURE__*/React.createElement("th", {
      style: {
        ...th,
        width: 180
      }
    }, "\u0414\u043E\u043B\u0436\u043D\u043E\u0441\u0442\u044C"), /*#__PURE__*/React.createElement("th", {
      style: {
        ...th,
        width: 150
      }
    }, "\u041E\u0442\u0434\u0435\u043B"), /*#__PURE__*/React.createElement("th", {
      style: {
        ...th,
        width: 150
      }
    }, "\u0420\u043E\u043B\u044C"), editing && /*#__PURE__*/React.createElement("th", {
      style: {
        ...th,
        width: 120,
        textAlign: 'end'
      }
    }, "\u0414\u0435\u0439\u0441\u0442\u0432\u0438\u044F")));
    const renderRow = (u, archive) => /*#__PURE__*/React.createElement("tr", {
      key: u.id,
      className: "urow",
      style: {
        transition: 'background .12s',
        opacity: archive ? 0.72 : 1
      }
    }, /*#__PURE__*/React.createElement("td", {
      style: td
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 11
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        width: 36,
        height: 36,
        borderRadius: '50%',
        flexShrink: 0,
        background: archive ? 'var(--c-muted2)' : U_ROLE_GRAD[u.role] || U_ROLE_GRAD.accountant,
        color: archive ? 'var(--c-muted)' : '#fff',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        fontSize: 12.5,
        fontWeight: 600,
        letterSpacing: '.02em'
      }
    }, uInitials(u.full_name)), /*#__PURE__*/React.createElement("div", {
      style: {
        minWidth: 0
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        fontWeight: 600,
        fontSize: 13.5,
        color: 'var(--c-text)',
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, u.full_name), /*#__PURE__*/React.createElement("div", {
      style: {
        fontSize: 12,
        color: 'var(--c-muted)',
        overflow: 'hidden',
        textOverflow: 'ellipsis',
        whiteSpace: 'nowrap'
      }
    }, u.email)))), /*#__PURE__*/React.createElement("td", {
      style: {
        ...td,
        color: u.phone ? 'var(--c-text2)' : 'var(--c-muted)',
        whiteSpace: 'nowrap',
        fontVariantNumeric: 'tabular-nums'
      }
    }, u.phone ?? '—'), /*#__PURE__*/React.createElement("td", {
      style: {
        ...td,
        color: u.job_title ? 'var(--c-text2)' : 'var(--c-muted)'
      }
    }, u.job_title ?? '—'), /*#__PURE__*/React.createElement("td", {
      style: {
        ...td,
        color: u.department_name ? 'var(--c-text2)' : 'var(--c-muted)'
      }
    }, u.department_name ?? '—'), /*#__PURE__*/React.createElement("td", {
      style: td
    }, u.role ? /*#__PURE__*/React.createElement(UTag, {
      value: U_ROLE_LABEL[u.role],
      severity: U_ROLE_SEV[u.role]
    }) : /*#__PURE__*/React.createElement("span", {
      style: {
        color: 'var(--c-muted)'
      }
    }, "\u2014")), editing && /*#__PURE__*/React.createElement("td", {
      style: td
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 2,
        justifyContent: 'flex-end'
      }
    }, /*#__PURE__*/React.createElement(UIconBtn, {
      icon: "pi-pencil",
      color: "var(--c-text2)",
      title: "\u0420\u0435\u0434\u0430\u043A\u0442\u0438\u0440\u043E\u0432\u0430\u0442\u044C",
      onClick: () => setDialog({
        type: 'form',
        editing: u
      })
    }), u.id !== U_ME && /*#__PURE__*/React.createElement(UIconBtn, {
      icon: "pi-key",
      color: "var(--c-text2)",
      title: U_T.reset.action,
      onClick: () => setDialog({
        type: 'confirm',
        kind: 'reset',
        user: u
      })
    }), archive ? /*#__PURE__*/React.createElement(UIconBtn, {
      icon: "pi-replay",
      color: "var(--mg-status-success-text)",
      title: U_T.activate,
      onClick: () => doReactivate(u)
    }) : /*#__PURE__*/React.createElement(UIconBtn, {
      icon: "pi-ban",
      color: "var(--mg-status-danger-text)",
      title: U_T.deactivate,
      onClick: () => setDialog({
        type: 'confirm',
        kind: 'deactivate',
        user: u
      })
    }))));
    return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'flex-start',
        gap: 16,
        marginBottom: 20
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        flex: 1,
        minWidth: 0
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10
      }
    }, /*#__PURE__*/React.createElement("h2", {
      style: {
        margin: 0,
        fontSize: 20,
        fontWeight: 600
      }
    }, U_T.title), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 12,
        fontWeight: 600,
        padding: '2px 9px',
        borderRadius: 999,
        background: 'var(--mg-primary-100)',
        color: 'var(--mg-primary-900)'
      }
    }, rows.length)), /*#__PURE__*/React.createElement("p", {
      style: {
        margin: '5px 0 0',
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }, U_T.subtitle, " \xB7 ", activeCount, " \u0430\u043A\u0442\u0438\u0432\u043D\u044B\u0445")), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 10,
        flexShrink: 0
      }
    }, /*#__PURE__*/React.createElement("button", {
      style: editing ? {
        ...uBtn('ghost'),
        color: 'var(--mg-primary-900)',
        borderColor: 'var(--mg-primary-900)'
      } : uBtn('ghost'),
      onClick: () => setEditing(e => !e)
    }, /*#__PURE__*/React.createElement("i", {
      className: 'pi ' + (editing ? 'pi-check' : 'pi-pencil'),
      style: {
        fontSize: 12
      }
    }), editing ? 'Завершить редактирование' : 'Редактировать'), /*#__PURE__*/React.createElement("button", {
      style: uBtn('primary'),
      onClick: () => setDialog({
        type: 'form',
        editing: null
      })
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-plus",
      style: {
        fontSize: 12
      }
    }), U_T.addUser))), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        flexWrap: 'wrap',
        marginBottom: 14
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        height: 40,
        padding: '0 12px',
        flex: 1,
        minWidth: 200,
        maxWidth: 320,
        border: '1px solid var(--mg-input-border)',
        borderRadius: 'var(--mg-radius-md)',
        background: 'var(--c-card)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-search",
      style: {
        fontSize: 13,
        color: 'var(--c-muted)'
      }
    }), /*#__PURE__*/React.createElement("input", {
      value: search,
      onChange: e => setSearch(e.target.value),
      placeholder: U_T.filters.search,
      style: {
        flex: 1,
        border: 'none',
        outline: 'none',
        background: 'transparent',
        fontFamily: 'var(--mg-font-sans)',
        fontSize: 14,
        color: 'var(--c-text)'
      }
    })), /*#__PURE__*/React.createElement(UDropdown, {
      value: roleF,
      onChange: setRoleF,
      options: U_ROLE_OPTS,
      placeholder: U_T.filters.role,
      width: 150
    }), /*#__PURE__*/React.createElement(UDropdown, {
      value: deptF,
      onChange: setDeptF,
      options: U_DEPTS.map(d => ({
        value: d.id,
        label: d.name
      })),
      placeholder: U_T.filters.department,
      width: 168
    })), /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-sm)',
        overflow: 'hidden'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        overflowX: 'auto'
      }
    }, /*#__PURE__*/React.createElement("table", {
      style: {
        width: '100%',
        borderCollapse: 'collapse',
        minWidth: 820
      }
    }, columns, /*#__PURE__*/React.createElement("tbody", null, active.map(u => renderRow(u, false))))), active.length === 0 && /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        gap: 12,
        padding: '52px 20px',
        color: 'var(--c-muted)'
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-users",
      style: {
        fontSize: 30,
        opacity: 0.3
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 14,
        color: 'var(--c-text2)'
      }
    }, "\u0410\u043A\u0442\u0438\u0432\u043D\u044B\u0435 \u043F\u043E\u043B\u044C\u0437\u043E\u0432\u0430\u0442\u0435\u043B\u0438 \u043D\u0435 \u043D\u0430\u0439\u0434\u0435\u043D\u044B"))), inactive.length > 0 && /*#__PURE__*/React.createElement("div", {
      style: {
        marginTop: 28
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 9,
        marginBottom: 12
      }
    }, /*#__PURE__*/React.createElement("i", {
      className: "pi pi-user-minus",
      style: {
        fontSize: 14,
        color: 'var(--c-muted)'
      }
    }), /*#__PURE__*/React.createElement("h3", {
      style: {
        margin: 0,
        fontSize: 15,
        fontWeight: 600,
        color: 'var(--c-text2)'
      }
    }, "\u0423\u0432\u043E\u043B\u0435\u043D\u043D\u044B\u0435"), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: 11.5,
        fontWeight: 600,
        padding: '2px 8px',
        borderRadius: 999,
        background: 'var(--c-muted2)',
        color: 'var(--c-text2)'
      }
    }, inactive.length)), /*#__PURE__*/React.createElement("div", {
      style: {
        background: 'var(--c-card)',
        border: '1px solid var(--c-border)',
        borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-sm)',
        overflow: 'hidden'
      }
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        overflowX: 'auto'
      }
    }, /*#__PURE__*/React.createElement("table", {
      style: {
        width: '100%',
        borderCollapse: 'collapse',
        minWidth: 820
      }
    }, columns, /*#__PURE__*/React.createElement("tbody", null, inactive.map(u => renderRow(u, true))))))), dialog && dialog.type === 'form' && /*#__PURE__*/React.createElement(UUserDialog, {
      key: dialog.editing ? 'edit-' + dialog.editing.id : 'new',
      editing: dialog.editing,
      close: () => setDialog(null),
      onSave: saveUser
    }), dialog && dialog.type === 'confirm' && dialog.kind === 'deactivate' && /*#__PURE__*/React.createElement(UConfirm, {
      header: U_T.deactivateTitle,
      icon: "pi-exclamation-triangle",
      iconColor: "var(--mg-status-warning-solid)",
      message: U_T.deactivateConfirm(dialog.user.full_name),
      acceptLabel: U_T.deactivate,
      acceptKind: "danger",
      onAccept: () => doDeactivate(dialog.user),
      close: () => setDialog(null)
    }), dialog && dialog.type === 'confirm' && dialog.kind === 'reset' && /*#__PURE__*/React.createElement(UConfirm, {
      header: U_T.reset.title,
      icon: "pi-key",
      iconColor: "var(--mg-status-warning-solid)",
      message: U_T.reset.confirm(dialog.user.full_name),
      acceptLabel: U_T.reset.action,
      acceptKind: "warn",
      onAccept: doReset,
      close: () => setDialog(null)
    }), dialog && dialog.type === 'resetResult' && /*#__PURE__*/React.createElement(UResetResult, {
      password: dialog.pw,
      close: () => setDialog(null)
    }), /*#__PURE__*/React.createElement(UToasts, {
      items: toasts
    }));
  }
  function uGenPw() {
    const a = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    let s = '';
    for (let i = 0; i < 12; i++) s += a[Math.floor(Math.random() * a.length)];
    return s;
  }
  window.UsersTab = UsersTab;
})();
})(); } catch (e) { __ds_ns.__errors.push({ path: "redesign/users-section.jsx", error: String((e && e.message) || e) }); }

// ui_kits/crm/Sidebar.jsx
try { (() => {
// MACRO Global CRM — UI Kit · Sidebar (dark navy, brand-invariant)
function Sidebar({
  active,
  onNavigate
}) {
  const {
    Avatar,
    Badge
  } = window.MACROGlobalCRMDesignSystem_2f42e6;
  const main = [{
    key: 'dashboard',
    icon: 'pi-home',
    label: 'Дашборд'
  }, {
    key: 'contacts',
    icon: 'pi-users',
    label: 'Контакты'
  }, {
    key: 'companies',
    icon: 'pi-building',
    label: 'Компании'
  }, {
    key: 'deals',
    icon: 'pi-briefcase',
    label: 'Сделки'
  }, {
    key: 'tasks',
    icon: 'pi-check-square',
    label: 'Мои задачи',
    badge: 20
  }, {
    key: 'cabinet',
    icon: 'pi-id-card',
    label: 'Кабинет'
  }, {
    key: 'catalog',
    icon: 'pi-box',
    label: 'Каталог'
  }, {
    key: 'documents',
    icon: 'pi-file-edit',
    label: 'Документы'
  }, {
    key: 'approvals',
    icon: 'pi-check-circle',
    label: 'Мои согласования'
  }, {
    key: 'learning',
    icon: 'pi-book',
    label: 'Моё обучение'
  }];
  const admin = [{
    key: 'pipeline',
    icon: 'pi-sliders-h',
    label: 'Настройки воронки'
  }, {
    key: 'templates',
    icon: 'pi-file',
    label: 'Шаблоны'
  }, {
    key: 'routes',
    icon: 'pi-sitemap',
    label: 'Маршруты'
  }, {
    key: 'hr',
    icon: 'pi-graduation-cap',
    label: 'Курсы (HR)'
  }, {
    key: 'progress',
    icon: 'pi-chart-bar',
    label: 'Прогресс'
  }];
  const switchable = new Set(['deals', 'contacts', 'tasks', 'companies']);
  const Item = ({
    it
  }) => {
    const [hover, setHover] = React.useState(false);
    const isActive = it.key === active;
    return /*#__PURE__*/React.createElement("div", {
      onMouseEnter: () => setHover(true),
      onMouseLeave: () => setHover(false),
      onClick: () => switchable.has(it.key) && onNavigate(it.key),
      style: {
        position: 'relative',
        display: 'flex',
        alignItems: 'center',
        gap: '10px',
        margin: '2px 8px',
        borderRadius: '9px',
        padding: '8px 10px',
        minHeight: '36px',
        color: isActive ? 'var(--mg-sidebar-text-active)' : 'var(--mg-sidebar-text)',
        background: isActive ? 'var(--mg-sidebar-active-bg)' : hover ? 'rgba(255,255,255,0.05)' : 'transparent',
        cursor: switchable.has(it.key) ? 'pointer' : 'default',
        whiteSpace: 'nowrap',
        transition: 'background var(--mg-transition-fast), color var(--mg-transition-fast)'
      }
    }, isActive && /*#__PURE__*/React.createElement("span", {
      style: {
        position: 'absolute',
        left: '-8px',
        top: '50%',
        transform: 'translateY(-50%)',
        width: '3px',
        height: '18px',
        background: 'var(--mg-sidebar-active-bar)',
        borderRadius: '0 3px 3px 0'
      }
    }), /*#__PURE__*/React.createElement("i", {
      className: `pi ${it.icon}`,
      style: {
        fontSize: '18px',
        width: '18px',
        textAlign: 'center',
        flexShrink: 0
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        fontSize: '13px',
        fontWeight: 500,
        flex: 1,
        overflow: 'hidden',
        textOverflow: 'ellipsis'
      }
    }, it.label), it.badge && /*#__PURE__*/React.createElement(Badge, {
      value: it.badge
    }));
  };
  return /*#__PURE__*/React.createElement("aside", {
    style: {
      display: 'flex',
      flexDirection: 'column',
      background: 'var(--mg-sidebar-bg)',
      width: '240px',
      height: '100%',
      flexShrink: 0,
      overflow: 'hidden'
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'center',
      height: '60px',
      padding: '0 16px',
      borderBottom: '1px solid var(--mg-sidebar-divider)',
      flexShrink: 0
    }
  }, /*#__PURE__*/React.createElement("img", {
    src: "../../assets/macroglobal-logo-primary-light.svg",
    alt: "MACRO Global",
    style: {
      height: '28px',
      filter: 'brightness(0) invert(1)'
    }
  })), /*#__PURE__*/React.createElement("nav", {
    style: {
      flex: 1,
      overflowY: 'auto',
      padding: '8px 0'
    },
    className: "mg-sb-scroll"
  }, main.map(it => /*#__PURE__*/React.createElement(Item, {
    key: it.key,
    it: it
  })), /*#__PURE__*/React.createElement("div", {
    style: {
      padding: '14px 16px 4px',
      fontSize: '10px',
      fontWeight: 600,
      letterSpacing: '0.08em',
      textTransform: 'uppercase',
      color: 'rgba(255,255,255,0.35)'
    }
  }, "\u0410\u0434\u043C\u0438\u043D\u0438\u0441\u0442\u0440\u0438\u0440\u043E\u0432\u0430\u043D\u0438\u0435"), admin.map(it => /*#__PURE__*/React.createElement(Item, {
    key: it.key,
    it: it
  }))), /*#__PURE__*/React.createElement("div", {
    style: {
      flexShrink: 0,
      borderTop: '1px solid var(--mg-sidebar-divider)',
      padding: '8px'
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: '8px',
      padding: '8px',
      borderRadius: '6px',
      cursor: 'pointer'
    }
  }, /*#__PURE__*/React.createElement(Avatar, {
    name: "\u0411\u043E\u0433\u0434\u0430\u043D \u041C\u0435\u0440\u043A\u0443\u043B\u043E\u0432",
    size: 32,
    color: "rgba(255,255,255,0.15)"
  }), /*#__PURE__*/React.createElement("div", {
    style: {
      flex: 1,
      overflow: 'hidden'
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      fontSize: '14px',
      fontWeight: 500,
      color: 'var(--mg-sidebar-text-active)',
      overflow: 'hidden',
      textOverflow: 'ellipsis',
      whiteSpace: 'nowrap'
    }
  }, "\u0411\u043E\u0433\u0434\u0430\u043D \u041C\u0435\u0440\u043A\u0443\u043B\u043E\u0432"), /*#__PURE__*/React.createElement("div", {
    style: {
      fontSize: '12px',
      color: 'rgba(255,255,255,0.55)'
    }
  }, "\u0410\u0434\u043C\u0438\u043D\u0438\u0441\u0442\u0440\u0430\u0442\u043E\u0440")), /*#__PURE__*/React.createElement("i", {
    className: "pi pi-ellipsis-h",
    style: {
      fontSize: '14px',
      color: 'rgba(255,255,255,0.4)'
    }
  }))));
}
window.Sidebar = Sidebar;
})(); } catch (e) { __ds_ns.__errors.push({ path: "ui_kits/crm/Sidebar.jsx", error: String((e && e.message) || e) }); }

__ds_ns.KanbanCard = __ds_scope.KanbanCard;

__ds_ns.Stepper = __ds_scope.Stepper;

__ds_ns.Avatar = __ds_scope.Avatar;

__ds_ns.AvatarGroup = __ds_scope.AvatarGroup;

__ds_ns.Badge = __ds_scope.Badge;

__ds_ns.Card = __ds_scope.Card;

__ds_ns.DataTable = __ds_scope.DataTable;

__ds_ns.NotificationBadge = __ds_scope.NotificationBadge;

__ds_ns.StatCard = __ds_scope.StatCard;

__ds_ns.Tag = __ds_scope.Tag;

__ds_ns.EmptyState = __ds_scope.EmptyState;

__ds_ns.Skeleton = __ds_scope.Skeleton;

__ds_ns.Toast = __ds_scope.Toast;

__ds_ns.Button = __ds_scope.Button;

__ds_ns.Checkbox = __ds_scope.Checkbox;

__ds_ns.Input = __ds_scope.Input;

__ds_ns.Select = __ds_scope.Select;

__ds_ns.Switch = __ds_scope.Switch;

__ds_ns.PageHeader = __ds_scope.PageHeader;

__ds_ns.Pagination = __ds_scope.Pagination;

__ds_ns.SegmentedControl = __ds_scope.SegmentedControl;

__ds_ns.Tabs = __ds_scope.Tabs;

__ds_ns.CommandPalette = __ds_scope.CommandPalette;

__ds_ns.Dialog = __ds_scope.Dialog;

__ds_ns.Menu = __ds_scope.Menu;

__ds_ns.Tooltip = __ds_scope.Tooltip;

__ds_ns.Tree = __ds_scope.Tree;

})();
