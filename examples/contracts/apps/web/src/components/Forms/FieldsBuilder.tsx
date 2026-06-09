"use client";

import { FieldRow } from "@/components/Forms/FieldRow";
import type { FormField } from "@/lib/types";

interface Props {
  fields: FormField[];
  onChange: (next: FormField[]) => void;
}

const DEFAULT_NEW_FIELD: FormField = {
  name: "",
  label: "",
  type: "text",
  required: false,
};

/** Конструктор полей формы: список + добавить + перемещение/удаление. */
export function FieldsBuilder({ fields, onChange }: Props) {
  function add() {
    onChange([...fields, { ...DEFAULT_NEW_FIELD }]);
  }

  function update(index: number, next: FormField) {
    const arr = fields.slice();
    arr[index] = next;
    onChange(arr);
  }

  function move(index: number, delta: -1 | 1) {
    const target = index + delta;
    if (target < 0 || target >= fields.length) return;
    const arr = fields.slice();
    [arr[index], arr[target]] = [arr[target], arr[index]];
    onChange(arr);
  }

  function remove(index: number) {
    const arr = fields.slice();
    arr.splice(index, 1);
    onChange(arr);
  }

  return (
    <div className="space-y-2">
      {fields.length === 0 && (
        <div className="text-sm text-gray-500 italic">
          Полей пока нет. Добавьте первое поле формы.
        </div>
      )}
      {fields.map((f, idx) => (
        <FieldRow
          key={idx}
          field={f}
          index={idx}
          total={fields.length}
          onChange={(next) => update(idx, next)}
          onMove={(delta) => move(idx, delta)}
          onRemove={() => remove(idx)}
        />
      ))}
      <button type="button" className="btn-secondary text-sm" onClick={add}>
        <i className="bi bi-plus-lg" /> Поле
      </button>
    </div>
  );
}
