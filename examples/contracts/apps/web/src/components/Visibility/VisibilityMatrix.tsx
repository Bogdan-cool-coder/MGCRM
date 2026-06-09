"use client";

import { VisibilityScopeSelect } from "./VisibilityScopeSelect";
import type { DepartmentScope } from "@/lib/types";

type EntityType = "lead" | "deal" | "counterparty" | "company" | "subscription" | "contract";
type RoleType = "director" | "lawyer" | "manager";
type MatrixKey = `${EntityType}:${RoleType}`;

const ENTITY_LABELS: Record<EntityType, string> = {
  lead: "Лиды",
  deal: "Сделки",
  counterparty: "Контрагенты",
  company: "Компании",
  subscription: "Подписки",
  contract: "Контракты",
};

const ENTITY_TYPES: EntityType[] = [
  "lead", "deal", "counterparty", "company", "subscription", "contract",
];

const ROLES_TO_CONFIGURE: { role: RoleType; label: string }[] = [
  { role: "director", label: "Директор" },
  { role: "lawyer", label: "Юрист" },
  { role: "manager", label: "Менеджер" },
];

const SCOPE_TOOLTIPS: Record<DepartmentScope, string> = {
  all: "Без ограничений — роль видит все записи",
  personal: "Роль видит только свои записи (owner = он сам)",
  department: "Роль видит записи своего отдела",
  department_and_children: "Роль видит записи своего отдела и дочерних подразделений",
};

interface VisibilityMatrixProps {
  matrix: Record<string, DepartmentScope>;
  onChange: (key: string, value: DepartmentScope) => void;
  loading?: boolean;
}

export function VisibilityMatrix({ matrix, onChange, loading = false }: VisibilityMatrixProps) {
  if (loading) {
    return (
      <div className="card overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-gray-50">
            <tr>
              <th className="text-left px-4 py-3 font-semibold text-gray-700 w-40">Сущность</th>
              {ROLES_TO_CONFIGURE.map((r) => (
                <th key={r.role} className="text-center px-4 py-3 font-semibold text-gray-700">
                  {r.label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {ENTITY_TYPES.map((et) => (
              <tr key={et} className="border-t border-gray-200">
                <td className="px-4 py-3 font-medium text-gray-900">{ENTITY_LABELS[et]}</td>
                {ROLES_TO_CONFIGURE.map((r) => (
                  <td key={r.role} className="px-4 py-3 text-center">
                    <div className="animate-pulse h-8 w-full bg-gray-100 rounded" />
                  </td>
                ))}
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
        <thead className="bg-gray-50">
          <tr>
            <th className="text-left px-4 py-3 font-semibold text-gray-700 w-40">Сущность</th>
            {ROLES_TO_CONFIGURE.map((r) => (
              <th key={r.role} className="text-center px-4 py-3 font-semibold text-gray-700">
                {r.label}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {ENTITY_TYPES.map((et) => (
            <tr key={et} className="border-t border-gray-200 hover:bg-gray-50/50">
              <td className="px-4 py-3 font-medium text-gray-900">{ENTITY_LABELS[et]}</td>
              {ROLES_TO_CONFIGURE.map((r) => {
                const key: MatrixKey = `${et}:${r.role}`;
                const scope: DepartmentScope = (matrix[key] as DepartmentScope | undefined) ?? "all";
                return (
                  <td
                    key={r.role}
                    className="px-4 py-3 text-center"
                    title={SCOPE_TOOLTIPS[scope]}
                  >
                    <VisibilityScopeSelect
                      value={scope}
                      onChange={(v) => onChange(key, v)}
                    />
                  </td>
                );
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
