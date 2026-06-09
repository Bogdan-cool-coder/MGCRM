"use client";

import { useState } from "react";
import {
  DndContext,
  closestCenter,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
  type DragEndEvent,
} from "@dnd-kit/core";
import {
  SortableContext,
  verticalListSortingStrategy,
  arrayMove,
} from "@dnd-kit/sortable";
import { DepartmentCard } from "./DepartmentCard";
import { api } from "@/lib/api";
import type { Department, User } from "@/lib/types";

/** Строит дерево из flat-списка по parent_id */
export function buildDepartmentTree(flat: Department[]): Department[] {
  const map = new Map<number, Department & { children: Department[] }>();
  const roots: (Department & { children: Department[] })[] = [];

  for (const d of flat) {
    map.set(d.id, { ...d, children: [] });
  }

  for (const d of flat) {
    const node = map.get(d.id)!;
    if (d.parent_id == null) {
      roots.push(node);
    } else {
      const parent = map.get(d.parent_id);
      if (parent) {
        (parent as Department & { children: Department[] }).children.push(node);
      }
    }
  }

  roots.sort((a, b) => a.sort_order - b.sort_order);
  return roots;
}

interface DepartmentTreeProps {
  departments: Department[];
  allUsers: User[];
  onEdit: (d: Department) => void;
  onDelete: (d: Department) => void;
  onReorder: () => void;
}

export function DepartmentTree({
  departments,
  allUsers,
  onEdit,
  onDelete,
  onReorder,
}: DepartmentTreeProps) {
  const tree = buildDepartmentTree(departments);
  const [rootOrder, setRootOrder] = useState<number[]>(() =>
    tree.map((d) => d.id),
  );

  // Синхронизируем rootOrder когда список обновляется
  // (при добавлении нового корневого отдела)
  const treeIds = tree.map((d) => d.id);
  const needsSync =
    rootOrder.length !== treeIds.length ||
    !rootOrder.every((id) => treeIds.includes(id));
  const effectiveOrder = needsSync ? treeIds : rootOrder;

  const sensors = useSensors(
    useSensor(PointerSensor),
    useSensor(KeyboardSensor),
  );

  async function handleDragEnd(event: DragEndEvent) {
    const { active, over } = event;
    if (!over || active.id === over.id) return;

    const oldIdx = effectiveOrder.indexOf(active.id as number);
    const newIdx = effectiveOrder.indexOf(over.id as number);
    if (oldIdx < 0 || newIdx < 0) return;

    const reordered = arrayMove(effectiveOrder, oldIdx, newIdx);
    setRootOrder(reordered);

    // Обновляем sort_order на сервере для всех затронутых корневых отделов
    await Promise.all(
      reordered.map((id, idx) =>
        api(`/departments/${id}`, {
          method: "PATCH",
          body: { sort_order: idx },
        }).catch(() => null),
      ),
    );
    onReorder();
  }

  // Карта id → node для сортировки по effectiveOrder
  const nodeMap = new Map(tree.map((d) => [d.id, d]));
  const sortedTree = effectiveOrder
    .map((id) => nodeMap.get(id))
    .filter((d): d is Department => d != null);

  return (
    <DndContext
      sensors={sensors}
      collisionDetection={closestCenter}
      onDragEnd={handleDragEnd}
    >
      <SortableContext items={effectiveOrder} strategy={verticalListSortingStrategy}>
        {sortedTree.map((dept) => (
          <DepartmentCard
            key={dept.id}
            department={dept}
            allUsers={allUsers}
            depth={0}
            onEdit={onEdit}
            onDelete={onDelete}
          />
        ))}
      </SortableContext>
    </DndContext>
  );
}
