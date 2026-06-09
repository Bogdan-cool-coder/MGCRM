"use client";

interface Props {
  config: Record<string, unknown>;
  onChange: (next: Record<string, unknown>) => void;
}

/**
 * Конфиг webhook.
 * URL (обязательный) + секрет (опц.) + дополнительные заголовки (JSON, опц.).
 * Тело запроса формирует бэкенд: { event, automation_id, target_type, target_id, payload }.
 */
export function WebhookConfig({ config, onChange }: Props) {
  const url = typeof config.url === "string" ? config.url : "";
  const secret = typeof config.secret === "string" ? config.secret : "";
  const headersRaw =
    typeof config.headers === "string"
      ? config.headers
      : config.headers !== undefined
      ? JSON.stringify(config.headers, null, 2)
      : "";

  return (
    <div className="space-y-3">
      <div>
        <label className="label">
          URL <span className="text-danger">*</span>
        </label>
        <input
          className="input"
          type="url"
          value={url}
          onChange={(e) => onChange({ ...config, url: e.target.value })}
          placeholder="https://..."
        />
      </div>

      <div>
        <label className="label">Секрет (опционально)</label>
        <input
          className="input"
          type="text"
          value={secret}
          onChange={(e) => onChange({ ...config, secret: e.target.value })}
          placeholder="Подпись запроса"
        />
        <div className="text-xs text-gray-500 mt-1">
          Если задан — бэкенд добавит заголовок{" "}
          <code className="text-primary">X-Macro-Signature: sha256=...</code>
        </div>
      </div>

      <div>
        <label className="label">Доп. заголовки (JSON, опционально)</label>
        <textarea
          className="input font-mono text-xs"
          rows={3}
          value={headersRaw}
          onChange={(e) => onChange({ ...config, headers: e.target.value })}
          placeholder={'{"Authorization": "Bearer ..."}'}
        />
        <div className="text-xs text-gray-500 mt-1">
          Объект JSON с дополнительными HTTP-заголовками.
        </div>
      </div>

      <div className="text-xs text-gray-500 bg-gray-50 border border-gray-200 rounded-md p-3">
        <i className="bi bi-info-circle mr-1" />
        Тело запроса:{" "}
        <code className="text-primary">
          {"{ event, automation_id, target_type, target_id, payload }"}
        </code>
      </div>
    </div>
  );
}
