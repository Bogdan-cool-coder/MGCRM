"use client";

import { useState, useCallback } from "react";
import useSWR from "swr";
import { fetcher } from "@/lib/api";
import type { APIToken } from "@/lib/types";
import { RequestHistorySidebar, type HistoryEntry } from "./RequestHistorySidebar";
import { SnippetSaveModal } from "./SnippetSaveModal";

const HISTORY_KEY = "sandbox_history";
const BASE_URL = "https://contracts.macroglobal.tech";
const MAX_HISTORY = 20;

const METHODS = ["GET", "POST", "PUT", "PATCH", "DELETE"] as const;
type HttpMethod = typeof METHODS[number];

const METHOD_COLORS: Record<string, string> = {
  GET: "bg-info/10 text-info",
  POST: "bg-success/10 text-success",
  PUT: "bg-warning/10 text-warning",
  PATCH: "bg-warning/10 text-warning",
  DELETE: "bg-danger/10 text-danger",
};

function loadHistory(): HistoryEntry[] {
  try {
    return JSON.parse(localStorage.getItem(HISTORY_KEY) ?? "[]") as HistoryEntry[];
  } catch {
    return [];
  }
}

function saveHistory(entries: HistoryEntry[]) {
  localStorage.setItem(HISTORY_KEY, JSON.stringify(entries.slice(0, MAX_HISTORY)));
}

interface HeaderPair {
  key: string;
  value: string;
}

export function SandboxPlayground() {
  const { data: tokens } = useSWR<APIToken[]>("/api/api-tokens", fetcher);
  const sandboxToken = tokens?.find((t) => t.is_active);

  const [method, setMethod] = useState<HttpMethod>("GET");
  const [url, setUrl] = useState("/api/leads");
  const [headers, setHeaders] = useState<HeaderPair[]>([]);
  const [body, setBody] = useState("");
  const [jsonError, setJsonError] = useState<string | null>(null);

  const [sending, setSending] = useState(false);
  const [response, setResponse] = useState<{ status: number; body: string; durationMs: number } | null>(null);
  const [responseError, setResponseError] = useState<string | null>(null);
  const [responseCopied, setResponseCopied] = useState(false);

  const [history, setHistory] = useState<HistoryEntry[]>(() => loadHistory());
  const [snippetOpen, setSnippetOpen] = useState(false);
  const [currentEntry, setCurrentEntry] = useState<HistoryEntry | null>(null);

  const showBody = method !== "GET" && method !== "DELETE";

  function addHeader() {
    setHeaders((prev) => [...prev, { key: "", value: "" }]);
  }

  function updateHeader(idx: number, field: "key" | "value", val: string) {
    setHeaders((prev) => prev.map((h, i) => i === idx ? { ...h, [field]: val } : h));
  }

  function removeHeader(idx: number) {
    setHeaders((prev) => prev.filter((_, i) => i !== idx));
  }

  async function handleSend() {
    setJsonError(null);
    setResponseError(null);

    if (showBody && body.trim()) {
      try {
        JSON.parse(body);
      } catch {
        setJsonError("Невалидный JSON — проверь тело запроса");
        return;
      }
    }

    const headersMap: Record<string, string> = {};
    if (sandboxToken) {
      headersMap["Authorization"] = `Bearer <sandbox_token>`;
    }
    headers.forEach((h) => {
      if (h.key.trim()) headersMap[h.key.trim()] = h.value;
    });
    if (showBody && body.trim()) {
      headersMap["Content-Type"] = "application/json";
    }

    setSending(true);
    const startTime = Date.now();

    try {
      const fullUrl = url.startsWith("http") ? url : `${BASE_URL}${url.startsWith("/") ? "" : "/"}${url}`;
      const res = await fetch(fullUrl, {
        method,
        headers: headersMap,
        body: showBody && body.trim() ? body : undefined,
        credentials: "same-origin",
        signal: AbortSignal.timeout(30000),
      });

      const durationMs = Date.now() - startTime;
      let responseBody: string;
      const ct = res.headers.get("content-type") ?? "";
      if (ct.includes("application/json")) {
        const json = await res.json() as unknown;
        responseBody = JSON.stringify(json, null, 2);
      } else {
        responseBody = await res.text();
      }

      setResponse({ status: res.status, body: responseBody, durationMs });

      const entry: HistoryEntry = {
        method,
        url,
        statusCode: res.status,
        headers: [...headers],
        body,
        timestamp: Date.now(),
      };
      setCurrentEntry(entry);
      const newHistory = [entry, ...history];
      setHistory(newHistory);
      saveHistory(newHistory);
    } catch (err) {
      if (err instanceof Error && err.name === "TimeoutError") {
        setResponseError("Превышено время ожидания (30 сек)");
      } else {
        setResponseError("Ошибка соединения — проверь доступность API");
      }
      setResponse(null);
    } finally {
      setSending(false);
    }
  }

  const handleHistorySelect = useCallback((entry: HistoryEntry) => {
    setMethod(entry.method as HttpMethod);
    setUrl(entry.url);
    setHeaders([...entry.headers]);
    setBody(entry.body);
    setResponse(null);
    setResponseError(null);
  }, []);

  function handleClearHistory() {
    if (!confirm("Очистить историю запросов?")) return;
    localStorage.removeItem(HISTORY_KEY);
    setHistory([]);
  }

  function handleCopyResponse() {
    if (!response) return;
    navigator.clipboard.writeText(response.body).then(() => {
      setResponseCopied(true);
      setTimeout(() => setResponseCopied(false), 2000);
    }).catch(() => {});
  }

  function getStatusBg(status: number) {
    if (status < 300) return "bg-success/10 text-success";
    if (status < 400) return "bg-info/10 text-info";
    return "bg-danger/10 text-danger";
  }

  return (
    <div className="flex gap-6">
      {/* Main panel */}
      <div className="flex-1 space-y-4">
        {/* No sandbox token warning */}
        {!sandboxToken && (
          <div className="bg-warning-50 dark:bg-warning-500/10 text-warning-700 dark:text-warning-400 rounded-xl p-3 text-sm flex items-center gap-2 border border-warning-200 dark:border-warning-500/20">
            <i className="bi bi-exclamation-triangle shrink-0" />
            Создай sandbox-токен для авторизации запросов
          </div>
        )}

        {/* Request URL row */}
        <div className="card rounded-2xl shadow-elev-1 border border-gray-100 dark:border-gray-800 p-4">
          <label className="label text-xs mb-2">URL запроса</label>
          <div className="flex gap-2 items-center">
            <select
              className="input w-28 font-mono shrink-0"
              value={method}
              onChange={(e) => setMethod(e.target.value as HttpMethod)}
            >
              {METHODS.map((m) => (
                <option key={m} value={m}>{m}</option>
              ))}
            </select>
            <input
              className="input flex-1 font-mono"
              placeholder="/api/leads"
              value={url}
              onChange={(e) => setUrl(e.target.value)}
            />
            <button
              className="btn-primary shrink-0"
              onClick={handleSend}
              disabled={sending}
            >
              <i className="bi bi-send mr-1" />
              {sending ? "Отправляем…" : "Отправить"}
            </button>
          </div>
          <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">
            Базовый URL: {BASE_URL}
          </p>
        </div>

        {/* Headers */}
        <div className="card rounded-2xl shadow-elev-1 border border-gray-100 dark:border-gray-800 p-4">
          <label className="label text-xs mb-2">Заголовки</label>
          {sandboxToken && (
            <div className="flex gap-2 mb-2 opacity-60">
              <input className="input flex-1 font-mono text-xs" value="Authorization" readOnly />
              <input className="input flex-1 font-mono text-xs" value={`Bearer <sandbox_token>`} readOnly />
              <div className="w-8" />
            </div>
          )}
          <div className="space-y-2">
            {headers.map((h, idx) => (
              <div key={idx} className="flex gap-2">
                <input
                  className="input flex-1 font-mono text-xs"
                  placeholder="Header-Name"
                  value={h.key}
                  onChange={(e) => updateHeader(idx, "key", e.target.value)}
                />
                <input
                  className="input flex-1 font-mono text-xs"
                  placeholder="value"
                  value={h.value}
                  onChange={(e) => updateHeader(idx, "value", e.target.value)}
                />
                <button
                  className="btn-ghost text-danger w-8 px-0 shrink-0"
                  onClick={() => removeHeader(idx)}
                >
                  <i className="bi bi-x" />
                </button>
              </div>
            ))}
          </div>
          <button className="btn-ghost text-xs mt-2" onClick={addHeader}>
            <i className="bi bi-plus mr-1" />
            Добавить заголовок
          </button>
        </div>

        {/* Body */}
        {showBody && (
          <div className="card rounded-2xl shadow-elev-1 border border-gray-100 dark:border-gray-800 p-4">
            <label className="label text-xs mb-2">Тело запроса (JSON)</label>
            <textarea
              className="input font-mono text-sm w-full"
              rows={8}
              placeholder="{}"
              value={body}
              onChange={(e) => { setBody(e.target.value); setJsonError(null); }}
            />
            {jsonError && (
              <p className="text-danger text-xs mt-1">{jsonError}</p>
            )}
          </div>
        )}

        {/* Actions */}
        <div className="flex gap-2">
          <button
            className="btn-secondary"
            disabled={!currentEntry}
            onClick={() => setSnippetOpen(true)}
          >
            <i className="bi bi-bookmark-plus mr-1" />
            Сохранить сниппет
          </button>
        </div>

        {/* Response */}
        {(response || responseError) && (
          <div className="card rounded-2xl shadow-elev-1 border border-gray-100 dark:border-gray-800 p-4">
            <div className="flex items-center justify-between mb-3">
              <span className="text-sm font-medium text-gray-700 dark:text-gray-300">Ответ</span>
              <div className="flex items-center gap-2">
                {response && (
                  <>
                    <span className={`badge text-xs ${getStatusBg(response.status)}`}>
                      {response.status}
                    </span>
                    <span className="text-xs text-gray-500 dark:text-gray-400">{response.durationMs} мс</span>
                    <button className="btn-ghost text-xs" onClick={handleCopyResponse}>
                      <i className={`bi ${responseCopied ? "bi-check-lg" : "bi-clipboard"}`} />
                      {responseCopied ? " Скопировано" : " Копировать"}
                    </button>
                  </>
                )}
              </div>
            </div>
            {responseError && (
              <div className="text-danger text-sm">{responseError}</div>
            )}
            {response && (
              <pre className="bg-gray-900 text-green-400 rounded-lg p-4 text-sm font-mono overflow-auto max-h-80">
                {response.body}
              </pre>
            )}
          </div>
        )}
      </div>

      {/* History sidebar */}
      <RequestHistorySidebar
        history={history}
        onSelect={handleHistorySelect}
        onClear={handleClearHistory}
      />

      <SnippetSaveModal
        open={snippetOpen}
        entry={currentEntry}
        onClose={() => setSnippetOpen(false)}
        onSaved={() => {}}
      />
    </div>
  );
}
