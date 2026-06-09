"use client";

interface Props {
  enabled: boolean;
  lang: string;
  minDuration: number;
  openaiKey: string;
  onChange: (field: string, value: string | number | boolean) => void;
}

const LANGS = [
  { value: "ru", label: "Русский" },
  { value: "en", label: "English" },
  { value: "kk", label: "Казахский" },
];

export function TranscriptionStep({ enabled, lang, minDuration, openaiKey, onChange }: Props) {
  return (
    <div>
      <h3 className="text-base font-semibold text-gray-900 dark:text-gray-100 mb-4">
        Расшифровка звонков (Whisper)
      </h3>
      <div className="space-y-5">
        {/* Toggle */}
        <label className="flex items-center gap-3 cursor-pointer">
          <div className="relative">
            <input
              type="checkbox"
              className="sr-only"
              checked={enabled}
              onChange={(e) => onChange("transcriptionEnabled", e.target.checked)}
            />
            <div className={`w-11 h-6 rounded-full transition-colors ${enabled ? "bg-primary" : "bg-gray-300 dark:bg-gray-600"}`} />
            <div className={`absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform ${enabled ? "translate-x-5" : ""}`} />
          </div>
          <span className="text-sm font-medium text-gray-900 dark:text-gray-100">
            Включить расшифровку
          </span>
        </label>

        {/* Lang */}
        <div className={!enabled ? "opacity-50 pointer-events-none" : ""}>
          <label className="label">Язык распознавания</label>
          <select
            className="input w-48"
            value={lang}
            onChange={(e) => onChange("transcriptionLang", e.target.value)}
          >
            {LANGS.map((l) => (
              <option key={l.value} value={l.value}>{l.label}</option>
            ))}
          </select>
        </div>

        {/* Min duration */}
        <div className={!enabled ? "opacity-50 pointer-events-none" : ""}>
          <label className="label">Минимальная длительность</label>
          <div className="flex items-center gap-2">
            <input
              type="number"
              className="input w-28"
              value={minDuration}
              min={10}
              max={300}
              onChange={(e) => onChange("transcriptionMinDuration", Number(e.target.value))}
            />
            <span className="text-sm text-gray-600 dark:text-gray-400">секунд</span>
          </div>
          <p className="text-xs text-gray-400 dark:text-gray-500 mt-1">
            Звонки короче — не расшифровываются
          </p>
        </div>

        {/* OpenAI key */}
        <div className={!enabled ? "opacity-50 pointer-events-none" : ""}>
          <label className="label">OpenAI API Key</label>
          <input
            type="password"
            className="input"
            placeholder="sk-…"
            value={openaiKey}
            onChange={(e) => onChange("openaiKey", e.target.value)}
          />
          <p className="text-xs text-gray-400 dark:text-gray-500 mt-1">
            Ключ хранится зашифрованным. Стоимость: $0.006 в минуту.
          </p>
        </div>
      </div>
    </div>
  );
}
