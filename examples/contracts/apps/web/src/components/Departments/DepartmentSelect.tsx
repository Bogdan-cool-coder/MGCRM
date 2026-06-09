"use client";

import type { Department } from "@/lib/types";

interface DepartmentSelectProps {
  value: string;
  onChange: (value: string) => void;
  departments: Department[];
  placeholder?: string;
  excludeId?: number;
  className?: string;
  disabled?: boolean;
}

interface FlatOption {
  id: number;
  label: string;
  depth: number;
}

/** Строит плоский список опций с отступами через обход дерева в глубину */
function buildFlatOptions(
  departments: Department[],
  excludeId?: number,
): FlatOption[] {
  // Строим дерево из flat-списка
  const map = new Map<number, Department & { children: Department[] }>();
  const roots: (Department & { children: Department[] })[] = [];

  for (const d of departments) {
    map.set(d.id, { ...d, children: [] });
  }

  for (const d of departments) {
    const node = map.get(d.id)!;
    if (d.parent_id == null) {
      roots.push(node);
    } else {
      const parent = map.get(d.parent_id);
      if (parent) parent.children.push(node);
    }
  }

  // Сортировка корней по sort_order
  roots.sort((a, b) => a.sort_order - b.sort_order);

  const result: FlatOption[] = [];

  function traverse(node: Department & { children: Department[] }, depth: number) {
    if (excludeId != null && node.id === excludeId) return;
    const prefix = depth === 0 ? "" : "  ".repeat(depth * 2) + "↳ ";
    result.push({ id: node.id, label: prefix + node.name, depth });
    const sorted = [...node.children].sort((a, b) => a.sort_order - b.sort_order);
    for (const child of sorted) {
      traverse(child as Department & { children: Department[] }, depth + 1);
    }
  }

  for (const root of roots) traverse(root, 0);

  return result;
}

export function DepartmentSelect({
  value,
  onChange,
  departments,
  placeholder = "Не выбрано",
  excludeId,
  className = "input",
  disabled = false,
}: DepartmentSelectProps) {
  const options = buildFlatOptions(departments, excludeId);

  return (
    <select
      className={className}
      value={value}
      onChange={(e) => onChange(e.target.value)}
      disabled={disabled}
    >
      <option value="">{placeholder}</option>
      {options.map((opt) => (
        <option key={opt.id} value={String(opt.id)}>
          {opt.label}
        </option>
      ))}
    </select>
  );
}
