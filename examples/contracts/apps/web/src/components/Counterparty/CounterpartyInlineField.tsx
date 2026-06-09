"use client";

import { useRef, useState } from "react";

interface CounterpartyInlineFieldProps {
  label: string;
  value: string | null;
  onSave: (newValue: string) => Promise<void>;
  type?: "text" | "email" | "tel";
  placeholder?: string;
}

export function CounterpartyInlineField({
  label,
  value,
  onSave,
  type = "text",
  placeholder = "—",
}: CounterpartyInlineFieldProps) {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  function startEdit() {
    setDraft(value ?? "");
    setError(null);
    setEditing(true);
    // focus happens via autoFocus on input
  }

  function cancel() {
    setEditing(false);
    setError(null);
  }

  async function commit() {
    if (draft === (value ?? "")) {
      setEditing(false);
      return;
    }
    setLoading(true);
    setError(null);
    try {
      await onSave(draft);
      setEditing(false);
    } catch {
      setError("Не удалось сохранить");
    } finally {
      setLoading(false);
    }
  }

  function handleKeyDown(e: React.KeyboardEvent) {
    if (e.key === "Enter") {
      e.preventDefault();
      void commit();
    }
    if (e.key === "Escape") {
      cancel();
    }
  }

  return (
    <div className="border-b border-gray-100 py-1.5">
      <span className="text-xs text-gray-500">{label}</span>
      {editing ? (
        <div className="mt-0.5">
          <input
            ref={inputRef}
            autoFocus
            className={`input text-sm py-1 w-full ${loading ? "opacity-50" : ""}`}
            type={type}
            value={draft}
            onChange={(e) => setDraft(e.target.value)}
            onBlur={() => void commit()}
            onKeyDown={handleKeyDown}
            disabled={loading}
            placeholder={placeholder}
          />
          {error && <div className="text-danger text-xs mt-1">{error}</div>}
        </div>
      ) : (
        <div
          className="flex items-center gap-1 group cursor-pointer mt-0.5"
          onClick={startEdit}
          title="Нажмите для редактирования"
        >
          <span className="text-sm text-gray-800 flex-1 truncate">
            {value ?? <span className="text-gray-400">{placeholder}</span>}
          </span>
          <i className="bi bi-pencil text-xs text-gray-300 opacity-0 group-hover:opacity-100 transition-opacity shrink-0" />
        </div>
      )}
    </div>
  );
}
