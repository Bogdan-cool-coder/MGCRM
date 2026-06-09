"use client";

import { useState, useEffect } from "react";
import useSWR from "swr";
import clsx from "clsx";
import { PageHeader } from "@/components/PageHeader";
import { RoleGate } from "@/components/RoleGate";
import { VisibilityMatrix } from "@/components/Visibility/VisibilityMatrix";
import { VisibilityRoleMatrix, VISIBILITY_ROLES } from "@/components/Visibility/VisibilityRoleMatrix";
import { useToast } from "@/components/ui/Toast";
import { api, ApiError, fetcher } from "@/lib/api";
import type {
  VisibilitySetting,
  DepartmentScope,
  VisibilityEntityType,
  VisibilityRole,
} from "@/lib/types";

type EntityRole = "director" | "lawyer" | "manager";

const ENTITY_TYPES: VisibilityEntityType[] = [
  "lead", "deal", "counterparty", "company", "subscription", "contract",
];

const ENTITY_ROLES: EntityRole[] = ["director", "lawyer", "manager"];

function errText(e: unknown): string {
  return e instanceof ApiError
    ? String((e.detail as { detail?: string })?.detail ?? e.message)
    : "Не удалось сохранить настройки. Попробуй ещё раз.";
}

// ============ Tab 1: детальная матрица сущность × роль ============

function buildEntityMatrix(settings: VisibilitySetting[]): Record<string, DepartmentScope> {
  const result: Record<string, DepartmentScope> = {};
  for (const et of ENTITY_TYPES) {
    for (const role of ENTITY_ROLES) result[`${et}:${role}`] = "all";
  }
  for (const s of settings) {
    if (ENTITY_ROLES.includes(s.applies_to_role as EntityRole)) {
      result[`${s.entity_type}:${s.applies_to_role}`] = s.scope;
    }
  }
  return result;
}

function entityMatrixToRules(matrix: Record<string, DepartmentScope>) {
  const rules: { entity_type: string; applies_to_role: string; scope: DepartmentScope }[] = [];
  for (const et of ENTITY_TYPES) {
    for (const role of ENTITY_ROLES) {
      rules.push({ entity_type: et, applies_to_role: role, scope: matrix[`${et}:${role}`] ?? "all" });
    }
  }
  return rules;
}

function buildEntityAllAll(): Record<string, DepartmentScope> {
  const result: Record<string, DepartmentScope> = {};
  for (const et of ENTITY_TYPES) {
    for (const role of ENTITY_ROLES) result[`${et}:${role}`] = "all";
  }
  return result;
}

// ============ Tab 2: матрица «видимость по ролям» (единый scope роли) ============

function buildRoleScopes(settings: VisibilitySetting[]): Record<VisibilityRole, DepartmentScope> {
  // Базовое значение — «все»
  const result = {} as Record<VisibilityRole, DepartmentScope>;
  for (const role of VISIBILITY_ROLES) result[role] = "all";
  // Считаем самый частый scope среди строк роли (по всем сущностям)
  const counts = {} as Record<VisibilityRole, Partial<Record<DepartmentScope, number>>>;
  for (const role of VISIBILITY_ROLES) counts[role] = {};
  for (const s of settings) {
    if ((VISIBILITY_ROLES as readonly string[]).includes(s.applies_to_role)) {
      const c = counts[s.applies_to_role];
      c[s.scope] = (c[s.scope] ?? 0) + 1;
    }
  }
  for (const role of VISIBILITY_ROLES) {
    let best: DepartmentScope | null = null;
    let bestN = 0;
    for (const [scope, n] of Object.entries(counts[role]) as [DepartmentScope, number][]) {
      if (n > bestN) { best = scope; bestN = n; }
    }
    if (best) result[role] = best;
  }
  return result;
}

function roleScopesToRules(scopes: Record<VisibilityRole, DepartmentScope>) {
  // Единый scope роли применяется ко всем сущностям
  const rules: { entity_type: string; applies_to_role: string; scope: DepartmentScope }[] = [];
  for (const role of VISIBILITY_ROLES) {
    for (const et of ENTITY_TYPES) {
      rules.push({ entity_type: et, applies_to_role: role, scope: scopes[role] });
    }
  }
  return rules;
}

// ============ Page ============

type Tab = "roles" | "entities";

const HELP =
  "Вся база — видит все сделки/контакты/договоры/подписки; " +
  "Только своё — лишь записи, где пользователь ответственный/автор; " +
  "Свой отдел — записи своего отдела.";

export default function VisibilityPage() {
  const { data: settings, error: loadError, isLoading } = useSWR<VisibilitySetting[]>(
    "/admin/visibility-settings",
    fetcher,
  );
  const { toast } = useToast();

  const [tab, setTab] = useState<Tab>("roles");

  // --- per-role state ---
  const [roleScopes, setRoleScopes] = useState<Record<VisibilityRole, DepartmentScope>>(
    {} as Record<VisibilityRole, DepartmentScope>,
  );
  const [savedRoleScopes, setSavedRoleScopes] = useState<Record<VisibilityRole, DepartmentScope>>(
    {} as Record<VisibilityRole, DepartmentScope>,
  );

  // --- per-entity state ---
  const [entityMatrix, setEntityMatrix] = useState<Record<string, DepartmentScope>>({});
  const [savedEntityMatrix, setSavedEntityMatrix] = useState<Record<string, DepartmentScope>>({});

  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!settings) return;
    const rs = buildRoleScopes(settings);
    setRoleScopes(rs);
    setSavedRoleScopes(rs);
    const em = buildEntityMatrix(settings);
    setEntityMatrix(em);
    setSavedEntityMatrix(em);
  }, [settings]);

  const roleDirty = JSON.stringify(roleScopes) !== JSON.stringify(savedRoleScopes);
  const entityDirty = JSON.stringify(entityMatrix) !== JSON.stringify(savedEntityMatrix);

  async function saveRules(
    rules: { entity_type: string; applies_to_role: string; scope: DepartmentScope }[],
    onSuccess: () => void,
  ) {
    setSaving(true);
    try {
      await api("/admin/visibility-settings", { method: "PATCH", body: { rules } });
      onSuccess();
      toast.success("Настройки видимости сохранены");
    } catch (err) {
      toast.error(errText(err));
    } finally {
      setSaving(false);
    }
  }

  function saveRoles() {
    void saveRules(roleScopesToRules(roleScopes), () => setSavedRoleScopes({ ...roleScopes }));
  }

  function saveEntities() {
    void saveRules(entityMatrixToRules(entityMatrix), () => setSavedEntityMatrix({ ...entityMatrix }));
  }

  return (
    <RoleGate allowed={["admin", "director"]} fallback={<NoAccess />}>
      <PageHeader
        title="Настройки видимости"
        description="Настрой, какие записи видит каждая роль: только свои, свой отдел или всю базу"
      />

      <div className="p-8 max-w-4xl">
        {loadError && (
          <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded mb-4">
            Не удалось загрузить настройки. Попробуй обновить страницу.
          </div>
        )}

        {/* Tabs */}
        <div className="flex gap-1 border-b border-gray-200 dark:border-gray-700 mb-5">
          <TabBtn active={tab === "roles"} onClick={() => setTab("roles")}>
            По ролям
          </TabBtn>
          <TabBtn active={tab === "entities"} onClick={() => setTab("entities")}>
            По сущностям
          </TabBtn>
        </div>

        {/* Help */}
        <div className="text-sm text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-800/40 rounded-lg px-4 py-3 mb-5">
          <i className="bi bi-info-circle mr-1.5" />
          {HELP}
        </div>

        {tab === "roles" ? (
          <>
            <p className="text-sm text-gray-600 dark:text-gray-300 mb-3">
              Единый уровень доступа на роль — применяется ко всем сущностям (сделки, контакты,
              договоры, подписки).
            </p>
            <VisibilityRoleMatrix scopes={roleScopes} onChange={(role, scope) =>
              setRoleScopes((prev) => ({ ...prev, [role]: scope }))} loading={isLoading} />

            {roleDirty && <DirtyBadge />}
            <div className="flex items-center justify-end gap-3 mt-6">
              <button
                className="btn-secondary"
                disabled={saving}
                onClick={() => {
                  const all = {} as Record<VisibilityRole, DepartmentScope>;
                  for (const r of VISIBILITY_ROLES) all[r] = "all";
                  setRoleScopes(all);
                }}
              >
                <i className="bi bi-arrow-counterclockwise mr-1" />
                Сбросить к «Вся база»
              </button>
              <button className="btn-primary" disabled={!roleDirty || saving} onClick={saveRoles}>
                {saving ? "Сохраняем…" : "Сохранить"}
              </button>
            </div>
          </>
        ) : (
          <>
            <p className="text-sm text-gray-600 dark:text-gray-300 mb-3">
              Точная настройка scope для каждой пары «сущность × роль».
            </p>
            <VisibilityMatrix matrix={entityMatrix} onChange={(key, value) =>
              setEntityMatrix((prev) => ({ ...prev, [key]: value }))} loading={isLoading} />

            {entityDirty && <DirtyBadge />}
            <div className="flex items-center justify-end gap-3 mt-6">
              <button
                className="btn-secondary"
                disabled={saving}
                onClick={() => setEntityMatrix(buildEntityAllAll())}
              >
                <i className="bi bi-arrow-counterclockwise mr-1" />
                Сбросить к «Все»
              </button>
              <button className="btn-primary" disabled={!entityDirty || saving} onClick={saveEntities}>
                {saving ? "Сохраняем…" : "Сохранить"}
              </button>
            </div>
          </>
        )}
      </div>
    </RoleGate>
  );
}

function TabBtn({ active, onClick, children }: {
  active: boolean; onClick: () => void; children: React.ReactNode;
}) {
  return (
    <button
      onClick={onClick}
      className={clsx(
        "px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors",
        active
          ? "border-primary text-primary dark:text-primary-light dark:border-primary-light"
          : "border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200",
      )}
    >
      {children}
    </button>
  );
}

function DirtyBadge() {
  return (
    <div className="flex items-center gap-2 text-sm mt-4">
      <span className="inline-flex items-center gap-1.5 rounded-full px-3 py-1 bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400 text-xs font-medium">
        <i className="bi bi-exclamation-circle" />
        Есть несохранённые изменения
      </span>
    </div>
  );
}

function NoAccess() {
  return (
    <div className="p-8">
      <div className="card flex flex-col items-center justify-center py-12 text-center">
        <i className="bi bi-shield-lock text-4xl text-gray-300 mb-3" />
        <p className="text-sm font-medium text-gray-500">Доступ только для администраторов и руководителей</p>
      </div>
    </div>
  );
}
