"use client";

import { useEffect, useState } from "react";
import { useSearchParams } from "next/navigation";
import useSWR from "swr";
import { api, ApiError, fetcher } from "@/lib/api";
import { Field } from "@/components/Field";

interface DriveStatus {
  has_client_config: boolean;
  configured: boolean;
  connected_email: string | null;
  redirect_uri: string;
}

export function GoogleDriveSetup() {
  const { data: status, mutate } = useSWR<DriveStatus>("/integrations/google-drive/status", fetcher);
  const searchParams = useSearchParams();

  const [clientId, setClientId] = useState("");
  const [clientSecret, setClientSecret] = useState("");
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<{ kind: "ok" | "err"; text: string } | null>(null);

  useEffect(() => {
    const g = searchParams.get("google");
    if (g === "connected") {
      setMsg({ kind: "ok", text: `Google подключён: ${searchParams.get("email") ?? ""}` });
      void mutate();
    } else if (g === "error") {
      setMsg({ kind: "err", text: `Ошибка подключения: ${searchParams.get("reason") ?? "неизвестно"}` });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function saveConfig() {
    if (!clientId.trim() || !clientSecret.trim()) {
      setMsg({ kind: "err", text: "Введите Client ID и Client Secret" });
      return;
    }
    setBusy(true); setMsg(null);
    try {
      await api("/integrations/google-drive/oauth/config", {
        method: "POST", body: { client_id: clientId, client_secret: clientSecret },
      });
      await mutate();
      setMsg({ kind: "ok", text: "Client ID/Secret сохранены. Теперь нажмите «Подключить Google»." });
      setClientSecret("");
    } catch (err) {
      setMsg({ kind: "err", text: err instanceof ApiError ? String((err.detail as { detail?: string })?.detail ?? err.message) : "Ошибка" });
    } finally { setBusy(false); }
  }

  async function connect() {
    setBusy(true); setMsg(null);
    try {
      const res = await api<{ auth_url: string }>("/integrations/google-drive/oauth/start");
      window.location.href = res.auth_url;
    } catch (err) {
      setMsg({ kind: "err", text: err instanceof ApiError ? String((err.detail as { detail?: string })?.detail ?? err.message) : "Ошибка" });
      setBusy(false);
    }
  }

  async function disconnect() {
    if (!confirm("Отключить Google Drive?")) return;
    setBusy(true); setMsg(null);
    try {
      await api("/integrations/google-drive/oauth/disconnect", { method: "POST" });
      await mutate();
      setMsg({ kind: "ok", text: "Google Drive отключён" });
    } finally { setBusy(false); }
  }

  function copyRedirect() {
    if (status?.redirect_uri) {
      void navigator.clipboard.writeText(status.redirect_uri);
      setMsg({ kind: "ok", text: "Redirect URI скопирован" });
    }
  }

  return (
    <div className="space-y-4">
      {status?.configured && status.connected_email && (
        <div className="bg-success/20 rounded p-3 text-sm">
          Подключён аккаунт: <b>{status.connected_email}</b>. Договоры будут выгружаться от его имени.
        </div>
      )}

      {msg && (
        <div className={`text-sm rounded-md px-3 py-2 ${msg.kind === "ok" ? "bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-500" : "bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-500"}`}>
          {msg.text}
        </div>
      )}

      {/* Redirect URI */}
      <div className="bg-gray-100 dark:bg-gray-700 rounded p-3 text-sm">
        <div className="text-xs text-gray-500 dark:text-gray-400 mb-1">
          Redirect URI (вставьте в Google Console при создании OAuth client)
        </div>
        <div className="flex items-center gap-2">
          <code className="flex-1 break-all text-xs">{status?.redirect_uri ?? "…"}</code>
          <button onClick={copyRedirect} className="btn-ghost text-xs shrink-0">
            <i className="bi bi-clipboard" /> Копировать
          </button>
        </div>
      </div>

      {/* Client ID / Secret */}
      <div className="space-y-3">
        <Field label="Client ID" value={clientId} onChange={setClientId} placeholder="…apps.googleusercontent.com" />
        <Field
          label="Client Secret"
          type="password"
          value={clientSecret}
          onChange={setClientSecret}
          placeholder={status?.has_client_config ? "•••••••• (сохранён)" : "GOCSPX-…"}
        />
        <button onClick={saveConfig} disabled={busy} className="btn-secondary">
          <i className="bi bi-save" /> Сохранить Client ID/Secret
        </button>
      </div>

      {/* Connect/Disconnect */}
      <div className="flex flex-wrap gap-2 pt-3 border-t border-gray-200 dark:border-gray-700">
        <button onClick={connect} disabled={busy || !status?.has_client_config} className="btn-primary">
          <i className="bi bi-google" /> {status?.configured ? "Переподключить Google" : "Подключить Google"}
        </button>
        {status?.configured && (
          <button onClick={disconnect} disabled={busy} className="btn-ghost text-danger">
            <i className="bi bi-x-circle" /> Отключить
          </button>
        )}
      </div>

      <details className="text-sm text-gray-700 dark:text-gray-300">
        <summary className="cursor-pointer font-medium text-primary">Инструкция по настройке</summary>
        <ol className="list-decimal pl-5 mt-3 space-y-2">
          <li>В Google Cloud Console настройте <b>OAuth consent screen</b>: тип <b>Internal</b>.</li>
          <li><b>APIs &amp; Services → Library</b> → включите <b>Google Drive API</b>.</li>
          <li><b>Credentials → Create credentials → OAuth client ID</b> → тип <b>Web application</b>.</li>
          <li>В <b>Authorized redirect URIs</b> вставьте URI выше.</li>
          <li>Скопируйте <b>Client ID</b> и <b>Client Secret</b> → вставьте выше → «Сохранить».</li>
          <li>Нажмите <b>«Подключить Google»</b> → выберите аккаунт → разрешите доступ.</li>
        </ol>
      </details>
    </div>
  );
}
