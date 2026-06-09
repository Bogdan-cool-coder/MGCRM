"use client";

import { useState } from "react";
import { api, ApiError } from "@/lib/api";
import type { Channel } from "@/lib/types";

interface Props {
  channel: Channel;
  isAdmin: boolean;
  onRegenerated: (channel: Channel) => void;
}

/** URL webhook'а + copy + regen.
 *
 * Webhook URL формируется по схеме `/api/inbox/webhook/{channel_id}`.
 * Секрет (X-Channel-Token) показываем отдельно с copy — это уже сам токен.
 */
export function WebhookCell({ channel, isAdmin, onRegenerated }: Props) {
  const [pending, setPending] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  const [copied, setCopied] = useState<"url" | "token" | null>(null);

  // URL абсолютный — чтобы пользователь мог сразу копировать в curl/Postman.
  const origin = typeof window !== "undefined" ? window.location.origin : "";
  const url = `${origin}/api/inbox/webhook/${channel.id}`;

  async function copy(value: string, key: "url" | "token") {
    try {
      await navigator.clipboard.writeText(value);
      setCopied(key);
      setTimeout(() => setCopied(null), 1500);
    } catch {
      // Fallback: показываем в alert (старые браузеры/HTTPS-only)
      alert(value);
    }
  }

  // Полный токен не приходит в списке — запрашиваем его через admin-only
  // reveal-token и сразу копируем (C4 CRIT-3: токен не светится всем).
  async function revealAndCopy() {
    setPending(true);
    setErr(null);
    try {
      const full = await api<Channel>(`/channels/${channel.id}/reveal-token`);
      if (full.secret_token) {
        await copy(full.secret_token, "token");
      } else {
        setErr("Токен недоступен");
      }
    } catch (e) {
      setErr(
        e instanceof ApiError
          ? String((e.detail as { detail?: string })?.detail ?? e.message)
          : "Не удалось получить токен",
      );
    } finally {
      setPending(false);
    }
  }

  async function regen() {
    if (
      !confirm(
        `Перегенерировать токен канала «${channel.name}»? Старый токен перестанет работать — обновите его во всех внешних системах.`,
      )
    ) {
      return;
    }
    setPending(true);
    setErr(null);
    try {
      const next = await api<Channel>(`/channels/${channel.id}/regenerate-token`, {
        method: "POST",
      });
      onRegenerated(next);
    } catch (e) {
      setErr(
        e instanceof ApiError
          ? String((e.detail as { detail?: string })?.detail ?? e.message)
          : "Не удалось перегенерировать",
      );
    } finally {
      setPending(false);
    }
  }

  return (
    <div className="flex flex-col gap-1 text-xs">
      <div className="flex items-center gap-1">
        <code className="font-mono bg-gray-100 px-1.5 py-0.5 rounded truncate max-w-[260px]" title={url}>
          {url}
        </code>
        <button
          type="button"
          onClick={() => copy(url, "url")}
          className="text-gray-500 hover:text-primary"
          title="Копировать URL"
        >
          <i className={`bi ${copied === "url" ? "bi-check2" : "bi-copy"}`} />
        </button>
      </div>
      <div className="flex items-center gap-1">
        <span className="text-gray-500">X-Channel-Token:</span>
        <code className="font-mono bg-gray-100 px-1.5 py-0.5 rounded truncate max-w-[180px]" title="Токен замаскирован — полный доступен только администратору">
          {channel.secret_token_preview}
        </code>
        {/* Полный токен виден только admin/director через reveal-token (C4 CRIT-3). */}
        {isAdmin && (
          <button
            type="button"
            onClick={revealAndCopy}
            disabled={pending}
            className="text-gray-500 hover:text-primary disabled:opacity-60"
            title="Показать и скопировать полный токен"
          >
            <i className={`bi ${copied === "token" ? "bi-check2" : "bi-copy"}`} />
          </button>
        )}
        {isAdmin && (
          <button
            type="button"
            onClick={regen}
            disabled={pending}
            className="text-gray-500 hover:text-danger disabled:opacity-60"
            title="Перегенерировать токен"
          >
            <i className={`bi ${pending ? "bi-arrow-repeat animate-spin" : "bi-arrow-repeat"}`} />
          </button>
        )}
      </div>
      {err && <span className="text-danger">{err}</span>}
    </div>
  );
}
