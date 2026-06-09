"use client";

import { memo, useEffect, useRef, useState } from "react";
import Link from "next/link";
import { api, ApiError } from "@/lib/api";
import { VariablesModal } from "@/components/Templates/VariablesModal";

const PLACEHOLDER_ID = "onlyoffice-editor";

type EditorConfigResponse = { ds_url: string; config: Record<string, unknown> };
type DocEditorInstance = { destroyEditor?: () => void };
type DocEditorCtor = new (placeholderId: string, config: Record<string, unknown>) => DocEditorInstance;
type DocsAPINS = { DocEditor: DocEditorCtor };

function getDocsAPI(): DocsAPINS | undefined {
  if (typeof window === "undefined") return undefined;
  return (window as unknown as { DocsAPI?: DocsAPINS }).DocsAPI;
}

/**
 * Изолированный контейнер для OnlyOffice DocEditor. DS мутирует потомков этого div
 * (вставляет iframe и UI), а React-реконсилятор при re-render родителя НЕ должен
 * трогать это поддерево — иначе ловим «NotFoundError: insertBefore … not a child».
 * memo без props гарантирует, что React пропустит реконсиляцию при апдейтах outside.
 */
const EditorContainer = memo(function EditorContainer() {
  return <div id={PLACEHOLDER_ID} className="flex-1 min-h-0" suppressHydrationWarning />;
});

// Soft-бейдж статуса редактора
function StatusBadge({
  status,
}: {
  status: "loading" | "ready" | "error";
}) {
  const map = {
    loading: {
      cls: "bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400",
      icon: "bi-hourglass-split",
      label: "Загрузка редактора…",
    },
    ready: {
      cls: "bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400",
      icon: "bi-check-circle",
      label: "Редактор готов",
    },
    error: {
      cls: "bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400",
      icon: "bi-exclamation-circle",
      label: "Ошибка",
    },
  } as const;
  const s = map[status];
  return (
    <span
      className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ${s.cls}`}
    >
      <i className={`bi ${s.icon}`} aria-hidden="true" />
      {s.label}
    </span>
  );
}

/**
 * WYSIWYG-редактор master_skeleton.docx на OnlyOffice Document Server.
 * Конфиг (подписанный JWT) и публичный URL DS приходят с backend
 * (GET /templates/master-skeleton/editor-config). Грузим api.js DS динамически,
 * инициализируем DocEditor. Сохранение — server-to-server через callback DS.
 */
export default function EditMasterSkeletonPage() {
  const editorRef = useRef<DocEditorInstance | null>(null);
  const scriptElRef = useRef<HTMLScriptElement | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [editorStatus, setEditorStatus] = useState<"loading" | "ready" | "error">("loading");
  const [varsOpen, setVarsOpen] = useState(false);
  const [previewLoading, setPreviewLoading] = useState(false);
  const [previewError, setPreviewError] = useState<string | null>(null);

  async function previewMaster() {
    setPreviewLoading(true);
    setPreviewError(null);
    try {
      const res = await fetch("/api/templates/by-code/master_skeleton/preview", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({}),
        credentials: "same-origin",
      });
      if (!res.ok) {
        const txt = await res.text();
        let msg = txt;
        try {
          msg = String(JSON.parse(txt).detail ?? txt);
        } catch {
          /* keep text */
        }
        setPreviewError(`Не удалось сгенерировать preview: ${msg}`);
        return;
      }
      const blob = await res.blob();
      const url = URL.createObjectURL(blob);
      window.open(url, "_blank", "noopener,noreferrer");
    } finally {
      setPreviewLoading(false);
    }
  }

  useEffect(() => {
    let cancelled = false;

    function loadScript(src: string): Promise<void> {
      return new Promise((resolve, reject) => {
        if (getDocsAPI()) {
          resolve();
          return;
        }
        const el = document.createElement("script");
        el.src = src;
        el.async = true;
        el.onload = () => resolve();
        el.onerror = () => reject(new Error("Не удалось загрузить скрипт OnlyOffice"));
        document.body.appendChild(el);
        scriptElRef.current = el;
      });
    }

    (async () => {
      try {
        const { ds_url, config } = await api<EditorConfigResponse>(
          "/templates/master-skeleton/editor-config",
        );
        if (cancelled) return;
        await loadScript(`${ds_url}/web-apps/apps/api/documents/api.js`);
        if (cancelled) return;
        const DocsAPI = getDocsAPI();
        if (!DocsAPI) throw new Error("OnlyOffice API недоступен (api.js загрузился, но DocsAPI отсутствует)");
        editorRef.current = new DocsAPI.DocEditor(PLACEHOLDER_ID, {
          ...config,
          width: "100%",
          height: "100%",
          events: {
            onAppReady: () => {
              if (!cancelled) setEditorStatus("ready");
            },
            onError: (e: unknown) => {
              let detail = "";
              try {
                detail = e && typeof e === "object" ? JSON.stringify(e, null, 2) : String(e);
              } catch {
                detail = String(e);
              }
              console.error("OnlyOffice editor error (stringified):", detail);
              console.error("OnlyOffice editor error (raw):", e);
            },
          },
        });
      } catch (e) {
        if (cancelled) return;
        setEditorStatus("error");
        if (e instanceof ApiError && e.status === 503) {
          setError("OnlyOffice не настроен на сервере (нужны DNS, секрет и профиль onlyoffice).");
        } else {
          setError(e instanceof Error ? e.message : String(e));
        }
      }
    })();

    return () => {
      cancelled = true;
      try {
        editorRef.current?.destroyEditor?.();
      } catch {
        /* editor мог не инициализироваться */
      }
      editorRef.current = null;
      if (scriptElRef.current?.parentNode) {
        scriptElRef.current.parentNode.removeChild(scriptElRef.current);
      }
      scriptElRef.current = null;
    };
  }, []);

  return (
    <div className="flex flex-col h-screen bg-gray-50 dark:bg-gray-950">
      {/* Тулбар */}
      <header className="flex items-center gap-3 px-5 py-3 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700 shrink-0 shadow-sm">
        <Link
          href="/admin/templates"
          className="btn-ghost flex items-center gap-1.5 text-sm"
        >
          <i className="bi bi-arrow-left" />
          К шаблонам
        </Link>

        <div className="h-5 w-px bg-gray-200 dark:bg-gray-700" />

        <div className="flex items-center gap-2 min-w-0">
          <i className="bi bi-file-earmark-word text-primary dark:text-blue-300 text-lg shrink-0" />
          <span className="font-semibold text-sm text-gray-900 dark:text-gray-100 truncate">
            master_skeleton.docx
          </span>
        </div>

        <div className="ml-1">
          <StatusBadge status={editorStatus} />
        </div>

        <div className="ml-auto flex items-center gap-2">
          <button
            type="button"
            onClick={() => setVarsOpen(true)}
            className="btn-secondary"
          >
            <i className="bi bi-braces mr-1" /> Переменные
          </button>
          <button
            type="button"
            onClick={previewMaster}
            disabled={previewLoading}
            className="btn-secondary"
          >
            <i className="bi bi-eye mr-1" />
            {previewLoading ? "Генерация…" : "Preview"}
          </button>
        </div>
      </header>

      {/* Подсказка под тулбаром */}
      <div className="px-5 py-1.5 bg-white dark:bg-gray-900 border-b border-gray-100 dark:border-gray-800 shrink-0">
        <p className="text-xs text-gray-400 dark:text-gray-500">
          Сохранение автоматическое при закрытии.{" "}
          Теги <code className="font-mono bg-gray-100 dark:bg-gray-800 px-1 rounded">{"{{ }}"}</code>{" "}
          / <code className="font-mono bg-gray-100 dark:bg-gray-800 px-1 rounded">{"{% %}"}</code>{" "}
          чинятся на сервере.
        </p>
      </div>

      {/* Ошибка инициализации */}
      {error && (
        <div className="mx-5 mt-4 shrink-0 flex items-start gap-3 rounded-xl border border-danger/30 bg-danger-50 dark:bg-danger-500/10 px-4 py-3">
          <i className="bi bi-exclamation-triangle text-danger mt-0.5 shrink-0" />
          <div className="min-w-0">
            <p className="text-sm font-semibold text-danger-700 dark:text-danger-400">
              Не удалось загрузить редактор
            </p>
            <p className="text-xs text-danger-600 dark:text-danger-300 mt-0.5 break-words">
              {error}
            </p>
          </div>
        </div>
      )}

      {/* Ошибка preview */}
      {previewError && (
        <div className="mx-5 mt-3 shrink-0 flex items-start gap-3 rounded-xl border border-danger/30 bg-danger-50 dark:bg-danger-500/10 px-4 py-3">
          <i className="bi bi-exclamation-circle text-danger mt-0.5 shrink-0" />
          <p className="text-sm text-danger-700 dark:text-danger-400">{previewError}</p>
        </div>
      )}

      {/* DocEditor контейнер — React.memo, не трогаем */}
      <EditorContainer />

      <VariablesModal
        open={varsOpen}
        onClose={() => setVarsOpen(false)}
        templateCode="master_skeleton"
      />
    </div>
  );
}
