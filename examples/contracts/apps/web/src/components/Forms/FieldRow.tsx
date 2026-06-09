"use client";

import { FORM_FIELD_TYPE_OPTIONS, type FormField, type FormFieldType } from "@/lib/types";

interface Props {
  field: FormField;
  index: number;
  total: number;
  onChange: (next: FormField) => void;
  onMove: (delta: -1 | 1) => void;
  onRemove: () => void;
}

/** Одно поле формы: name, label, type, required, options.
 *
 * options редактируем как одну строку (через запятую), парсим обратно в массив строк.
 * DnD отложили — порядок управляется кнопками ↑/↓.
 */
export function FieldRow({ field, index, total, onChange, onMove, onRemove }: Props) {
  const isSelect = field.type === "select";
  const optionsText = (field.options ?? []).join(", ");

  function setType(next: FormFieldType) {
    // При переключении type: если ушли с select — очищаем options
    if (next === "select") {
      onChange({ ...field, type: next, options: field.options ?? [] });
    } else {
      const { options: _unused, ...rest } = field;
      void _unused;
      onChange({ ...rest, type: next });
    }
  }

  function setOptionsText(raw: string) {
    const arr = raw.split(",").map((s) => s.trim()).filter(Boolean);
    onChange({ ...field, options: arr });
  }

  return (
    <div className="border border-gray-200 rounded-md p-3 space-y-2 bg-gray-50">
      <div className="flex items-center justify-between gap-2">
        <div className="text-xs text-gray-500">Поле #{index + 1}</div>
        <div className="flex items-center gap-1">
          <button
            type="button"
            className="text-gray-500 hover:text-primary disabled:opacity-30 p-1"
            disabled={index === 0}
            onClick={() => onMove(-1)}
            title="Выше"
          >
            <i className="bi bi-arrow-up" />
          </button>
          <button
            type="button"
            className="text-gray-500 hover:text-primary disabled:opacity-30 p-1"
            disabled={index === total - 1}
            onClick={() => onMove(1)}
            title="Ниже"
          >
            <i className="bi bi-arrow-down" />
          </button>
          <button
            type="button"
            className="text-danger hover:bg-gray-100 rounded p-1"
            onClick={onRemove}
            title="Удалить поле"
          >
            <i className="bi bi-trash" />
          </button>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-2">
        <div>
          <label className="label">Имя (name) <span className="text-danger">*</span></label>
          <input
            className="input"
            value={field.name}
            onChange={(e) => onChange({ ...field, name: e.target.value })}
            placeholder="например: phone"
          />
        </div>
        <div>
          <label className="label">Подпись (label) <span className="text-danger">*</span></label>
          <input
            className="input"
            value={field.label}
            onChange={(e) => onChange({ ...field, label: e.target.value })}
            placeholder="например: Телефон"
          />
        </div>
      </div>

      <div className="grid grid-cols-2 gap-2 items-end">
        <div>
          <label className="label">Тип</label>
          <select
            className="input"
            value={field.type}
            onChange={(e) => setType(e.target.value as FormFieldType)}
          >
            {FORM_FIELD_TYPE_OPTIONS.map((o) => (
              <option key={o.value} value={o.value}>{o.label}</option>
            ))}
          </select>
        </div>
        <div>
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={field.required}
              onChange={(e) => onChange({ ...field, required: e.target.checked })}
            />
            Обязательное
          </label>
        </div>
      </div>

      {isSelect && (
        <div>
          <label className="label">Варианты (через запятую)</label>
          <input
            className="input"
            value={optionsText}
            onChange={(e) => setOptionsText(e.target.value)}
            placeholder="Опция 1, Опция 2, Опция 3"
          />
          <div className="text-xs text-gray-500 mt-1">
            Каждое значение — отдельный пункт выпадающего списка.
          </div>
        </div>
      )}
    </div>
  );
}
