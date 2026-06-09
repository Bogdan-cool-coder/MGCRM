"use client";

import { useState } from "react";
import { useSortable } from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import type { Department, User } from "@/lib/types";

interface DepartmentCardProps {
  department: Department;
  allUsers: User[];
  depth: number;
  onEdit: (d: Department) => void;
  onDelete: (d: Department) => void;
}

function pluralMembers(n: number): string {
  if (n % 10 === 1 && n % 100 !== 11) return `${n} сотрудник`;
  if (n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 10 || n % 100 >= 20)) return `${n} сотрудника`;
  return `${n} сотрудников`;
}

export function DepartmentCard({
  department,
  allUsers,
  depth,
  onEdit,
  onDelete,
}: DepartmentCardProps) {
  const [expanded, setExpanded] = useState(true);

  const headUser = department.head_user_id != null
    ? allUsers.find((u) => u.id === department.head_user_id)
    : null;

  const children = department.children ?? [];
  const hasChildren = children.length > 0;
  const membersCount = department.members_count ?? 0;

  // DnD: только для корневых (depth === 0)
  const {
    attributes,
    listeners,
    setNodeRef,
    transform,
    transition,
    isDragging,
  } = useSortable({ id: department.id, disabled: depth > 0 });

  const style =
    depth === 0
      ? {
          transform: CSS.Transform.toString(transform),
          transition,
          opacity: isDragging ? 0.5 : 1,
        }
      : undefined;

  const cardContent = (
    <div
      className={
        depth === 0
          ? "card p-4 mb-3"
          : "ml-6 mt-2 border-l border-gray-200 pl-4"
      }
      ref={depth === 0 ? setNodeRef : undefined}
      style={depth === 0 ? style : undefined}
    >
      {/* Заголовок строки */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-1 min-w-0">
          {/* Drag handle — только для корневых */}
          {depth === 0 && (
            <button
              {...attributes}
              {...listeners}
              className="btn-ghost p-1 cursor-grab text-gray-400 hover:text-gray-600 shrink-0"
              title="Перетащить для изменения порядка"
              tabIndex={-1}
            >
              <i className="bi bi-grip-vertical" />
            </button>
          )}

          {/* Иконка вложения для дочерних */}
          {depth > 0 && (
            <i className="bi bi-arrow-return-right text-gray-400 mr-1 text-xs shrink-0" />
          )}

          {/* Chevron раскрытия */}
          {hasChildren && (
            <button
              className="btn-ghost p-1"
              onClick={() => setExpanded((v) => !v)}
              title={expanded ? "Свернуть" : "Развернуть"}
            >
              <i className={`bi ${expanded ? "bi-chevron-down" : "bi-chevron-right"} text-sm`} />
            </button>
          )}
          {!hasChildren && <span className="w-7 shrink-0" />}

          <span className="text-base font-semibold text-primary truncate">
            {department.name}
          </span>
        </div>

        <div className="flex items-center gap-1 shrink-0 ml-2">
          <button
            className="btn-ghost p-1.5 text-gray-500 hover:text-primary"
            onClick={() => onEdit(department)}
            title="Редактировать"
          >
            <i className="bi bi-pencil text-sm" />
          </button>
          <button
            className="btn-ghost p-1.5 text-danger"
            onClick={() => onDelete(department)}
            title="Удалить"
          >
            <i className="bi bi-trash text-sm" />
          </button>
        </div>
      </div>

      {/* Мета-информация */}
      <div className="flex items-center gap-2 mt-1 ml-7 flex-wrap">
        {headUser ? (
          <span className="inline-flex items-center gap-1 text-xs bg-info/10 text-info px-2 py-0.5 rounded-full">
            <i className="bi bi-person text-xs" />
            Руководитель: {headUser.full_name}
          </span>
        ) : (
          <span className="text-xs text-gray-400">Руководитель: —</span>
        )}

        {membersCount > 0 && (
          <span className="text-xs text-gray-500 flex items-center gap-1">
            <i className="bi bi-people text-xs" />
            {pluralMembers(membersCount)}
          </span>
        )}
      </div>

      {/* Дочерние отделы */}
      {hasChildren && expanded && (
        <div className="mt-2">
          {children
            .slice()
            .sort((a, b) => a.sort_order - b.sort_order)
            .map((child) => (
              <DepartmentCard
                key={child.id}
                department={child}
                allUsers={allUsers}
                depth={depth + 1}
                onEdit={onEdit}
                onDelete={onDelete}
              />
            ))}
        </div>
      )}
    </div>
  );

  return cardContent;
}
