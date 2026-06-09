"use client";

/**
 * Dropzone — зона drag&drop загрузки файлов.
 *
 * Чистый UI-компонент: отвечает только за выбор файлов и отображение состояний.
 * Сетевую загрузку делает вызывающий код через колбэк onFiles.
 *
 * Возможности:
 *   - drag-over выделение зоны (цвет + пунктир)
 *   - клик → системный диалог выбора файла
 *   - список выбранных файлов (имя + размер + иконка типа + кнопка удалить)
 *   - progress per-file через prop fileProgress
 *   - accept / multiple
 *   - disabled, error, hint
 *   - полная a11y (role=button, aria-label, keyDown, визуальный focus)
 *   - prefers-reduced-motion (transition гасится глобальным правилом)
 *
 * USAGE: см. apps/web/docs/forms-usage.md
 */

import clsx from "clsx";
import {
  DragEvent,
  KeyboardEvent,
  useCallback,
  useId,
  useRef,
  useState,
} from "react";

// ─── Типы ──────────────────────────────────────────────────────────────────────

export interface DropzoneFile {
  /** Уникальный ключ файла в списке (обычно генерируется вызывающим кодом). */
  key: string;
  /** Исходный File-объект браузера. */
  file: File;
}

export interface DropzoneProps {
  /** Вызывается с новыми файлами после выбора/drop. */
  onFiles: (files: File[]) => void;
  /** Текущий список отображаемых файлов. */
  files?: DropzoneFile[];
  /** Вызывается при нажатии × напротив файла. */
  onRemove?: (key: string) => void;
  /**
   * Прогресс загрузки (0–100) по key файла.
   * Если ключ отсутствует — прогресс не отображается.
   */
  fileProgress?: Record<string, number>;
  /** MIME или расширения: "image/*", ".pdf,.docx". */
  accept?: string;
  /** Разрешить выбор нескольких файлов. */
  multiple?: boolean;
  /** Заблокировать зону. */
  disabled?: boolean;
  /** Сообщение об ошибке. */
  error?: string;
  /** Вспомогательный текст. */
  hint?: React.ReactNode;
  /** Основной текст в зоне. */
  label?: string;
  /** Вспомогательный текст под основным в зоне. */
  description?: string;
  /** Доп. className на root-div. */
  className?: string;
}

// ─── Компонент ────────────────────────────────────────────────────────────────

export function Dropzone({
  onFiles,
  files = [],
  onRemove,
  fileProgress = {},
  accept,
  multiple = false,
  disabled = false,
  error,
  hint,
  label = "Перетащите файлы сюда",
  description,
  className,
}: DropzoneProps) {
  const id = useId();
  const inputRef = useRef<HTMLInputElement>(null);
  const [dragOver, setDragOver] = useState(false);

  const handleFiles = useCallback(
    (fileList: FileList | null) => {
      if (!fileList || disabled) return;
      const arr = Array.from(fileList);
      if (arr.length > 0) onFiles(arr);
    },
    [disabled, onFiles],
  );

  // ── Drag events ──────────────────────────────────────────────────────────

  const handleDragEnter = useCallback(
    (e: DragEvent<HTMLDivElement>) => {
      e.preventDefault();
      e.stopPropagation();
      if (!disabled) setDragOver(true);
    },
    [disabled],
  );

  const handleDragLeave = useCallback((e: DragEvent<HTMLDivElement>) => {
    e.preventDefault();
    e.stopPropagation();
    // Убедиться что курсор покинул именно этот элемент, а не ушёл в дочерний
    if (e.currentTarget.contains(e.relatedTarget as Node)) return;
    setDragOver(false);
  }, []);

  const handleDragOver = useCallback((e: DragEvent<HTMLDivElement>) => {
    e.preventDefault();
    e.stopPropagation();
  }, []);

  const handleDrop = useCallback(
    (e: DragEvent<HTMLDivElement>) => {
      e.preventDefault();
      e.stopPropagation();
      setDragOver(false);
      if (!disabled) handleFiles(e.dataTransfer.files);
    },
    [disabled, handleFiles],
  );

  // ── Click / keyboard ─────────────────────────────────────────────────────

  const openDialog = useCallback(() => {
    if (!disabled) inputRef.current?.click();
  }, [disabled]);

  const handleKeyDown = useCallback(
    (e: KeyboardEvent<HTMLDivElement>) => {
      if (e.key === "Enter" || e.key === " ") {
        e.preventDefault();
        openDialog();
      }
    },
    [openDialog],
  );

  const defaultDescription =
    description ??
    (accept
      ? `Допустимые форматы: ${accept}`
      : multiple
        ? "Можно несколько файлов"
        : "Один файл");

  return (
    <div className={clsx("space-y-2", className)}>
      {/* Скрытый input */}
      <input
        ref={inputRef}
        id={id}
        type="file"
        accept={accept}
        multiple={multiple}
        disabled={disabled}
        className="sr-only"
        aria-hidden="true"
        tabIndex={-1}
        onChange={(e) => {
          handleFiles(e.target.files);
          // Сбросить value чтобы повторный выбор того же файла сработал
          e.target.value = "";
        }}
      />

      {/* Drop-зона */}
      <div
        role="button"
        tabIndex={disabled ? -1 : 0}
        aria-label="Зона загрузки файлов. Перетащите файлы или нажмите Enter для выбора."
        aria-disabled={disabled}
        onClick={openDialog}
        onKeyDown={handleKeyDown}
        onDragEnter={handleDragEnter}
        onDragLeave={handleDragLeave}
        onDragOver={handleDragOver}
        onDrop={handleDrop}
        className={clsx(
          "relative flex flex-col items-center justify-center gap-2",
          "rounded-lg border-2 border-dashed px-6 py-8",
          "cursor-pointer select-none",
          "transition-[border-color,background-color] duration-base ease-standard",
          "focus:outline-none focus-visible:ring-4 focus-visible:ring-primary/20",
          // Drag-over
          dragOver && !disabled
            ? "border-primary bg-primary/4 dark:border-primary-light dark:bg-primary-light/8"
            : error
              ? "border-danger bg-danger-50/60 dark:border-danger dark:bg-danger-500/5"
              : "border-gray-300 bg-gray-50 hover:border-primary/50 hover:bg-primary/3 dark:border-gray-600 dark:bg-gray-800/50 dark:hover:border-primary-light/50",
          disabled && "cursor-not-allowed opacity-50 hover:border-gray-300 hover:bg-gray-50",
        )}
      >
        {/* Иконка */}
        <div
          className={clsx(
            "flex h-12 w-12 items-center justify-center rounded-xl",
            "transition-colors duration-base",
            dragOver && !disabled
              ? "bg-primary/10 dark:bg-primary-light/15"
              : "bg-gray-100 dark:bg-gray-700",
          )}
        >
          <i
            className={clsx(
              "bi text-2xl",
              dragOver && !disabled
                ? "bi-cloud-upload text-primary dark:text-primary-light"
                : error
                  ? "bi-exclamation-circle text-danger"
                  : "bi-cloud-arrow-up text-gray-400 dark:text-gray-500",
            )}
            aria-hidden="true"
          />
        </div>

        {/* Текст */}
        <div className="text-center">
          <p
            className={clsx(
              "text-sm font-medium",
              dragOver && !disabled
                ? "text-primary dark:text-primary-light"
                : "text-gray-700 dark:text-gray-300",
            )}
          >
            {dragOver ? "Отпустите для загрузки" : label}
          </p>
          {!dragOver && (
            <p className="mt-0.5 text-xs text-gray-400 dark:text-gray-500">
              {defaultDescription}{" "}
              <span className="text-primary dark:text-primary-light underline-offset-2 hover:underline">
                или нажмите для выбора
              </span>
            </p>
          )}
        </div>
      </div>

      {/* Error / hint */}
      {error && <p className="text-xs text-danger">{error}</p>}
      {hint && !error && (
        <p className="text-xs text-gray-500 dark:text-gray-400">{hint}</p>
      )}

      {/* Список файлов */}
      {files.length > 0 && (
        <ul className="space-y-1.5" aria-label="Выбранные файлы">
          {files.map(({ key, file }) => {
            const progress = fileProgress[key];
            const hasProgress = progress !== undefined;
            const done = hasProgress && progress >= 100;

            return (
              <li
                key={key}
                className={clsx(
                  "flex items-center gap-3 rounded-lg border border-gray-200 bg-white px-3 py-2",
                  "dark:border-gray-700 dark:bg-gray-800",
                )}
              >
                {/* Иконка типа файла */}
                <span
                  className="shrink-0 text-base leading-none text-gray-400 dark:text-gray-500"
                  aria-hidden="true"
                >
                  <i className={clsx("bi", getFileIcon(file.name))} />
                </span>

                {/* Имя + размер + прогресс-бар */}
                <div className="min-w-0 flex-1">
                  <div className="flex items-center justify-between gap-2">
                    <span
                      className="truncate text-sm font-medium text-gray-700 dark:text-gray-300"
                      title={file.name}
                    >
                      {file.name}
                    </span>
                    <span className="shrink-0 text-xs tabular-nums text-gray-400 dark:text-gray-500">
                      {formatBytes(file.size)}
                    </span>
                  </div>

                  {hasProgress && (
                    <div className="mt-1.5">
                      <div
                        className="h-1 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700"
                        role="progressbar"
                        aria-valuenow={progress}
                        aria-valuemin={0}
                        aria-valuemax={100}
                        aria-label={`Загрузка ${file.name}: ${progress}%`}
                      >
                        <div
                          className={clsx(
                            "h-full rounded-full transition-[width] duration-slow ease-standard",
                            done ? "bg-success" : "bg-primary dark:bg-primary-light",
                          )}
                          style={{ width: `${Math.min(progress, 100)}%` }}
                        />
                      </div>
                    </div>
                  )}
                </div>

                {/* Кнопка удалить */}
                {onRemove && (
                  <button
                    type="button"
                    aria-label={`Удалить файл ${file.name}`}
                    onClick={() => onRemove(key)}
                    className={clsx(
                      "shrink-0 flex h-6 w-6 items-center justify-center rounded-md",
                      "text-gray-400 hover:bg-gray-100 hover:text-gray-600",
                      "dark:hover:bg-gray-700 dark:hover:text-gray-300",
                      "transition-colors duration-fast",
                    )}
                  >
                    <i className="bi bi-x text-sm leading-none" aria-hidden="true" />
                  </button>
                )}

                {/* Иконка успеха */}
                {done && (
                  <i
                    className="bi bi-check-circle-fill shrink-0 text-success text-sm"
                    aria-label="Файл загружен"
                  />
                )}
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}

// ─── Утилиты ──────────────────────────────────────────────────────────────────

/** Возвращает Bootstrap Icons класс по расширению файла. */
function getFileIcon(name: string): string {
  const ext = name.split(".").pop()?.toLowerCase() ?? "";
  const map: Record<string, string> = {
    pdf: "bi-file-earmark-pdf text-danger",
    doc: "bi-file-earmark-word text-info",
    docx: "bi-file-earmark-word text-info",
    xls: "bi-file-earmark-excel text-success",
    xlsx: "bi-file-earmark-excel text-success",
    png: "bi-file-earmark-image text-warning",
    jpg: "bi-file-earmark-image text-warning",
    jpeg: "bi-file-earmark-image text-warning",
    gif: "bi-file-earmark-image text-warning",
    webp: "bi-file-earmark-image text-warning",
    zip: "bi-file-earmark-zip text-gray-500",
    rar: "bi-file-earmark-zip text-gray-500",
    txt: "bi-file-earmark-text text-gray-500",
    csv: "bi-file-earmark-spreadsheet text-success",
    mp4: "bi-file-earmark-play text-info",
    mov: "bi-file-earmark-play text-info",
  };
  return map[ext] ?? "bi-file-earmark text-gray-400";
}

/** Форматирует размер файла: 1234 → "1.2 КБ". */
function formatBytes(bytes: number): string {
  if (bytes === 0) return "0 Б";
  const k = 1024;
  const sizes = ["Б", "КБ", "МБ", "ГБ"];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  const value = bytes / Math.pow(k, i);
  return `${value.toFixed(i === 0 ? 0 : 1)} ${sizes[i]}`;
}
