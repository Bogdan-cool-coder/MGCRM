"use client";

import { useMemo, useEffect, useRef, useState } from "react";
import useSWR from "swr";
import { fetcher } from "@/lib/api";
import type { Department, EmployeeListItem, EmploymentStatus } from "@/lib/types";
import { Avatar } from "@/components/Avatar";
import { EmployeeStatusBadge } from "./EmployeeStatusBadge";
import { EmployeeRowActions } from "./EmployeeRowActions";
import { DataTable } from "@/components/ui/DataTable";
import type { DataTableColumn } from "@/components/ui/DataTable";

type StatusFilter = EmploymentStatus | "all";

interface Props {
  onEdit: (employee: EmployeeListItem) => void;
  onDismiss: (employee: EmployeeListItem) => void;
  onRestore: (employee: EmployeeListItem) => void;
  onTransfer: (employee: EmployeeListItem) => void;
  onSchedule: (employee: EmployeeListItem) => void;
}

function useDebounce(value: string, ms: number): string {
  const [debounced, setDebounced] = useState(value);
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  useEffect(() => {
    timerRef.current = setTimeout(() => setDebounced(value), ms);
    return () => {
      if (timerRef.current) clearTimeout(timerRef.current);
    };
  }, [value, ms]);
  return debounced;
}

export function EmployeesTable({ onEdit, onDismiss, onRestore, onTransfer, onSchedule }: Props) {
  const [statusFilter, setStatusFilter] = useState<StatusFilter>("active");
  const [search, setSearch] = useState("");
  const [departmentId, setDepartmentId] = useState("");
  const debouncedSearch = useDebounce(search, 300);

  const swrKey = statusFilter === "all"
    ? "/admin/users/employees"
    : `/admin/users/employees?status=${statusFilter}`;

  const { data, error, isLoading } = useSWR<EmployeeListItem[]>(swrKey, fetcher);
  const { data: departments } = useSWR<Department[]>("/departments", fetcher);

  const filtered = useMemo(() => {
    if (!data) return undefined;
    const result = data.filter((e) => {
      const q = debouncedSearch.toLowerCase();
      const matchSearch = !q || e.full_name.toLowerCase().includes(q) || e.email.toLowerCase().includes(q);
      const matchDept = !departmentId || String(e.department_id) === departmentId;
      return matchSearch && matchDept;
    });
    return result;
  }, [data, debouncedSearch, departmentId]);

  const STATUS_TABS: { value: StatusFilter; label: string }[] = [
    { value: "active", label: "Активные" },
    { value: "on_vacation", label: "В отпуске" },
    { value: "dismissed", label: "Уволенные" },
    { value: "all", label: "Все" },
  ];

  const showSubstitute = (e: EmployeeListItem) =>
    e.employment_status === "on_vacation" || e.employment_status === "dismissed";

  const columns: DataTableColumn<EmployeeListItem>[] = [
    {
      key: "full_name",
      header: "ФИО",
      skeletonWidth: "70%",
      render: (emp) => (
        <div className="flex items-center gap-2">
          <Avatar
            userId={emp.id}
            name={emp.full_name}
            hasAvatar={!!emp.avatar_path}
            size={24}
          />
          <span className="font-medium text-gray-900 dark:text-gray-100">{emp.full_name}</span>
        </div>
      ),
    },
    {
      key: "department_name",
      header: "Отдел",
      skeletonWidth: "55%",
      render: (emp) => (
        <span className="text-gray-600 dark:text-gray-400">{emp.department_name ?? "—"}</span>
      ),
    },
    {
      key: "manager_name",
      header: "Руководитель",
      skeletonWidth: "60%",
      render: (emp) => (
        <span className="text-gray-600 dark:text-gray-400">{emp.manager_name ?? "—"}</span>
      ),
    },
    {
      key: "employment_status",
      header: "Статус",
      skeletonWidth: "40%",
      render: (emp) => <EmployeeStatusBadge status={emp.employment_status} />,
    },
    {
      key: "substitute_name",
      header: "Замещающий",
      skeletonWidth: "50%",
      render: (emp) => (
        <span className="text-gray-600 dark:text-gray-400">
          {showSubstitute(emp) ? (emp.substitute_name ?? "—") : "—"}
        </span>
      ),
    },
  ];

  // Rows: undefined = loading (DataTable показывает skeleton)
  const tableRows = isLoading ? undefined : (filtered ?? []);

  return (
    <div>
      {/* Status tabs */}
      <div className="flex items-center gap-1 mb-4">
        {STATUS_TABS.map((tab) => (
          <button
            key={tab.value}
            type="button"
            className={statusFilter === tab.value ? "btn-secondary text-sm" : "btn-ghost text-sm"}
            onClick={() => setStatusFilter(tab.value)}
          >
            {tab.label}
          </button>
        ))}
      </div>

      {/* Search + Department filter */}
      <div className="flex items-center gap-3 mb-4">
        <div className="relative flex-1">
          <i className="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm pointer-events-none" />
          <input
            className="input pl-8"
            placeholder="Поиск по имени или email…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>
        <select
          className="input w-48"
          value={departmentId}
          onChange={(e) => setDepartmentId(e.target.value)}
        >
          <option value="">Все отделы</option>
          {departments?.map((d) => (
            <option key={d.id} value={String(d.id)}>{d.name}</option>
          ))}
        </select>
      </div>

      <DataTable<EmployeeListItem>
        columns={columns}
        rows={tableRows}
        getRowKey={(emp) => emp.id}
        onRowClick={onEdit}
        rowActions={(emp) => (
          <div onClick={(e) => e.stopPropagation()}>
            <EmployeeRowActions
              employee={emp}
              onEdit={onEdit}
              onDismiss={onDismiss}
              onRestore={onRestore}
              onTransfer={onTransfer}
              onSchedule={onSchedule}
            />
          </div>
        )}
        isError={!!error}
        errorText="Не удалось загрузить список сотрудников"
        emptyIcon="bi-people"
        emptyTitle={data && data.length === 0 ? "Пока нет сотрудников" : "Нет совпадений"}
        emptyText={
          data && data.length === 0
            ? "Добавь первого в разделе Пользователи"
            : "Попробуй другой фильтр или поисковый запрос"
        }
        emptyCta={
          data && data.length === 0 ? (
            <a href="/admin/users" className="btn-primary text-sm">
              <i className="bi bi-plus-lg mr-1" />
              Добавить сотрудника
            </a>
          ) : undefined
        }
        ariaLabel="Список сотрудников"
        skeletonRows={6}
        maxHeight="72vh"
      />
    </div>
  );
}

