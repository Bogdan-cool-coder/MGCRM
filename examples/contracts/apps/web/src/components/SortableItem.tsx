"use client";

import React from "react";
import { useSortable } from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";

interface SortableItemProps {
  id: number | string;
  children: React.ReactNode;
  /** Дополнительные классы для внешнего контейнера */
  className?: string;
  /** Если true — handle занимает отдельную кнопку слева */
  showHandle?: boolean;
}

/**
 * Универсальный wrapper для элементов @dnd-kit/sortable.
 * Использует drag handle bi-grip-vertical слева от children.
 */
export function SortableItem({ id, children, className = "", showHandle = true }: SortableItemProps) {
  const {
    attributes,
    listeners,
    setNodeRef,
    transform,
    transition,
    isDragging,
  } = useSortable({ id });

  const style: React.CSSProperties = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.5 : 1,
    zIndex: isDragging ? 50 : undefined,
  };

  if (!showHandle) {
    return (
      <div
        ref={setNodeRef}
        style={style}
        {...attributes}
        {...listeners}
        className={`${isDragging ? "shadow-lg" : ""} ${className}`}
      >
        {children}
      </div>
    );
  }

  return (
    <div
      ref={setNodeRef}
      style={style}
      className={`flex items-center gap-2 ${isDragging ? "shadow-lg" : ""} ${className}`}
    >
      {/* Drag handle */}
      <button
        {...attributes}
        {...listeners}
        type="button"
        className="cursor-grab active:cursor-grabbing p-1 text-gray-400 hover:text-gray-600 shrink-0"
        tabIndex={-1}
        title="Перетащить"
      >
        <i className="bi bi-grip-vertical" />
      </button>
      {/* Содержимое */}
      <div className="flex-1 min-w-0">{children}</div>
    </div>
  );
}
