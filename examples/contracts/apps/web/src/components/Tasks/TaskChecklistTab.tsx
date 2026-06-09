"use client";

import { useState } from "react";
import { api } from "@/lib/api";
import type { ChecklistItem } from "@/lib/types";

interface Props {
  activityId: number;
  items: ChecklistItem[];
  onMutate: () => void;
}

export function TaskChecklistTab({ activityId, items, onMutate }: Props) {
  const [newItem, setNewItem] = useState("");
  const [adding, setAdding] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [editText, setEditText] = useState("");

  const done = items.filter((i) => i.is_done).length;
  const total = items.length;
  const pct = total > 0 ? Math.round((done / total) * 100) : 0;

  async function toggleItem(item: ChecklistItem) {
    await api(`/activities/${activityId}/checklist/${item.id}`, {
      method: "PATCH",
      body: { is_done: !item.is_done },
    });
    onMutate();
  }

  async function deleteItem(itemId: number) {
    await api(`/activities/${activityId}/checklist/${itemId}`, { method: "DELETE" });
    onMutate();
  }

  async function addItem() {
    if (!newItem.trim()) return;
    setAdding(true);
    try {
      await api(`/activities/${activityId}/checklist`, {
        method: "POST",
        body: { text: newItem.trim(), sort_order: items.length },
      });
      setNewItem("");
      onMutate();
    } finally {
      setAdding(false);
    }
  }

  async function saveEdit(itemId: number) {
    if (editText.trim()) {
      await api(`/activities/${activityId}/checklist/${itemId}`, {
        method: "PATCH",
        body: { text: editText.trim() },
      });
      onMutate();
    }
    setEditingId(null);
  }

  return (
    <div className="p-6 space-y-4">
      {/* Progress */}
      {total > 0 && (
        <div className="flex items-center gap-3">
          <span className="text-sm text-gray-600 dark:text-gray-400">
            {done} из {total} пунктов
          </span>
          <div className="flex-1 h-2 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
            <div className="h-full bg-success transition-all" style={{ width: `${pct}%` }} />
          </div>
          <span className="text-sm font-medium tabular-nums">{pct}%</span>
        </div>
      )}

      {/* Items */}
      <div className="space-y-0.5">
        {items.map((item) => (
          <div
            key={item.id}
            className="flex items-center gap-2 py-2 px-3 rounded-md hover:bg-gray-50 dark:hover:bg-gray-800/50 group"
          >
            <input
              type="checkbox"
              className="w-4 h-4 cursor-pointer shrink-0"
              checked={item.is_done}
              onChange={() => toggleItem(item)}
            />
            {editingId === item.id ? (
              <input
                className="input flex-1 text-sm py-0.5"
                value={editText}
                onChange={(e) => setEditText(e.target.value)}
                onBlur={() => saveEdit(item.id)}
                onKeyDown={(e) => {
                  if (e.key === "Enter") saveEdit(item.id);
                  if (e.key === "Escape") setEditingId(null);
                }}
                autoFocus
              />
            ) : (
              <span
                className={`flex-1 text-sm cursor-pointer ${item.is_done ? "line-through text-gray-400" : "text-gray-700 dark:text-gray-300"}`}
                onClick={() => { setEditingId(item.id); setEditText(item.text); }}
              >
                {item.text}
              </span>
            )}
            <button
              onClick={() => deleteItem(item.id)}
              className="opacity-0 group-hover:opacity-100 text-gray-400 hover:text-danger p-0.5 transition-opacity"
              title="Удалить"
            >
              <i className="bi bi-x text-sm" />
            </button>
          </div>
        ))}
      </div>

      {/* Add item */}
      <div className="flex gap-2">
        <input
          className="input flex-1"
          placeholder="Название пункта..."
          value={newItem}
          onChange={(e) => setNewItem(e.target.value)}
          onKeyDown={(e) => { if (e.key === "Enter") addItem(); }}
        />
        <button
          className="btn-secondary text-sm"
          disabled={adding || !newItem.trim()}
          onClick={addItem}
        >
          + Добавить пункт
        </button>
      </div>
    </div>
  );
}
