"use client";

interface Props {
  config: Record<string, unknown>;
  onChange: (next: Record<string, unknown>) => void;
}

/**
 * Конфиг для action_kind=set_tags.
 * TODO(backend): добавить action_kind set_tags в automation_executor.py (требует модели тегов).
 */
export function SetTagsConfig({ config, onChange }: Props) {
  const tags = Array.isArray(config.tags) ? (config.tags as string[]).join(", ") : "";

  function handleChange(raw: string) {
    const parsed = raw.split(",").map((t) => t.trim()).filter(Boolean);
    onChange({ ...config, tags: parsed });
  }

  return (
    <div className="space-y-3">
      <div>
        <label className="label">Теги (через запятую)</label>
        <input
          className="input"
          placeholder="Теги через запятую: лид, горячий, Q2"
          value={tags}
          onChange={(e) => handleChange(e.target.value)}
        />
        <div className="text-xs text-gray-500 mt-1">
          Теги будут добавлены к объекту. Через запятую — несколько тегов.
        </div>
      </div>
    </div>
  );
}
