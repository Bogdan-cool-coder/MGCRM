"use client";

import { useRef, useState } from "react";
import useSWR from "swr";
import { api, ApiError, fetcher } from "@/lib/api";
import { Modal } from "@/components/Modal";
import { formatDate } from "@/lib/dates";

// ── Types ────────────────────────────────────────────────────────────────────

interface CrmFolder {
  id: number;
  name: string;
  is_system: boolean;
  entity_type: string;
  entity_id: number;
}

interface CrmFile {
  id: number;
  original_name: string;
  file_size: number;
  mime_type: string;
  created_at: string;
  folder_id: number;
}

function isFolderArray(v: unknown): v is CrmFolder[] {
  return Array.isArray(v) && (v.length === 0 || (typeof v[0] === "object" && v[0] !== null && "is_system" in v[0]));
}

function isFileArray(v: unknown): v is CrmFile[] {
  return Array.isArray(v) && (v.length === 0 || (typeof v[0] === "object" && v[0] !== null && "original_name" in v[0]));
}

function fmtSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} Б`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} КБ`;
  return `${(bytes / 1024 / 1024).toFixed(1)} МБ`;
}

const MAX_SIZE = 20 * 1024 * 1024; // 20 MB

// ── Props ─────────────────────────────────────────────────────────────────────

interface Props {
  entityType: "contact" | "company" | "deal";
  entityId: number;
  editMode: boolean;
}

// ── Component ─────────────────────────────────────────────────────────────────

export function FilesTab({ entityType, entityId, editMode }: Props) {
  const foldersKey = `/crm/folders?entity_type=${entityType}&entity_id=${entityId}`;
  const { data: rawFolders, mutate: mutateFolders } = useSWR<unknown>(foldersKey, fetcher);
  const folders = isFolderArray(rawFolders) ? rawFolders : [];

  const [selectedFolderId, setSelectedFolderId] = useState<number | null>(null);
  const [renamingId, setRenamingId] = useState<number | null>(null);
  const [renameVal, setRenameVal] = useState("");
  const [newFolderName, setNewFolderName] = useState("");
  const [addingFolder, setAddingFolder] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [uploading, setUploading] = useState(false);
  const [uploadError, setUploadError] = useState<string | null>(null);
  const [confirmDeleteFile, setConfirmDeleteFile] = useState<CrmFile | null>(null);
  const [confirmDeleteFolder, setConfirmDeleteFolder] = useState<CrmFolder | null>(null);

  const fileInputRef = useRef<HTMLInputElement>(null);

  // Pick default folder
  const activeFolder = folders.find((f) => f.id === selectedFolderId) ?? folders[0] ?? null;
  const activeFolderId = activeFolder?.id ?? null;

  const filesKey = activeFolderId != null
    ? `/crm/files?entity_type=${entityType}&entity_id=${entityId}&folder_id=${activeFolderId}`
    : null;
  const { data: rawFiles, mutate: mutateFiles } = useSWR<unknown>(filesKey, fetcher);
  const files = isFileArray(rawFiles) ? rawFiles : [];

  // ── Folder actions ───────────────────────────────────────────────────────────

  async function handleAddFolder() {
    if (!newFolderName.trim()) return;
    setError(null);
    try {
      await api("/crm/folders", {
        method: "POST",
        body: { entity_type: entityType, entity_id: entityId, name: newFolderName.trim() },
      });
      await mutateFolders();
      setNewFolderName("");
      setAddingFolder(false);
    } catch (err) {
      setError(err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Не удалось создать папку");
    }
  }

  async function handleRenameFolder(id: number) {
    if (!renameVal.trim()) { setRenamingId(null); return; }
    setError(null);
    try {
      await api(`/crm/folders/${id}`, { method: "PATCH", body: { name: renameVal.trim() } });
      await mutateFolders();
      setRenamingId(null);
    } catch (err) {
      setError(err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Не удалось переименовать");
    }
  }

  async function handleDeleteFolder(folder: CrmFolder) {
    setError(null);
    setConfirmDeleteFolder(null);
    try {
      await api(`/crm/folders/${folder.id}`, { method: "DELETE" });
      await mutateFolders();
      if (selectedFolderId === folder.id) setSelectedFolderId(null);
    } catch (err) {
      setError(err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Не удалось удалить папку");
    }
  }

  // ── File upload ──────────────────────────────────────────────────────────────

  async function handleFileChange(e: React.ChangeEvent<HTMLInputElement>) {
    const selectedFiles = Array.from(e.target.files ?? []);
    if (selectedFiles.length === 0 || activeFolderId == null) return;

    setUploadError(null);

    // Validate sizes
    for (const f of selectedFiles) {
      if (f.size > MAX_SIZE) {
        setUploadError(`Файл «${f.name}» слишком большой. Максимум 20 МБ.`);
        e.target.value = "";
        return;
      }
    }

    setUploading(true);
    try {
      for (const f of selectedFiles) {
        const formData = new FormData();
        formData.append("entity_type", entityType);
        formData.append("entity_id", String(entityId));
        formData.append("folder_id", String(activeFolderId));
        formData.append("file", f);

        const res = await fetch("/api/crm/files", {
          method: "POST",
          body: formData,
          credentials: "same-origin",
        });
        if (!res.ok) {
          let detail: unknown;
          try { detail = await res.json(); } catch { detail = await res.text(); }
          const msg = typeof detail === "object" && detail !== null && "detail" in detail
            ? String((detail as { detail: unknown }).detail)
            : `Ошибка ${res.status}`;
          setUploadError(msg);
          break;
        }
      }
      await mutateFiles();
    } catch {
      setUploadError("Не удалось загрузить файл");
    } finally {
      setUploading(false);
      e.target.value = "";
    }
  }

  async function handleDeleteFile(file: CrmFile) {
    setConfirmDeleteFile(null);
    setError(null);
    try {
      await api(`/crm/files/${file.id}`, { method: "DELETE" });
      await mutateFiles();
    } catch (err) {
      setError(err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Не удалось удалить файл");
    }
  }

  // ── Render ───────────────────────────────────────────────────────────────────

  return (
    <div className="flex gap-4 min-h-[360px]">
      {/* Left: folders */}
      <div className="w-48 shrink-0 border-r border-gray-200 dark:border-gray-700 pr-3 space-y-1">
        <div className="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2">Папки</div>

        {folders.map((folder) => (
          <div key={folder.id} className="group">
            {renamingId === folder.id ? (
              <input
                className="input text-sm w-full py-1"
                autoFocus
                value={renameVal}
                onChange={(e) => setRenameVal(e.target.value)}
                onBlur={() => handleRenameFolder(folder.id)}
                onKeyDown={(e) => {
                  if (e.key === "Enter") void handleRenameFolder(folder.id);
                  if (e.key === "Escape") setRenamingId(null);
                }}
              />
            ) : (
              <button
                type="button"
                onDoubleClick={() => {
                  if (!folder.is_system && editMode) {
                    setRenamingId(folder.id);
                    setRenameVal(folder.name);
                  }
                }}
                onClick={() => setSelectedFolderId(folder.id)}
                className={`w-full text-left flex items-center gap-2 px-2 py-1.5 rounded-md text-sm transition-colors ${
                  activeFolderId === folder.id
                    ? "bg-primary text-white"
                    : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700"
                }`}
              >
                <i className={`bi ${folder.is_system ? "bi-folder2" : "bi-folder"} text-xs`} />
                <span className="flex-1 truncate">{folder.name}</span>
                {editMode && !folder.is_system && activeFolderId !== folder.id && (
                  <button
                    type="button"
                    onClick={(e) => { e.stopPropagation(); setConfirmDeleteFolder(folder); }}
                    className="opacity-0 group-hover:opacity-100 text-danger text-xs"
                    title="Удалить папку"
                  >
                    <i className="bi bi-trash" />
                  </button>
                )}
              </button>
            )}
          </div>
        ))}

        {addingFolder ? (
          <div className="mt-2">
            <input
              className="input text-sm w-full py-1"
              autoFocus
              value={newFolderName}
              onChange={(e) => setNewFolderName(e.target.value)}
              placeholder="Название папки"
              onKeyDown={(e) => {
                if (e.key === "Enter") void handleAddFolder();
                if (e.key === "Escape") { setAddingFolder(false); setNewFolderName(""); }
              }}
            />
            <div className="flex gap-1 mt-1">
              <button type="button" className="btn-primary text-xs py-1 px-2" onClick={() => void handleAddFolder()}>Создать</button>
              <button type="button" className="btn-ghost text-xs py-1 px-2" onClick={() => { setAddingFolder(false); setNewFolderName(""); }}>Отмена</button>
            </div>
          </div>
        ) : (
          <button
            type="button"
            className="btn-ghost text-xs mt-2 w-full text-left"
            onClick={() => setAddingFolder(true)}
          >
            <i className="bi bi-plus" /> Папка
          </button>
        )}
      </div>

      {/* Right: files */}
      <div className="flex-1 min-w-0 space-y-3">
        {error && (
          <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">{error}</div>
        )}

        {/* Upload controls — доступны при выбранной папке (без editMode) */}
        {activeFolderId != null && (
          <div className="flex items-center gap-2">
            <button
              type="button"
              className="btn-secondary text-sm"
              disabled={uploading}
              onClick={() => fileInputRef.current?.click()}
            >
              {uploading
                ? <><i className="bi bi-arrow-repeat animate-spin mr-1" /> Загрузка…</>
                : <><i className="bi bi-upload mr-1" /> Загрузить файлы</>
              }
            </button>
            <input
              ref={fileInputRef}
              type="file"
              multiple
              className="hidden"
              onChange={handleFileChange}
            />
            {uploadError && (
              <span className="text-sm text-danger">{uploadError}</span>
            )}
          </div>
        )}

        {/* File list */}
        {activeFolderId == null ? (
          folders.length === 0 ? (
            <div className="flex flex-col items-center justify-center gap-2 py-12 text-gray-400 dark:text-gray-500">
              <i className="bi bi-folder-plus text-3xl" />
              <span className="text-sm">Папок пока нет</span>
              <button
                type="button"
                className="btn-secondary text-sm mt-1"
                onClick={() => setAddingFolder(true)}
              >
                <i className="bi bi-plus-lg mr-1" /> Создать папку
              </button>
            </div>
          ) : (
            <div className="text-sm text-gray-500 dark:text-gray-400">Выберите папку</div>
          )
        ) : files.length === 0 ? (
          <div className="flex flex-col items-center justify-center gap-2 py-12 text-gray-400 dark:text-gray-500">
            <i className="bi bi-folder2-open text-3xl" />
            <span className="text-sm">В папке пока нет файлов</span>
            <button
              type="button"
              className="btn-secondary text-sm mt-1"
              onClick={() => fileInputRef.current?.click()}
            >
              <i className="bi bi-upload mr-1" /> Загрузить
            </button>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-xs text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                  <th className="py-2 pr-4 font-medium">Имя</th>
                  <th className="py-2 pr-4 font-medium">Размер</th>
                  <th className="py-2 pr-4 font-medium">Дата</th>
                  <th className="py-2 font-medium"></th>
                </tr>
              </thead>
              <tbody>
                {files.map((file) => (
                  <tr
                    key={file.id}
                    className="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50"
                  >
                    <td className="py-2 pr-4">
                      <span className="flex items-center gap-2 text-gray-900 dark:text-gray-100">
                        <i className="bi bi-file-earmark text-gray-400" />
                        {file.original_name}
                      </span>
                    </td>
                    <td className="py-2 pr-4 text-gray-500 dark:text-gray-400 whitespace-nowrap">
                      {fmtSize(file.file_size)}
                    </td>
                    <td className="py-2 pr-4 text-gray-500 dark:text-gray-400 whitespace-nowrap">
                      {formatDate(file.created_at)}
                    </td>
                    <td className="py-2">
                      <div className="flex items-center gap-2">
                        <a
                          href={`/api/crm/files/${file.id}/download`}
                          download
                          className="btn-ghost text-xs py-1 px-2"
                          title="Скачать"
                        >
                          <i className="bi bi-download" />
                        </a>
                        {editMode && (
                          <button
                            type="button"
                            className="btn-ghost text-xs py-1 px-2 text-danger"
                            title="Удалить"
                            onClick={() => setConfirmDeleteFile(file)}
                          >
                            <i className="bi bi-trash" />
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Confirm delete file */}
      <Modal
        open={confirmDeleteFile != null}
        title="Удалить файл?"
        onClose={() => setConfirmDeleteFile(null)}
        width="sm"
        footer={
          <>
            <button type="button" className="btn-secondary" onClick={() => setConfirmDeleteFile(null)}>Отмена</button>
            <button
              type="button"
              className="btn-primary"
              onClick={() => confirmDeleteFile && void handleDeleteFile(confirmDeleteFile)}
            >
              Удалить
            </button>
          </>
        }
      >
        <p className="text-sm text-gray-700 dark:text-gray-300">
          Файл «{confirmDeleteFile?.original_name}» будет удалён безвозвратно.
        </p>
      </Modal>

      {/* Confirm delete folder */}
      <Modal
        open={confirmDeleteFolder != null}
        title="Удалить папку?"
        onClose={() => setConfirmDeleteFolder(null)}
        width="sm"
        footer={
          <>
            <button type="button" className="btn-secondary" onClick={() => setConfirmDeleteFolder(null)}>Отмена</button>
            <button
              type="button"
              className="btn-primary"
              onClick={() => confirmDeleteFolder && void handleDeleteFolder(confirmDeleteFolder)}
            >
              Удалить
            </button>
          </>
        }
      >
        <p className="text-sm text-gray-700 dark:text-gray-300">
          Папка «{confirmDeleteFolder?.name}» и все файлы в ней будут удалены.
        </p>
      </Modal>
    </div>
  );
}
