"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { DndContext, closestCenter, type DragEndEvent } from "@dnd-kit/core";
import { SortableContext, verticalListSortingStrategy, arrayMove } from "@dnd-kit/sortable";
import { SortableItem } from "./SortableItem";
import { Logo } from "./Logo";
import { SidebarTooltip } from "./SidebarTooltip";
import { Avatar } from "./Avatar";
import { RoleGate } from "./RoleGate";
import { SavedFilterPin } from "./Segments/SavedFilterPin";
import { ThemeToggle } from "./ThemeToggle";
import { NotificationBell } from "./Notifications/NotificationBell";
import { useMe } from "@/lib/auth";
import { RoleLabels, type UserRole, type OnboardingBadgeSummary, type SavedFilter } from "@/lib/types";
import { api, fetcher } from "@/lib/api";
import { usePinnedFilters } from "@/hooks/useSavedFilters";
import useSWR from "swr";

function useOnboardingBadge(): { overdue: number; inProgress: number } {
  const { data } = useSWR<OnboardingBadgeSummary>(
    "/onboarding/my-courses?summary=true",
    fetcher,
    { refreshInterval: 60_000 }
  );
  return {
    overdue: data?.overdue_count ?? 0,
    inProgress: data?.in_progress_count ?? 0,
  };
}

function useOpenTasksCount(): number {
  const { data } = useSWR<{ count: number }>(
    "/activities/my-open-count",
    fetcher,
    { refreshInterval: 60_000 }
  );
  return data?.count ?? 0;
}

// Lazy import search context to avoid circular dep — we use a custom event instead
function useOpenSearch() {
  return () => {
    window.dispatchEvent(new CustomEvent("crm:open-search"));
  };
}

type Item = { href: string; icon: string; label: string; roles?: UserRole[]; exact?: boolean };

function isItemActive(item: Item, pathname: string): boolean {
  if (item.exact) return pathname === item.href;
  return pathname === item.href || pathname.startsWith(item.href + "/");
}

function NavItemWithBadge({ item, pathname, badgeCount, badgeColor, collapsed }: { item: Item; pathname: string; badgeCount?: number; badgeColor?: string; collapsed?: boolean }) {
  const active = pathname === item.href || pathname.startsWith(item.href + "/");
  const hasBadge = badgeCount != null && badgeCount > 0;
  const dotColor = badgeColor ?? "bg-info";
  return (
    <Link
      href={item.href}
      className={
        "group relative flex items-center rounded-md py-2 text-sm transition-colors " +
        (collapsed ? "justify-center px-0 " : "gap-3 px-3 ") +
        (active ? "bg-primary text-white" : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700")
      }
    >
      <span className="relative inline-flex">
        <i className={`bi ${item.icon} text-base`} />
        {collapsed && hasBadge && (
          <span className={`absolute -top-0.5 -right-0.5 h-2 w-2 rounded-full ${dotColor}`} />
        )}
      </span>
      {!collapsed && <span className="flex-1">{item.label}</span>}
      {!collapsed && hasBadge && (
        <span className={`${badgeColor ?? "bg-info"} text-white text-[10px] font-bold rounded-full px-1.5 py-0.5 min-w-[18px] text-center`}>
          {badgeCount > 9 ? "9+" : badgeCount}
        </span>
      )}
      {collapsed && <SidebarTooltip label={item.label} />}
    </Link>
  );
}

const SALES_ITEMS: Item[] = [
  { href: "/dashboard", icon: "bi-speedometer2", label: "Дашборд" },
  { href: "/me", icon: "bi-person-workspace", label: "Кабинет" },
  // DEALS 2.0: Лиды схлопнуты в Сделки (Lead = Deal в этапе «Новые лиды»)
  { href: "/deals", icon: "bi-kanban", label: "Сделки" },
  // CONTACTS 2.0: единый раздел вместо Контрагенты + Контакты + Компании
  { href: "/contacts", icon: "bi-person-rolodex", label: "Контакты" },
  { href: "/tasks", icon: "bi-clipboard-check", label: "Задачи" },
];

const CS_ITEMS: Item[] = [
  { href: "/registry", icon: "bi-clipboard-data", label: "Реестр клиентов" },
  { href: "/inbox", icon: "bi-inbox-fill", label: "Входящие" },
  { href: "/contracts", icon: "bi-files", label: "Документы" },
];

const ANALYTICS_ITEMS: Item[] = [
  { href: "/analytics/cohorts", icon: "bi-grid-3x3", label: "Когортная аналитика" },
];

const LEARNING_ITEMS: Item[] = [
  { href: "/onboarding", icon: "bi-mortarboard-fill", label: "Мои курсы" },
];

const ADMIN_GROUPS: NavGroup[] = [
  {
    key: "adm_refs",
    label: "Справочники",
    icon: "bi-journal-bookmark",
    items: [
      { href: "/admin/products", icon: "bi-box-seam", label: "Продукты и прайс", roles: ["admin", "director"] },
      { href: "/admin/client-categories", icon: "bi-tags", label: "Категории клиентов", roles: ["admin", "director"] },
      { href: "/admin/references", icon: "bi-journal-bookmark", label: "Справочники", roles: ["admin", "director"] },
      { href: "/admin/cs-config", icon: "bi-hdd-stack", label: "Справочники CS", roles: ["admin", "director"] },
      { href: "/admin/currency-rates", icon: "bi-currency-exchange", label: "Курсы валют", roles: ["admin", "director"] },
      { href: "/admin/countries", icon: "bi-globe", label: "Страны", roles: ["admin", "director"] },
      { href: "/admin/cities", icon: "bi-geo-alt", label: "Города", roles: ["admin", "director"] },
      { href: "/admin/sources", icon: "bi-signpost", label: "Источники", roles: ["admin", "director"] },
      { href: "/admin/product-groups", icon: "bi-tags", label: "Группы продуктов", roles: ["admin", "director"] },
    ],
  },
  {
    key: "adm_process",
    label: "Процессы",
    icon: "bi-diagram-2",
    items: [
      { href: "/admin/pipelines", icon: "bi-diagram-2", label: "Конструктор воронок", roles: ["admin", "director"] },
      { href: "/admin/automations", icon: "bi-lightning-charge", label: "Автоматизации", roles: ["admin", "director"] },
      { href: "/admin/sequences", icon: "bi-collection-play-fill", label: "Последовательности", roles: ["admin", "director"] },
    ],
  },
  {
    key: "adm_team",
    label: "Команда и доступ",
    icon: "bi-people",
    items: [
      { href: "/admin/users", icon: "bi-person-gear", label: "Пользователи", roles: ["admin"] },
      { href: "/admin/departments", icon: "bi-diagram-3-fill", label: "Отделы", roles: ["admin", "director"] },
      { href: "/admin/visibility", icon: "bi-eye", label: "Видимость", roles: ["admin", "director"] },
      { href: "/company/employees", icon: "bi-people", label: "Сотрудники", roles: ["admin", "director"] },
      { href: "/company/rights-transfers", icon: "bi-arrow-left-right", label: "Передача прав", roles: ["admin", "director"] },
      { href: "/company/schedules", icon: "bi-calendar-week", label: "График работы", roles: ["admin", "director"] },
      { href: "/company/absences", icon: "bi-airplane", label: "Отсутствия", roles: ["admin", "director"] },
      { href: "/admin/salary-plans", icon: "bi-cash-coin", label: "Планы зарплат", roles: ["admin", "director"] },
      { href: "/admin/commission-rules", icon: "bi-percent", label: "Правила комиссии", roles: ["admin", "director"] },
      { href: "/admin/team-targets", icon: "bi-bullseye", label: "Командные цели", roles: ["admin", "director"] },
      { href: "/finance/settings/permissions", icon: "bi-shield-lock", label: "Финансы — права доступа", roles: ["cfo", "admin"] },
    ],
  },
  {
    key: "adm_docs",
    label: "Документы",
    icon: "bi-file-earmark-text",
    items: [
      { href: "/admin/templates", icon: "bi-file-earmark-code", label: "Шаблоны", roles: ["admin", "lawyer"] },
      { href: "/admin/template-variables", icon: "bi-input-cursor-text", label: "Переменные шаблона", roles: ["admin", "lawyer"] },
      { href: "/admin/approval-routes", icon: "bi-diagram-3", label: "Маршруты согласования", roles: ["admin"] },
      { href: "/admin/licensors", icon: "bi-building", label: "Наши юр.лица", roles: ["admin", "lawyer", "director"] },
    ],
  },
  {
    key: "adm_integrations",
    label: "Интеграции и каналы",
    icon: "bi-plug",
    items: [
      { href: "/admin/integrations", icon: "bi-plug", label: "Интеграции", roles: ["admin", "director", "lawyer"] },
      { href: "/developers", icon: "bi-code-slash", label: "Developer Portal", roles: ["admin", "director"] },
    ],
  },
  {
    key: "adm_notifications",
    label: "Уведомления",
    icon: "bi-bell",
    items: [
      { href: "/admin/notifications/broadcasts", icon: "bi-megaphone", label: "Рассылки", roles: ["admin", "director"] },
      { href: "/admin/notification-templates", icon: "bi-file-earmark-text", label: "Шаблоны уведомлений", roles: ["admin"] },
    ],
  },
  {
    key: "adm_learning",
    label: "Обучение",
    icon: "bi-mortarboard",
    items: [
      { href: "/admin/onboarding/courses", icon: "bi-collection-fill", label: "Курсы и онбординг", roles: ["admin", "director"] },
      { href: "/admin/onboarding/analytics", icon: "bi-bar-chart", label: "Аналитика онбординга", roles: ["admin", "director"] },
    ],
  },
  {
    key: "adm_data",
    label: "Данные",
    icon: "bi-database",
    items: [
      { href: "/admin/custom-fields", icon: "bi-layout-text-window-reverse", label: "Кастомные поля", roles: ["admin", "director"] },
      { href: "/admin/duplicates", icon: "bi-people-fill", label: "Дубликаты", roles: ["admin", "director"] },
      { href: "/admin/bulk-tasks", icon: "bi-archive-fill", label: "Bulk-задачи", roles: ["admin", "lawyer", "director"] },
    ],
  },
];

// Финансовый дашборд — верхний уровень, вне групп
const FINANCE_DASHBOARD: Item = { href: "/finance", icon: "bi-cash-coin", label: "Финансы" };

type NavGroup = { key: string; label: string; icon: string; items: Item[] };

const FINANCE_GROUPS: NavGroup[] = [
  {
    key: "fin_acct",
    label: "Учёт",
    icon: "bi-journal-bookmark",
    items: [
      { href: "/finance/operations", icon: "bi-arrow-left-right", label: "Операции" },
      { href: "/finance/accounts", icon: "bi-bank", label: "Счета и Баланс" },
      { href: "/finance/cashflow", icon: "bi-bar-chart-line", label: "ДДС" },
      { href: "/finance/categories", icon: "bi-tags", label: "Статьи ДДС", roles: ["accountant", "cfo", "admin"] },
      { href: "/finance/journals", icon: "bi-journal-text", label: "Журналы", roles: ["accountant", "cfo", "admin"] },
    ],
  },
  {
    key: "fin_approval",
    label: "Согласование",
    icon: "bi-check2-square",
    items: [
      {
        href: "/finance/requests",
        icon: "bi-file-earmark-text",
        label: "Заявки",
        roles: ["manager", "accountant", "cfo", "director", "admin"],
      },
      {
        href: "/finance/approvals",
        icon: "bi-check2-square",
        label: "Согласования",
        roles: ["accountant", "cfo", "director", "admin"],
      },
      {
        href: "/finance/registries",
        icon: "bi-list-check",
        label: "Реестр платежей",
        roles: ["accountant", "cfo", "admin"],
      },
      {
        href: "/finance/settings/approval-scenarios",
        icon: "bi-diagram-3",
        label: "Сценарии согл.",
        roles: ["cfo", "admin"],
      },
    ],
  },
  {
    key: "fin_docs",
    label: "Документы",
    icon: "bi-files",
    items: [
      {
        href: "/finance/invoices",
        icon: "bi-receipt",
        label: "Инвойсы",
        roles: ["accountant", "cfo", "director", "admin", "manager"],
      },
      {
        href: "/finance/acts",
        icon: "bi-file-earmark-check",
        label: "Акты",
        roles: ["accountant", "cfo", "director", "admin", "manager"],
      },
      {
        href: "/finance/vendor-bills",
        icon: "bi-file-earmark-minus",
        label: "Счета поставщиков",
        roles: ["accountant", "cfo", "admin"],
      },
    ],
  },
  {
    key: "fin_reports",
    label: "Отчёты",
    icon: "bi-graph-up-arrow",
    items: [
      // Хаб отчётов: точный match, чтобы не подсвечиваться на дочерних ar-aging/ap-aging/vat
      { href: "/finance/reports", icon: "bi-graph-up-arrow", label: "Отчёты", exact: true, roles: ["accountant", "cfo", "director", "admin"] },
      { href: "/finance/calendar", icon: "bi-calendar-check", label: "Платёжный календарь", roles: ["accountant", "cfo", "director", "admin"] },
      { href: "/finance/reports/recognition", icon: "bi-calendar-check", label: "Признание выручки", roles: ["accountant", "cfo", "director", "admin"] },
      { href: "/finance/reports/vat", icon: "bi-percent", label: "НДС", roles: ["accountant", "cfo", "director", "admin"] },
      { href: "/finance/reports/debt", icon: "bi-hourglass-split", label: "Задолженность", roles: ["accountant", "cfo", "director", "admin"] },
    ],
  },
  {
    key: "fin_settings",
    label: "Настройки",
    icon: "bi-sliders",
    items: [
      { href: "/finance/settings/period-lock", icon: "bi-lock", label: "Период", roles: ["cfo", "admin"] },
      { href: "/finance/settings/base-currency", icon: "bi-arrow-repeat", label: "Переоценка остатков", roles: ["cfo", "admin"] },
      { href: "/finance/settings/op-types", icon: "bi-sliders", label: "Типы операций", roles: ["cfo", "admin"] },
      { href: "/finance/settings/reconciliation", icon: "bi-check2-square", label: "Сверка комиссий", roles: ["cfo", "admin"] },
    ],
  },
];

const FINANCE_ROLES: UserRole[] = ["manager", "accountant", "cfo", "director", "admin"];

// cfo входит в список, чтобы видеть админ-секцию ради пункта «Финансы — права доступа»
// (фильтр per-item по roles не покажет ему другие пункты — все они с явными roles без cfo).
const ADMIN_ROLES: UserRole[] = ["admin", "director", "lawyer", "cfo"];
const MAX_PINNED_IN_SIDEBAR = 8;

function NavItem({ item, pathname, collapsed }: { item: Item; pathname: string; collapsed?: boolean }) {
  const active = isItemActive(item, pathname);
  return (
    <Link
      href={item.href}
      className={
        "group relative flex items-center rounded-md py-2 text-sm transition-colors " +
        (collapsed ? "justify-center px-0 " : "gap-3 px-3 ") +
        (active ? "bg-primary text-white" : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700")
      }
    >
      <i className={`bi ${item.icon} text-base`} />
      {!collapsed && item.label}
      {collapsed && <SidebarTooltip label={item.label} />}
    </Link>
  );
}

export function Sidebar() {
  const pathname = usePathname();
  const router = useRouter();
  const { user } = useMe();
  const openSearch = useOpenSearch();

  const { filters: pinnedFilters, isLoading: pinsLoading, mutate: mutatePins } = usePinnedFilters();
  const [localPins, setLocalPins] = useState<SavedFilter[]>([]);

  // Sync localPins from SWR data when it changes
  useEffect(() => {
    setLocalPins(pinnedFilters);
  }, [pinnedFilters]);
  const { overdue: overdueCount, inProgress: inProgressCount } = useOnboardingBadge();
  const openTasksCount = useOpenTasksCount();

  const [collapsed, setCollapsed] = useState(() => {
    if (typeof window === "undefined") return false;
    return localStorage.getItem("sidebar_collapsed") === "true";
  });

  function toggleCollapsed() {
    const next = !collapsed;
    setCollapsed(next);
    try { localStorage.setItem("sidebar_collapsed", String(next)); } catch { /* */ }
  }

  const [segmentsExpanded, setSegmentsExpanded] = useState(() => {
    if (typeof window === "undefined") return true;
    const stored = localStorage.getItem("segments_sidebar_expanded");
    return stored !== "false";
  });

  // Финансы: раскрытие под-групп (collapsible). Раскрытие хранится per-группа,
  // активная группа (по текущему роуту) авто-раскрывается независимо от хранимого флага.
  const [financeExpanded, setFinanceExpanded] = useState<Record<string, boolean>>(() => {
    if (typeof window === "undefined") return {};
    try {
      const stored = localStorage.getItem("finance_groups_expanded");
      return stored ? (JSON.parse(stored) as Record<string, boolean>) : {};
    } catch {
      return {};
    }
  });

  // Админ: раскрытие под-групп (collapsible, per-группа, как у Финансов)
  const [adminGroupsExpanded, setAdminGroupsExpanded] = useState<Record<string, boolean>>(() => {
    if (typeof window === "undefined") return {};
    try {
      const stored = localStorage.getItem("admin_groups_expanded");
      return stored ? (JSON.parse(stored) as Record<string, boolean>) : {};
    } catch {
      return {};
    }
  });

  function toggleFinanceGroup(key: string) {
    setFinanceExpanded((prev) => {
      const next = { ...prev, [key]: !prev[key] };
      try { localStorage.setItem("finance_groups_expanded", JSON.stringify(next)); } catch { /* */ }
      return next;
    });
  }

  function toggleAdminGroup(key: string) {
    setAdminGroupsExpanded((prev) => {
      const next = { ...prev, [key]: !prev[key] };
      try { localStorage.setItem("admin_groups_expanded", JSON.stringify(next)); } catch { /* */ }
      return next;
    });
  }

  function toggleSegments() {
    const next = !segmentsExpanded;
    setSegmentsExpanded(next);
    try { localStorage.setItem("segments_sidebar_expanded", String(next)); } catch { /* */ }
  }

  async function logout() {
    await api("/auth/logout", { method: "POST" });
    router.push("/login");
  }

  const shownPins = localPins.slice(0, MAX_PINNED_IN_SIDEBAR);

  function handlePinsDragEnd(event: DragEndEvent) {
    const { active, over } = event;
    if (!over || active.id === over.id) return;
    const oldIdx = localPins.findIndex((f) => f.id === active.id);
    const newIdx = localPins.findIndex((f) => f.id === over.id);
    if (oldIdx < 0 || newIdx < 0) return;
    const reordered = arrayMove(localPins, oldIdx, newIdx);
    setLocalPins(reordered);

    void api("/saved-filters/reorder", {
      method: "PATCH",
      body: reordered.map((f, idx) => ({ id: f.id, sort_order: idx })),
    }).catch(() => {
      setLocalPins(pinnedFilters);
    });
  }

  // Detect Mac for keyboard hint
  const [isMac, setIsMac] = useState(false);
  useEffect(() => {
    setIsMac(/Mac/.test(navigator.platform));
  }, []);

  // Видимые admin-группы (фильтрация по роли)
  const visibleAdminGroups = user
    ? ADMIN_GROUPS.map((g) => ({
        ...g,
        items: g.items.filter((it) => !it.roles || it.roles.includes(user.role)),
      })).filter((g) => g.items.length > 0)
    : [];

  return (
    <aside
      className={
        "shrink-0 border-r border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 h-screen sticky top-0 flex flex-col transition-[width] duration-200 ease-in-out " +
        (collapsed ? "w-[64px]" : "w-[240px]")
      }
    >
      <div
        className={
          "border-b border-gray-200 dark:border-gray-700 " +
          (collapsed ? "p-3" : "p-5")
        }
      >
        {collapsed ? (
          <div className="flex flex-col items-center gap-2">
            <Logo collapsed />
            <button
              onClick={toggleCollapsed}
              aria-label="Развернуть меню"
              className="flex items-center justify-center h-7 w-7 rounded-md text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 hover:text-gray-600 dark:hover:text-gray-300 transition-colors"
            >
              <i className="bi bi-chevron-double-right text-sm" />
            </button>
          </div>
        ) : (
          <div className="flex items-center">
            <Logo />
            <button
              onClick={toggleCollapsed}
              aria-label="Свернуть меню"
              className="ml-auto flex items-center justify-center h-7 w-7 rounded-md text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 hover:text-gray-600 dark:hover:text-gray-300 transition-colors"
            >
              <i className="bi bi-chevron-double-left text-sm" />
            </button>
          </div>
        )}
      </div>

      {/* Main nav */}
      <nav className="flex-1 p-3 space-y-0.5 overflow-y-auto">

        {/* Секция: Продажи (без заголовка) */}
        {SALES_ITEMS.map((it) =>
          it.href === "/tasks" ? (
            <NavItemWithBadge
              key={it.href}
              item={it}
              pathname={pathname}
              collapsed={collapsed}
              badgeCount={openTasksCount > 0 ? openTasksCount : undefined}
              badgeColor={openTasksCount > 0 ? "bg-warning" : undefined}
            />
          ) : (
            <NavItem key={it.href} item={it} pathname={pathname} collapsed={collapsed} />
          )
        )}

        {/* Секция: CS */}
        <div className="pt-2">
          {collapsed ? (
            <div className="my-1 border-t border-gray-100 dark:border-gray-700 mx-2" />
          ) : (
            <div className="px-3 py-1 text-[10px] font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">CS</div>
          )}
          {CS_ITEMS.map((it) => (
            <NavItem key={it.href} item={it} pathname={pathname} collapsed={collapsed} />
          ))}
        </div>

        {/* Секция: Финансы (только для финансовых ролей) — collapsible под-группы */}
        {user && FINANCE_ROLES.includes(user.role) && (
          <div className="pt-2">
            {collapsed ? (
              <div className="my-1 border-t border-gray-100 dark:border-gray-700 mx-2" />
            ) : (
              <div className="px-3 py-1 text-[10px] font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Финансы</div>
            )}
            {/* Дашборд — верхний уровень */}
            <NavItem item={FINANCE_DASHBOARD} pathname={pathname} collapsed={collapsed} />
            {FINANCE_GROUPS.map((group) => {
              const visibleItems = group.items.filter((it) => !it.roles || it.roles.includes(user.role));
              if (visibleItems.length === 0) return null;

              if (collapsed) {
                const groupActive = visibleItems.some((it) => isItemActive(it, pathname));
                return (
                  <div key={group.key} className="group relative">
                    <div
                      className={
                        "flex items-center justify-center rounded-md py-2 text-sm cursor-default transition-colors " +
                        (groupActive ? "bg-primary text-white" : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700")
                      }
                    >
                      <i className={`bi ${group.icon} text-base`} />
                    </div>
                    <div className="absolute left-full top-0 ml-2 z-50 min-w-[200px] rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-lg p-1 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition">
                      <div className="px-2 py-1 text-[10px] font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">{group.label}</div>
                      {visibleItems.map((it) => (
                        <NavItem key={it.href} item={it} pathname={pathname} />
                      ))}
                    </div>
                  </div>
                );
              }

              const hasActive = visibleItems.some((it) => isItemActive(it, pathname));
              const expanded = hasActive || financeExpanded[group.key] === true;
              return (
                <div key={group.key}>
                  <button
                    onClick={() => toggleFinanceGroup(group.key)}
                    className="flex items-center gap-3 rounded-md px-3 py-2 text-sm w-full text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                  >
                    <i className={`bi ${group.icon} text-base`} />
                    <span className="flex-1 text-left">{group.label}</span>
                    <i className={`bi ${expanded ? "bi-chevron-up" : "bi-chevron-down"} text-xs text-gray-400`} />
                  </button>
                  {expanded && (
                    <div className="ml-3 pl-1 border-l border-gray-100 dark:border-gray-700 space-y-0.5">
                      {visibleItems.map((it) => (
                        <NavItem key={it.href} item={it} pathname={pathname} />
                      ))}
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        )}

        {/* Секция: Аналитика */}
        <div className="pt-2">
          {collapsed ? (
            <div className="my-1 border-t border-gray-100 dark:border-gray-700 mx-2" />
          ) : (
            <div className="px-3 py-1 text-[10px] font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Аналитика</div>
          )}
          {ANALYTICS_ITEMS.map((it) => (
            <NavItem key={it.href} item={it} pathname={pathname} collapsed={collapsed} />
          ))}
        </div>

        {/* Секция: Обучение (для всех ролей) */}
        <div className="pt-2">
          {collapsed ? (
            <div className="my-1 border-t border-gray-100 dark:border-gray-700 mx-2" />
          ) : (
            <div className="px-3 py-1 text-[10px] font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Обучение</div>
          )}
          {LEARNING_ITEMS.map((it) => (
            <NavItemWithBadge
              key={it.href}
              item={it}
              pathname={pathname}
              collapsed={collapsed}
              badgeCount={overdueCount > 0 ? overdueCount : inProgressCount > 0 ? inProgressCount : undefined}
              badgeColor={overdueCount > 0 ? "bg-danger" : "bg-info"}
            />
          ))}
        </div>

        {/* Секция: Настройки (сворачиваемые группы, только admin/director/lawyer) */}
        <RoleGate allowed={ADMIN_ROLES}>
          {visibleAdminGroups.length > 0 && (
            <div className={collapsed ? "pt-2 mt-2" : "pt-2 border-t border-gray-100 dark:border-gray-700 mt-2"}>
              {collapsed ? (
                <div className="my-1 border-t border-gray-100 dark:border-gray-700 mx-2" />
              ) : (
                <div className="px-3 py-1 text-[10px] font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Настройки</div>
              )}
              {visibleAdminGroups.map((group) => {
                if (collapsed) {
                  const groupActive = group.items.some((it) => isItemActive(it, pathname));
                  return (
                    <div key={group.key} className="group relative">
                      <div
                        className={
                          "flex items-center justify-center rounded-md py-2 text-sm cursor-default transition-colors " +
                          (groupActive ? "bg-primary text-white" : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700")
                        }
                      >
                        <i className={`bi ${group.icon} text-base`} />
                      </div>
                      <div className="absolute left-full top-0 ml-2 z-50 min-w-[200px] rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-lg p-1 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition">
                        <div className="px-2 py-1 text-[10px] font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">{group.label}</div>
                        {group.items.map((it) => (
                          <NavItem key={it.href} item={it} pathname={pathname} />
                        ))}
                      </div>
                    </div>
                  );
                }

                const hasActive = group.items.some((it) => isItemActive(it, pathname));
                const expanded = hasActive || adminGroupsExpanded[group.key] === true;
                return (
                  <div key={group.key}>
                    <button
                      onClick={() => toggleAdminGroup(group.key)}
                      className="flex items-center gap-3 rounded-md px-3 py-2 text-sm w-full text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                    >
                      <i className={`bi ${group.icon} text-base`} />
                      <span className="flex-1 text-left">{group.label}</span>
                      <i className={`bi ${expanded ? "bi-chevron-up" : "bi-chevron-down"} text-xs text-gray-400`} />
                    </button>
                    {expanded && (
                      <div className="ml-3 pl-1 border-l border-gray-100 dark:border-gray-700 space-y-0.5">
                        {group.items.map((it) => (
                          <NavItem key={it.href} item={it} pathname={pathname} />
                        ))}
                      </div>
                    )}
                  </div>
                );
              })}
            </div>
          )}
        </RoleGate>

        {/* Сегменты */}
        {collapsed ? (
          <div className="pt-2 mt-2">
            <div className="my-1 border-t border-gray-100 dark:border-gray-700 mx-2" />
            <Link
              href="/profile?tab=segments"
              className={
                "group relative flex items-center justify-center rounded-md py-2 text-sm transition-colors " +
                (pathname === "/profile"
                  ? "text-primary"
                  : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700")
              }
            >
              <i className="bi bi-bookmark-star-fill text-base" />
              <SidebarTooltip label="Мои сегменты" />
            </Link>
          </div>
        ) : (
        <div className="pt-2 border-t border-gray-100 dark:border-gray-700 mt-2">
          <button
            onClick={toggleSegments}
            className="flex items-center justify-between w-full px-3 py-1.5 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hover:bg-gray-50 dark:hover:bg-gray-700 rounded-md transition-colors"
          >
            <span className="flex items-center gap-1.5">
              <i className="bi bi-bookmark-star-fill text-gray-400" />
              Мои сегменты
            </span>
            <i className={`bi ${segmentsExpanded ? "bi-chevron-up" : "bi-chevron-down"} text-gray-400`} />
          </button>

          {segmentsExpanded && (
            <div className="mt-1 space-y-0.5">
              {pinsLoading && (
                <div className="animate-pulse px-3 space-y-1.5 py-1">
                  {[1, 2, 3].map((i) => (
                    <div key={i} className="h-7 bg-gray-200 dark:bg-gray-700 rounded" />
                  ))}
                </div>
              )}
              {!pinsLoading && shownPins.length === 0 && (
                <div className="px-3 py-2 text-xs text-gray-400 dark:text-gray-500">
                  Нет закреплённых сегментов
                </div>
              )}
              {!pinsLoading && shownPins.length > 0 && (
                <DndContext collisionDetection={closestCenter} onDragEnd={handlePinsDragEnd}>
                  <SortableContext
                    items={shownPins.map((f) => f.id)}
                    strategy={verticalListSortingStrategy}
                  >
                    {shownPins.map((f) => (
                      <SortableItem key={f.id} id={f.id} className="rounded-md">
                        <SavedFilterPin
                          filter={f}
                          isActive={false}
                          onRemovePin={() => mutatePins()}
                        />
                      </SortableItem>
                    ))}
                  </SortableContext>
                </DndContext>
              )}
              <Link
                href="/profile?tab=segments"
                className="flex items-center gap-2 px-3 py-1.5 text-xs text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 rounded-md transition-colors"
              >
                <i className="bi bi-arrow-right text-xs" />
                Все сегменты…
              </Link>
            </div>
          )}
        </div>
        )}
      </nav>

      {/* Search button + Notifications + Theme */}
      {collapsed ? (
        <div className="px-2 py-2 border-t border-gray-100 dark:border-gray-700 flex flex-col items-center gap-1">
          <button
            onClick={openSearch}
            aria-label="Поиск"
            className="group relative flex items-center justify-center h-9 w-9 rounded-md bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
          >
            <i className="bi bi-search text-sm" />
            <SidebarTooltip label={isMac ? "Поиск ⌘K" : "Поиск Ctrl+K"} />
          </button>
          <NotificationBell />
          <ThemeToggle />
        </div>
      ) : (
        <div className="px-3 py-2 border-t border-gray-100 dark:border-gray-700 space-y-1">
          <button
            onClick={openSearch}
            className="bg-gray-100 dark:bg-gray-700 rounded-md px-3 py-2 text-sm text-gray-500 dark:text-gray-400 flex items-center gap-2 w-full hover:bg-gray-200 dark:hover:bg-gray-600 cursor-text transition-colors"
          >
            <i className="bi bi-search text-sm" />
            <span className="flex-1 text-left">Поиск…</span>
            <kbd className="text-xs text-gray-400 font-mono border border-gray-200 dark:border-gray-600 rounded px-1 bg-white dark:bg-gray-800">
              {isMac ? "⌘K" : "Ctrl+K"}
            </kbd>
          </button>
          <div className="flex items-center gap-1 px-1">
            <NotificationBell />
            <ThemeToggle />
          </div>
        </div>
      )}

      {/* Profile / Logout */}
      {collapsed ? (
        <div className="border-t border-gray-200 dark:border-gray-700 p-3 flex flex-col items-center gap-1">
          {user && (
            <Link
              href="/profile"
              className="group relative flex items-center justify-center rounded-md p-1 transition-colors hover:bg-gray-100 dark:hover:bg-gray-700"
            >
              <Avatar userId={user.id} name={user.full_name} hasAvatar={!!user.avatar_path} size={36} />
              <SidebarTooltip label={user.full_name} />
            </Link>
          )}
          <button
            onClick={logout}
            aria-label="Выйти"
            className="group relative flex items-center justify-center h-9 w-9 rounded-md text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
          >
            <i className="bi bi-box-arrow-right text-base" />
            <SidebarTooltip label="Выйти" />
          </button>
        </div>
      ) : (
      <div className="border-t border-gray-200 dark:border-gray-700 p-3">
        {user && (
          <Link
            href="/profile"
            className={
              "flex items-center gap-3 rounded-md p-2 transition-colors " +
              (pathname === "/profile"
                ? "bg-gray-100 dark:bg-gray-700"
                : "hover:bg-gray-100 dark:hover:bg-gray-700")
            }
          >
            <Avatar userId={user.id} name={user.full_name} hasAvatar={!!user.avatar_path} size={36} />
            <div className="min-w-0 flex-1">
              <div className="text-sm font-medium text-primary dark:text-gray-100 truncate">{user.full_name}</div>
              <div className="text-xs text-gray-600 dark:text-gray-400">{RoleLabels[user.role]}</div>
            </div>
          </Link>
        )}

        {/* Подпункты профиля */}
        <div className="mt-1 space-y-0.5 pl-2">
          <Link
            href="/profile"
            className={
              "flex items-center gap-2 rounded-md px-2 py-1 text-xs transition-colors " +
              (pathname === "/profile"
                ? "text-primary dark:text-primary-light font-medium"
                : "text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700")
            }
          >
            <i className="bi bi-person text-xs" />
            Личное
          </Link>
          <Link
            href="/profile?tab=security"
            className="flex items-center gap-2 rounded-md px-2 py-1 text-xs transition-colors text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700"
          >
            <i className="bi bi-shield-lock text-xs" />
            Безопасность
          </Link>
          <Link
            href="/profile?tab=notifications"
            className="flex items-center gap-2 rounded-md px-2 py-1 text-xs transition-colors text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700"
          >
            <i className="bi bi-bell text-xs" />
            Уведомления
          </Link>
          <Link
            href="/me/vacations"
            className={
              "flex items-center gap-2 rounded-md px-2 py-1 text-xs transition-colors " +
              (pathname === "/me/vacations"
                ? "text-primary dark:text-primary-light font-medium"
                : "text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700")
            }
          >
            <i className="bi bi-airplane text-xs" />
            Мои отпуска
          </Link>
        </div>

        <button onClick={logout} className="btn-ghost w-full justify-start mt-1">
          <i className="bi bi-box-arrow-right" /> Выйти
        </button>
      </div>
      )}
    </aside>
  );
}
