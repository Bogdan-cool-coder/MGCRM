"use client";

import { UserSelect } from "@/components/UserSelect";
import { MESSAGE_PLACEHOLDERS } from "@/lib/automationConfig";

interface Props {
  config: Record<string, unknown>;
  onChange: (next: Record<string, unknown>) => void;
}

type RecipientKind = "owner" | "specific";

/**
 * Конфиг email.
 * Получатель (ответственный или конкретный пользователь) + тема + тело.
 * SMTP настраивается на бэкенде через .env (SMTP_HOST/USER/PASS/FROM).
 */
export function EmailConfig({ config, onChange }: Props) {
  const recipientKind: RecipientKind =
    config.recipient_kind === "specific" ? "specific" : "owner";
  const recipientUserId =
    typeof config.recipient_user_id === "number"
      ? String(config.recipient_user_id)
      : typeof config.recipient_user_id === "string"
      ? config.recipient_user_id
      : "";
  const subject = typeof config.subject === "string" ? config.subject : "";
  const body = typeof config.body === "string" ? config.body : "";

  function changeRecipientKind(kind: RecipientKind) {
    const next: Record<string, unknown> = { ...config, recipient_kind: kind };
    if (kind === "owner") {
      delete next.recipient_user_id;
    }
    onChange(next);
  }

  function changeRecipientUser(userId: string) {
    onChange({
      ...config,
      recipient_kind: "specific",
      recipient_user_id: userId ? Number(userId) : null,
    });
  }

  return (
    <div className="space-y-4">
      {/* SMTP info */}
      <div className="flex items-start gap-2 text-xs text-primary bg-info/10 border border-info/30 rounded-md p-3">
        <i className="bi bi-info-circle-fill mt-0.5 shrink-0" />
        <span>
          SMTP должен быть настроен на бэкенде. Без него действие получит статус{" "}
          <strong>failed</strong>.
          <br />
          Настроить: <code>SMTP_HOST / SMTP_USER / SMTP_PASS / SMTP_FROM</code> в .env
        </span>
      </div>

      {/* Получатель */}
      <div>
        <label className="label">
          Получатель <span className="text-danger">*</span>
        </label>
        <div className="flex flex-wrap gap-4 mt-1">
          <label className="flex items-center gap-2 cursor-pointer">
            <input
              type="radio"
              name="email_recipient_kind"
              value="owner"
              checked={recipientKind === "owner"}
              onChange={() => changeRecipientKind("owner")}
            />
            <span className="text-sm">Ответственный пользователь</span>
          </label>
          <label className="flex items-center gap-2 cursor-pointer">
            <input
              type="radio"
              name="email_recipient_kind"
              value="specific"
              checked={recipientKind === "specific"}
              onChange={() => changeRecipientKind("specific")}
            />
            <span className="text-sm">Конкретный</span>
          </label>
        </div>
        {recipientKind === "specific" && (
          <div className="mt-2">
            <UserSelect
              value={recipientUserId}
              onChange={changeRecipientUser}
              placeholder="— выберите пользователя —"
            />
          </div>
        )}
      </div>

      {/* Тема */}
      <div>
        <label className="label">
          Тема <span className="text-danger">*</span>
        </label>
        <input
          className="input"
          type="text"
          value={subject}
          onChange={(e) => onChange({ ...config, subject: e.target.value })}
          placeholder="Новый лид — {{ entity.name }}"
        />
      </div>

      {/* Тело */}
      <div>
        <label className="label">
          Текст письма <span className="text-danger">*</span>
        </label>
        <textarea
          className="input"
          rows={5}
          value={body}
          onChange={(e) => onChange({ ...config, body: e.target.value })}
          placeholder="Поддерживается Jinja-синтаксис: {{ target_title }}, {{ owner_name }}"
        />
        <details className="text-xs text-gray-500 mt-1">
          <summary className="cursor-pointer">Доступные переменные</summary>
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
