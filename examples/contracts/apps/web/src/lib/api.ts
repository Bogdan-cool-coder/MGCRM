"use client";

export class ApiError extends Error {
  status: number;
  detail: unknown;
  constructor(status: number, detail: unknown, message?: string) {
    super(message ?? `HTTP ${status}`);
    this.status = status;
    this.detail = detail;
  }
}

type RequestOptions = {
  method?: "GET" | "POST" | "PUT" | "PATCH" | "DELETE";
  body?: unknown;
  query?: Record<string, string | number | boolean | undefined | null>;
};

export async function api<T = unknown>(path: string, opts: RequestOptions = {}): Promise<T> {
  const { method = "GET", body, query } = opts;
  let url = path.startsWith("/api") ? path : `/api${path.startsWith("/") ? path : `/${path}`}`;
  if (query) {
    const qs = new URLSearchParams();
    for (const [k, v] of Object.entries(query)) {
      if (v !== undefined && v !== null && v !== "") qs.set(k, String(v));
    }
    const s = qs.toString();
    if (s) url += `?${s}`;
  }

  const res = await fetch(url, {
    method,
    headers: body ? { "Content-Type": "application/json" } : undefined,
    body: body ? JSON.stringify(body) : undefined,
    credentials: "same-origin",
  });

  if (!res.ok) {
    let detail: unknown = await res.text();
    try {
      detail = JSON.parse(detail as string);
    } catch {
      /* keep as text */
    }
    throw new ApiError(res.status, detail);
  }
  if (res.status === 204) return undefined as T;
  const ct = res.headers.get("content-type") ?? "";
  if (ct.includes("application/json")) return (await res.json()) as T;
  return (await res.text()) as T;
}

export const fetcher = <T = unknown>(path: string): Promise<T> => api<T>(path);

/**
 * Достаёт человекочитаемое сообщение из пойманной ошибки.
 * Понимает FastAPI {detail: "..."} и 422 {detail: [{msg, loc}]}.
 * Используем для inline-error в формах (глобального toast в проекте нет).
 */
export function errorMessage(err: unknown, fallback = "Не удалось выполнить действие"): string {
  if (err instanceof ApiError) {
    const d = err.detail;
    if (typeof d === "string" && d.trim()) return d;
    if (d && typeof d === "object") {
      const detail = (d as { detail?: unknown }).detail;
      if (typeof detail === "string" && detail.trim()) return detail;
      if (Array.isArray(detail)) {
        const msgs = detail
          .map((item) =>
            item && typeof item === "object" && typeof (item as { msg?: unknown }).msg === "string"
              ? (item as { msg: string }).msg
              : null,
          )
          .filter((m): m is string => Boolean(m));
        if (msgs.length) return msgs.join("; ");
      }
    }
    return err.message || fallback;
  }
  if (err instanceof Error && err.message) return err.message;
  return fallback;
}
