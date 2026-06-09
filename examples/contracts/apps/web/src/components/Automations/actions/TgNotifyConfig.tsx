"use client";

import useSWR from "swr";
import { fetcher } from "@/lib/api";
import { MESSAGE_PLACEHOLDERS } from "@/lib/automationConfig";
import type { User } from "@/lib/types";

interface Props {
  config: Record<string, unknown>;
  onChange: (next: Record<string, unknown>) => void;
}

type RecipientKind = "owner" | "user" | "chat";

function parseRecipient(spec: string | undefined): { kind: RecipientKind; value: string } {
  if (!spec || spec === "owner") return { kind: "owner", value: "" };
  if (spec.startsWith("user_id:")) return { kind: "user", value: spec.slice("user_id:".length) };
  if (spec.startsWith("chat_id:")) return { kind: "chat", value: spec.slice("chat_id:".length) };
  return { kind: "owner", value: "" };
}

function buildRecipient(kind: RecipientKind, value: string): string {
  if (kind === "owner") return "owner";
  if (kind === "user") return `user_id:${value || ""}`;
  return `chat_id:${value || ""}`;
}

/** Конфиг tg_notify. recipient (owner | user_id:N | chat_id:N) + message. */
export function TgNotifyConfig({ config, onChange }: Props) {
  const recipientSpec = typeof config.recipient === "string" ? config.recipient : "owner";
  const message = typeof config.message === "string" ? config.message : "";
  const parsed = parseRecipient(recipientSpec);

  const { data: users } = useSWR<User[]>(parsed.kind === "user" ? "/users" : null, fetcher);

  function updateRecipient(kind: RecipientKind, value: string) {
    onChange({ ...config, recipient: buildRecipient(kind, value) });
  }

  return (
    <div className="space-y-3">
      <div>
        <label className="label">Кому отправить</label>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-2">
          <select
            className="input"
            value={parsed.kind}
            onChange={(e) => updateRecipient(e.target.value as RecipientKind, "")}
          >
            <option value="owner">Владельцу цели</option>
            <option value="user">Конкретному пользователю</option>
            <option value="chat">В чат / группу (chat_id)</option>
          </select>
          {parsed.kind === "user" && (
            <select
              className="input"
              value={parsed.value}
              onChange={(e) => updateRecipient("user", e.target.value)}
            >
              <option value="">— выберите пользователя —</option>
              {(users ?? []).map((u) => (
                <option key={u.id} value={String(u.id)}>{u.full_name}</option>
              ))}
            </select>
          )}
          {parsed.kind === "chat" && (
            <input
              className="input"
              type="text"
              placeholder="например, -1001234567890"
              value={parsed.value}
              onChange={(e) => updateRecipient("chat", e.target.value)}
            />
          )}
        </div>
        <div className="text-xs text-gray-500 mt-1">
          Для «Владельцу» — у пользователя должен быть привязан Telegram. Иначе — skipped.
        </div>
      </div>

      <div>
        <label className="label">Текст сообщения</label>
        <textarea
          className="input"
          rows={4}
          value={message}
          onChange={(e) => onChange({ ...config, message: e.target.value })}
          placeholder="Например: Сделка {target_title} висит на этапе. Ответственный — {owner_name}"
        />
        <details className="text-xs text-gray-500 mt-1">
          <summary className="cursor-pointer">Доступные плейсхолдеры</summary>
          <ul className="mt-1 space-y-0.5">
            {MESSAGE_PLACEHOLDERS.map((p) => (
              <li key={p.token}>
                <code className="text-primary">{p.token}</code> — {p.description}
              </li>
            ))}
          </ul>
        </details>
      </div>
    </div>
  );
}
