"use client";

import { useRef, useState } from "react";
import type { ActivityAttachment } from "@/lib/types";
import { api } from "@/lib/api";

interface Props {
  activityId: number;
  attachments: ActivityAttachment[];
  onMutate: () => void;
}

function getFileIcon(filename: string): string {
  const ext = filename.split(".").pop()?.toLowerCase();
  if (!ext) return "bi-file-earmark";
  if (["pdf"].includes(ext)) return "bi-file-pdf text-danger";
  if (["jpg", "jpeg", "png", "gif", "webp", "svg"].includes(ext)) return "bi-image text-success";
  if (["doc", "docx"].includes(ext)) return "bi-file-word text-info";
  if (["xls", "xlsx"].includes(ext)) return "bi-file-spreadsheet text-success";
  if (["zip", "rar", "7z"].includes(ext)) return "bi-file-zip text-warning";
  return "bi-file-earmark text-gray-400";
}

function formatSize(bytes: number | null): string {
  if (!bytes) return "";
  if (bytes < 1024) return `${bytes} Б`;
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} КБ`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} МБ`;
}

export function TaskFilesTab({ activityId, attachments, onMutate }: Props) {
  const inputRef = useRef<HTMLInputElement>(null);
  const [uploading, setUploading] = useState(false);
  const [dragOver, setDragOver] = useState(false);

  async function uploadFiles(files: FileList) {
    setUploading(true);
    try {
      for (const file of Array.from(files)) {
        const form = new FormData();
        form.append("file", file);
        await fetch(`/api/activities/${activityId}/attachments`, {
          method: "POST",
          body: form,
          credentials: "same-origin",
        });
      }
      onMutate();
    } finally {
      setUploading(false);
    }
  }

  async function deleteAttachment(id: number) {
    if (!confirm("Удалить файл?")) return;
    await api(`/activities/${activityId}/attachments/${id}`, { method: "DELETE" });
    onMutate();
  }

  function handleDrop(e: React.DragEvent) {
    e.preventDefault();
    setDragOver(false);
    if (e.dataTransfer.files.length > 0) uploadFiles(e.dataTransfer.files);
  }

  return (
    <div className="p-6 space-y-4">
      {/* Drop zone */}
      <div
        onDrop={handleDrop}
        onDragOver={(e) => { e.preventDefault(); setDragOver(true); }}
        onDragLeave={() => setDragOver(false)}
        className={
          "border-2 border-dashed rounded-lg p-8 text-center transition-colors " +
          (dragOver
            ? "border-primary bg-primary/5"
            : "border-gray-300 dark:border-gray-600 hover:border-primary hover:bg-gray-50 dark:hover:bg-gray-800/50")
        }
      >
        <i className="bi bi-cloud-upload text-4xl text-gray-300 dark:text-gray-600 block mb-2" />
        <p className="text-sm text-gray-500 dark:text-gray-400 mb-3">
          Перетащи файлы или нажми для выбора
        </p>
        <button
          className="btn-secondary text-sm"
          disabled={uploading}
          onClick={() => inputRef.current?.click()}
        >
          {uploading ? "Загрузка..." : "Выбрать файлы"}
        </button>
        <input
          ref={inputRef}
          type="file"
          multiple
          className="hidden"
          onChange={(e) => { if (e.target.files) uploadFiles(e.target.files); }}
        />
      </div>

      {/* Files list */}
      {attachments.length === 0 ? (
        <div className="text-center py-8">
          <p className="text-sm font-medium text-gray-500 dark:text-gray-400">Файлы не прикреплены</p>
          <p className="text-xs text-gray-400 mt-1">Перетащи сюда или нажми «Выбрать файлы»</p>
        </div>
      ) : (
        <div>
          <div className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
            Прикреплённые файлы ({attachments.length}):
          </div>
          <div className="space-y-2">
            {attachments.map((att) => (
              <div
                key={att.id}
                className="flex items-center gap-3 px-3 py-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800/50 group"
              >
                <i className={`bi ${getFileIcon(att.filename)} text-xl shrink-0`} />
                <div className="flex-1 min-w-0">
                  <div className="text-sm font-medium truncate text-gray-800 dark:text-gray-200">
                    {att.filename}
                  </div>
                  <div className="text-xs text-gray-500">
                    {formatSize(att.size_bytes)}
                    {att.uploaded_by_name && ` · ${att.uploaded_by_name}`}
                    {att.created_at && ` · ${new Date(att.created_at).toLocaleDateString("ru-RU")}`}
                  </div>
                </div>
                <a
                  href={`/api/activities/${activityId}/attachments/${att.id}/download`}
                  className="btn-ghost p-1.5"
                  title="Скачать"
                  download
                >
                  <i className="bi bi-download text-sm" />
                </a>
                <button
                  onClick={() => deleteAttachment(att.id)}
                  className="btn-ghost p-1.5 text-danger opacity-0 group-hover:opacity-100 transition-opacity"
                  title="Удалить"
                >
                  <i className="bi bi-trash text-sm" />
                </button>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
