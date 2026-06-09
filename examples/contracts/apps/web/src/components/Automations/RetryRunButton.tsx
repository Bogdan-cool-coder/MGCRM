"use client";

import { useState } from "react";
import { api, ApiError } from "@/lib/api";

interface Props {
  runId: number;
  onRetried: () => void;
}

type RetryState = "idle" | "loading" | "done" | "error";

export function RetryRunButton({ runId, onRetried }: Props) {
  const [state, setState] = useState<RetryState>("idle");
  const [errorMsg, setErrorMsg] = useState<string | null>(null);

  async function handleRetry(e: React.MouseEvent) {
    e.stopPropagation();
    if (state !== "idle") return;

    setState("loading");
    setErrorMsg(null);

    try {
      await api(`/automation-runs/${runId}/retry`, { method: "POST" });
      setState("done");
      setTimeout(() => {
        setState("idle");
        onRetried();
      }, 2000);
    } catch (err) {
      const msg =
        err instanceof ApiError
          ? String((err.detail as { detail?: string })?.detail ?? err.message)
          : "Не удалось запустить повторно";
      setErrorMsg(msg);
      setState("error");
      setTimeout(() => {
        setState("idle");
        setErrorMsg(null);
      }, 3000);
    }
  }

  if (state === "idle") {
    return (
      <button
        className="btn-ghost p-1"
        onClick={handleRetry}
        title="Повторить"
        aria-label="Повторить запуск"
      >
        <i className="bi bi-arrow-clockwise text-warning" />
      </button>
    );
  }

  if (state === "loading") {
    return (
      <button className="btn-ghost p-1 opacity-50 cursor-not-allowed" disabled aria-label="Запускаем повторно…">
        <i className="bi bi-arrow-clockwise animate-spin text-warning" />
      </button>
    );
  }

  if (state === "done") {
    return (
      <button className="btn-ghost p-1 cursor-default" title="Запрос отправлен">
        <i className="bi bi-check2 text-success" />
      </button>
    );
  }

  // error
  return (
    <button
      className="btn-ghost p-1 cursor-default"
      title={errorMsg ?? "Не удалось запустить повторно"}
    >
      <i className="bi bi-x-circle text-danger" />
    </button>
  );
}
