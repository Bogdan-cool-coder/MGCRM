"use client";

import { useState } from "react";
import Link from "next/link";
import { api } from "@/lib/api";

interface RelatedLink {
  id: number;
  activity_id_to: number;
  title?: string;
}

interface Props {
  activityId: number;
  relatedLinks: RelatedLink[];
  onMutate: () => void;
}

export function TaskRelatedTab({ activityId, relatedLinks, onMutate }: Props) {
  const [searchInput, setSearchInput] = useState("");
  const [linking, setLinking] = useState(false);

  async function linkTask(relatedId: number) {
    setLinking(true);
    try {
      await api(`/activities/${activityId}/related`, {
        method: "POST",
        body: { related_activity_id: relatedId },
      });
      setSearchInput("");
      onMutate();
    } finally {
      setLinking(false);
    }
  }

  async function unlinkTask(relatedId: number) {
    if (!confirm("Разорвать связь?")) return;
    await api(`/activities/${activityId}/related/${relatedId}`, { method: "DELETE" });
    onMutate();
  }

  return (
    <div className="p-6 space-y-4">
      {relatedLinks.length === 0 ? (
        <div className="text-center py-8">
          <p className="text-sm font-medium text-gray-500 dark:text-gray-400">Нет связанных задач</p>
        </div>
      ) : (
        <div className="space-y-2">
          {relatedLinks.map((link) => (
            <div
              key={link.id}
              className="flex items-center gap-3 px-3 py-2 rounded-lg border border-gray-200 dark:border-gray-700"
            >
              <i className="bi bi-link-45deg text-gray-400 text-base" />
              <Link
                href={`/tasks/${link.activity_id_to}`}
                className="flex-1 text-sm hover:underline text-gray-700 dark:text-gray-300"
              >
                #{link.activity_id_to} {link.title && `· ${link.title}`}
              </Link>
              <button
                onClick={() => unlinkTask(link.id)}
                className="text-gray-400 hover:text-danger text-xs"
                title="Разорвать связь"
              >
                <i className="bi bi-x" />
              </button>
            </div>
          ))}
        </div>
      )}

      {/* Link search */}
      <div className="flex gap-2">
        <input
          className="input flex-1"
          placeholder="Найти задачу..."
          value={searchInput}
          onChange={(e) => setSearchInput(e.target.value)}
        />
        <button
          className="btn-secondary text-sm"
          disabled={!searchInput.trim() || linking}
          onClick={() => {
            const id = parseInt(searchInput.trim(), 10);
            if (!isNaN(id)) linkTask(id);
          }}
        >
          + Связать
        </button>
      </div>
      <p className="text-xs text-gray-400">Введите ID задачи (например: 1042)</p>
    </div>
  );
}
