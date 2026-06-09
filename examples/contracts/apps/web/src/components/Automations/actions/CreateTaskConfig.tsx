"use client";

import useSWR from "swr";
import { fetcher } from "@/lib/api";
import { MESSAGE_PLACEHOLDERS } from "@/lib/automationConfig";
import type { User } from "@/lib/types";

interface Props {
  config: Record<string, unknown>;
  onChange: (next: Record<string, unknown>) => void;
}

type ResponsibleKind = "owner" | "user";

function parseResponsible(spec: string | undefined): { kind: ResponsibleKind; value: string } {
  if (!spec || spec === "owner") return { kind: "owner", value: "" };
  if (spec.startsWith("user_id:")) return { kind: "user", value: spec.slice("user_id:".length) };
  return { kind: "owner", value: "" };
}

function buildResponsible(kind: ResponsibleKind, value: string): string {
  if (kind === "owner") return "owner";
  return `user_id:${value || ""}`;
}

/** Конфиг create_task. title (template) + body? + responsible + due_days. */
export function CreateTaskConfig({ config, onChange }: Props) {
  const title = typeof config.title === "string" ? config.title : "";
  const body = typeof config.body === "string" ? config.body : "";
  const responsibleSpec = typeof config.responsible === "string" ? config.responsible : "owner";
  const parsed = parseResponsible(responsibleSpec);
  const dueDaysRaw = config.due_days;
  const dueDays =
    typeof dueDaysRaw === "number" ? dueDaysRaw :
    typeof dueDaysRaw === "string" && dueDaysRaw !== "" ? Number(dueDaysRaw) :
    "";

  const { data: users } = useSWR<User[]>(parsed.kind === "user" ? "/users" : null, fetcher);

  function updateResponsible(kind: ResponsibleKind, value: string) {
    onChange({ ...config, responsible: buildResponsible(kind, value) });
  }

  return (
    <div className="space-y-3">
      <div>
        <label className="label">Заголовок задачи</label>
        <input
          className="input"
          type="text"
          value={title}
          onChange={(e) => onChange({ ...config, title: e.target.value })}
          placeholder="Например: Перезвонить по {target_title}"
        />
        <div className="text-xs text-gray-500 mt-1">
          Пусто → «Автозадача: ИМЯ_АВТОМАТИЗАЦИИ».
        </div>
      </div>

      <div>
        <label className="label">Описание задачи (опционально)</label>
        <textarea
          className="input"
          rows={3}
          value={body}
          onChange={(e) => onChange({ ...config, body: e.target.value })}
          placeholder="Например: Уточнить статус по сделке. Владелец — {owner_name}"
        />
      </div>

      <div>
        <label className="label">Ответственный</label>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-2">
          <select
            className="input"
            value={parsed.kind}
            onChange={(e) => updateResponsible(e.target.value as ResponsibleKind, "")}
          >
            <option value="owner">Владелец цели</option>
            <option value="user">Конкретный пользователь</option>
          </select>
          {parsed.kind === "user" && (
            <select
              className="input"
              value={parsed.value}
              onChange={(e) => updateResponsible("user", e.target.value)}
            >
              <option value="">— выберите пользователя —</option>
              {(users ?? []).map((u) => (
                <option key={u.id} value={String(u.id)}>{u.full_name}</option>
              ))}
            </select>
          )}
        </div>
      </div>

      <div>
        <label className="label">Срок (через сколько дней)</label>
        <input
          className="input"
          type="number"
          min={0}
          value={dueDays === "" ? "" : dueDays}
          onChange={(e) => {
            const raw = e.target.value;
            if (raw === "") {
              // Удаляем due_days из конфига полностью, чтобы backend не считал это «срок 0 дней».
              const next: Record<string, unknown> = { ...config };
              delete next.due_days;
              onChange(next);
            } else {
              onChange({ ...config, due_days: Math.max(0, Number(raw) || 0) });
            }
          }}
          placeholder="Пусто = без срока"
        />
        <div className="text-xs text-gray-500 mt-1">
          Например, 1 = через сутки. Пусто = задача без дедлайна.
        </div>
      </div>

      <details className="text-xs text-gray-500">
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
  );
}
