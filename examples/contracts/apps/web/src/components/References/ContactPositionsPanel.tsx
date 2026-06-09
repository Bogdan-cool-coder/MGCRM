"use client";

import { SimpleEntityCrud } from "@/components/SimpleEntityCrud";
import { FloatingInput } from "@/components/ui/FloatingInput";

interface ContactPositionRow {
  id: number;
  name: string;
  sort_order: number;
  is_active: boolean;
}

type Form = { id?: number; name: string; sort_order: string; is_active: boolean };

export function ContactPositionsPanel() {
  return (
    <SimpleEntityCrud<ContactPositionRow, Form>
      endpoint="/admin/contact-positions"
      title="Должность"
      entityLabel="Должность"
      panelMode
      panelDescription="Используется при создании и редактировании контактов."
      defaultForm={() => ({ name: "", sort_order: "0", is_active: true })}
      formToEdit={(p) => ({ id: p.id, name: p.name, sort_order: String(p.sort_order), is_active: p.is_active })}
      buildBody={(f) => ({ name: f.name.trim(), sort_order: Number(f.sort_order) || 0, is_active: f.is_active })}
      formFields={(f, setF) => (
        <>
          <FloatingInput
            label="Название"
            required
            value={f.name}
            onChange={(e) => setF({ ...f, name: e.target.value })}
          />
          <FloatingInput
            label="Сортировка"
            type="number"
            value={f.sort_order}
            onChange={(e) => setF({ ...f, sort_order: e.target.value })}
          />
        </>
      )}
      validateForm={(f) => {
        if (!f.name.trim()) return "Введите название";
        return null;
      }}
      emptyIcon="bi-person-badge"
      emptyTitle="Нет должностей"
      maxHeight="55vh"
    />
  );
}
