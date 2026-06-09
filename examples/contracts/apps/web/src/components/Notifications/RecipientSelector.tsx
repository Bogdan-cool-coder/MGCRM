"use client";

import { useEffect, useRef, useState } from "react";
import useSWR from "swr";
import { fetcher } from "@/lib/api";
import type { User, Department } from "@/lib/types";

export type RecipientMode = "all" | "role" | "department" | "users";

const ROLE_OPTIONS = [
  { value: "admin", label: "Администраторы" },
  { value: "director", label: "Руководители" },
  { value: "manager", label: "Менеджеры" },
  { value: "lawyer", label: "Юристы" },
];

interface UserMultiSelectProps {
  selected: number[];
  onChange: (ids: number[]) => void;
}

function UserMultiSelect({ selected, onChange }: UserMultiSelectProps) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  const { data: users } = useSWR<User[]>("/api/admin/users?is_active=true&limit=200", fetcher);
  const list = users ?? [];

  useEffect(() => {
    function handleMouseDown(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) {
        setOpen(false);
      }
    }
    if (open) {
      document.addEventListener("mousedown", handleMouseDown);
    }
    return () => document.removeEventListener("mousedown", handleMouseDown);
  }, [open]);

  function toggle(id: number) {
    if (selected.includes(id)) {
      onChange(selected.filter((v) => v !== id));
    } else {
      onChange([...selected, id]);
    }
  }

  const label =
    selected.length === 0
      ? "Выбрать пользователей…"
      : `Выбрано: ${selected.length}`;

  return (
    <div className="relative mt-2" ref={ref}>
      <button
        type="button"
        className="btn-secondary text-sm py-1.5 px-3 w-full text-left flex items-center justify-between"
        onClick={() => setOpen((v) => !v)}
      >
        <span>{label}</span>
        <i className={`bi-chevron-down text-xs transition-transform ${open ? "rotate-180" : ""}`} />
      </button>

      {open && (
        <div className="absolute z-20 mt-1 card shadow-md w-full p-2 space-y-0.5 max-h-60 overflow-y-auto">
          {list.length === 0 && (
            <div className="px-2 py-2 text-sm text-gray-400">Загрузка…</div>
          )}
          {list.map((u) => (
            <label
              key={u.id}
              className="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer text-sm text-gray-700 dark:text-gray-300"
            >
              <input
                type="checkbox"
                className="accent-primary"
                checked={selected.includes(u.id)}
                onChange={() => toggle(u.id)}
              />
              {u.full_name}
            </label>
          ))}
        </div>
      )}
    </div>
  );
}

interface Props {
  mode: RecipientMode;
  role: string;
  departmentId: number | null;
  userIds: number[];
  onModeChange: (mode: RecipientMode) => void;
  onRoleChange: (role: string) => void;
  onDepartmentChange: (id: number | null) => void;
  onUserIdsChange: (ids: number[]) => void;
  error?: string;
}

export function RecipientSelector({
  mode,
  role,
  departmentId,
  userIds,
  onModeChange,
  onRoleChange,
  onDepartmentChange,
  onUserIdsChange,
  error,
}: Props) {
  const { data: departments } = useSWR<Department[]>("/api/admin/departments", fetcher);

  function getPreviewText(): string {
    switch (mode) {
      case "all":
        return "Все активные пользователи";
      case "role":
        return role
          ? `Роль: ${ROLE_OPTIONS.find((r) => r.value === role)?.label ?? role}`
          : "Выбери роль";
      case "department":
        if (departmentId) {
          const dept = departments?.find((d) => d.id === departmentId);
          return `Отдел: ${dept?.name ?? departmentId}`;
        }
        return "Выбери отдел";
      case "users":
        return userIds.length > 0
          ? `Выбрано: ${userIds.length} человек`
          : "Выбери пользователей";
    }
  }

  return (
    <div className="space-y-3">
      {/* Radio group */}
      <div className="space-y-2">
        <RadioOption
          value="all"
          current={mode}
          label="Все активные пользователи"
          onSelect={onModeChange}
        />
        <div>
          <RadioOption
            value="role"
            current={mode}
            label="По роли"
            onSelect={onModeChange}
          />
          {mode === "role" && (
            <select
              className="input mt-2 ml-6"
              value={role}
              onChange={(e) => onRoleChange(e.target.value)}
            >
              <option value="">Выбери роль…</option>
              {ROLE_OPTIONS.map((r) => (
                <option key={r.value} value={r.value}>{r.label}</option>
              ))}
            </select>
          )}
        </div>
        <div>
          <RadioOption
            value="department"
            current={mode}
            label="По отделу"
            onSelect={onModeChange}
          />
          {mode === "department" && (
            <select
              className="input mt-2 ml-6"
              value={departmentId ?? ""}
              onChange={(e) =>
                onDepartmentChange(e.target.value ? Number(e.target.value) : null)
              }
            >
              <option value="">Выбери отдел…</option>
              {(departments ?? []).map((d) => (
                <option key={d.id} value={d.id}>{d.name}</option>
              ))}
            </select>
          )}
        </div>
        <div>
          <RadioOption
            value="users"
            current={mode}
            label="Конкретные пользователи"
            onSelect={onModeChange}
          />
          {mode === "users" && (
            <div className="ml-6">
              <UserMultiSelect selected={userIds} onChange={onUserIdsChange} />
            </div>
          )}
        </div>
      </div>

      {/* Preview */}
      <div className="pt-2 border-t border-gray-100 dark:border-gray-700">
        <span className="badge bg-info/10 text-info text-xs">
          <i className="bi-people mr-1" />
          {getPreviewText()}
        </span>
      </div>

      {error && <p className="text-danger text-xs">{error}</p>}
    </div>
  );
}

function RadioOption({
  value,
  current,
  label,
  onSelect,
}: {
  value: RecipientMode;
  current: RecipientMode;
  label: string;
  onSelect: (v: RecipientMode) => void;
}) {
  return (
    <label className="flex items-center gap-2 cursor-pointer text-sm text-gray-700 dark:text-gray-300">
      <input
        type="radio"
        name="recipient_mode"
        className="accent-primary"
        checked={current === value}
        onChange={() => onSelect(value)}
      />
      {label}
    </label>
  );
}
