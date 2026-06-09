"use client";

import { VisibilityScopeSelect } from "./VisibilityScopeSelect";
import { RoleLabels } from "@/lib/types";
import type { DepartmentScope, VisibilityRole } from "@/lib/types";

/** Роли в порядке отображения. admin/director идут первыми (обычно «Вся база»). */
const ROLES: VisibilityRole[] = [
  "admin", "director", "lawyer", "manager", "accountant", "cfo",
];

/** Роли, для которых ограничение scope ниже «Вся база» опасно (скрывает данные руководства). */
const LEADERSHIP_ROLES: ReadonlySet<VisibilityRole> = new Set<VisibilityRole>(["admin", "director"]);

const ROLE_HINTS: Partial<Record<VisibilityRole, string>> = {
  admin: "Администратор всегда видит всё (бэкенд игнорирует ограничение)",
  director: "Руководитель обычно видит всю базу",
};

interface VisibilityRoleMatrixProps {
  /** Текущий scope по каждой роли. */
  scopes: Record<VisibilityRole, DepartmentScope>;
  onChange: (role: VisibilityRole, scope: DepartmentScope) => void;
  loading?: boolean;
}

export function VisibilityRoleMatrix({ scopes, onChange, loading = false }: VisibilityRoleMatrixProps) {
  if (loading) {
    return (
      <div className="card overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 dark:bg-gray-800/50">
            <tr>
              <th className="text-left px-4 py-3 font-semibold text-gray-700 dark:text-gray-200 w-48">Роль</th>
              <th className="text-left px-4 py-3 font-semibold text-gray-700 dark:text-gray-200">Видимость базы</th>
            </tr>
          </thead>
          <tbody>
            {ROLES.map((r) => (
              <tr key={r} className="border-t border-gray-200 dark:border-gray-700">
                <td className="px-4 py-3 font-medium text-gray-900 dark:text-gray-100">{RoleLabels[r]}</td>
                <td className="px-4 py-3"><div className="animate-pulse h-8 w-48 bg-gray-100 dark:bg-gray-700 rounded" /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    );
  }

  return (
    <div className="card overflow-x-auto">
      <table className="w-full text-sm">
        <thead className="bg-gray-50 dark:bg-gray-800/50">
          <tr>
            <th className="text-left px-4 py-3 font-semibold text-gray-700 dark:text-gray-200 w-48">Роль</th>
            <th className="text-left px-4 py-3 font-semibold text-gray-700 dark:text-gray-200">Видимость клиентской базы</th>
          </tr>
        </thead>
        <tbody>
          {ROLES.map((role) => {
            const scope = scopes[role] ?? "all";
            const restricted = LEADERSHIP_ROLES.has(role) && scope !== "all";
            const hint = ROLE_HINTS[role];
            return (
              <tr key={role} className="border-t border-gray-200 dark:border-gray-700 hover:bg-gray-50/50 dark:hover:bg-gray-800/30">
                <td className="px-4 py-3 align-top">
                  <div className="font-medium text-gray-900 dark:text-gray-100">{RoleLabels[role]}</div>
                  {hint && <div className="text-xs text-gray-400 dark:text-gray-500 mt-0.5">{hint}</div>}
                </td>
                <td className="px-4 py-3">
                  <div className="flex flex-col gap-1.5">
                    <div className="max-w-xs">
                      <VisibilityScopeSelect value={scope} onChange={(v) => onChange(role, v)} />
                    </div>
                    {restricted && (
                      <span className="inline-flex items-center gap-1.5 text-xs text-warning-700 dark:text-warning-400">
                        <i className="bi bi-exclamation-triangle" />
                        Ограничение для роли «{RoleLabels[role]}» может скрыть данные от руководства
                      </span>
                    )}
                  </div>
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

export { ROLES as VISIBILITY_ROLES };
