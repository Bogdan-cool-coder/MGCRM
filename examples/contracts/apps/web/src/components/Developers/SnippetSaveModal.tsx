"use client";

import { useState } from "react";
import { Modal } from "@/components/Modal";
import type { HistoryEntry } from "./RequestHistorySidebar";

interface Props {
  open: boolean;
  entry: HistoryEntry | null;
  onClose: () => void;
  onSaved: () => void;
}

export interface Snippet extends HistoryEntry {
  name: string;
  savedAt: number;
}

export const SNIPPETS_KEY = "sandbox_snippets";

export function SnippetSaveModal({ open, entry, onClose, onSaved }: Props) {
  const [name, setName] = useState("");
  const [error, setError] = useState<string | null>(null);

  function handleSave() {
    if (!name.trim()) {
      setError("Введите название сниппета");
      return;
    }
    if (!entry) return;

    const snippet: Snippet = { ...entry, name: name.trim(), savedAt: Date.now() };
    const existing: Snippet[] = (() => {
      try {
        return JSON.parse(localStorage.getItem(SNIPPETS_KEY) ?? "[]") as Snippet[];
      } catch {
        return [];
      }
    })();
    localStorage.setItem(SNIPPETS_KEY, JSON.stringify([snippet, ...existing]));
    setName("");
    setError(null);
    onSaved();
    onClose();
  }

  return (
    <Modal
      open={open}
      title="Сохранить сниппет"
      onClose={() => { setName(""); setError(null); onClose(); }}
      width="sm"
      footer={
        <>
          <button className="btn-ghost" onClick={onClose}>Отмена</button>
          <button className="btn-primary" onClick={handleSave}>Сохранить</button>
        </>
      }
    >
      <div className="space-y-4">
        {error && (
          <div className="rounded-md bg-danger/10 text-danger px-4 py-2 text-sm">{error}</div>
        )}
        <div>
          <label className="label">Название сниппета <span className="text-danger">*</span></label>
          <input
            className="input"
            placeholder="Например: Получить активные лиды"
            value={name}
            onChange={(e) => setName(e.target.value)}
            onKeyDown={(e) => e.key === "Enter" && handleSave()}
            autoFocus
          />
        </div>
        {entry && (
          <div className="text-xs text-gray-500 dark:text-gray-400">
            <span className="font-mono">{entry.method}</span> {entry.url}
          </div>
        )}
      </div>
    </Modal>
  );
}
