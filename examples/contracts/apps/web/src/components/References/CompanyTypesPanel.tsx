"use client";

import { SimpleEntityCrud } from "@/components/SimpleEntityCrud";
import { FloatingInput, FloatingTextarea } from "@/components/ui/FloatingInput";

interface CompanyTypeRow {
  id: number;
  name: string;
  description: string | null;
  sort_order: number;
  is_active: boolean;
}

type Form = {
  id?: number;
  name: string;
  description: string;
  sort_order: string;
  is_active: boolean;
};

export function CompanyTypesPanel() {
  return (
    <SimpleEntityCrud<CompanyTypeRow, Form>
      endpoint="/admin/company-types"
      title="Тип компании"
      entityLabel="Тип компании"
      panelMode
      panelDescription="Классификация компаний-клиентов: строительная, агентство и т.д."
      defaultForm={() => ({ name: "", description: "", sort_order: "0", is_active: true })}
      formToEdit={(t) => ({
        id: t.id,
        name: t.name,
        description: t.description ?? "",
        sort_order: String(t.sort_order),
        is_active: t.is_active,
      })}
      buildBody={(f) => ({
        name: f.name.trim(),
        description: f.description.trim() || null,
        sort_order: Number(f.sort_order) || 0,
        is_active: f.is_active,
      })}
      formFields={(f, setF) => (
        <>
          <FloatingInput
            label="Название"
            required
            value={f.name}
            onChange={(e) => setF({ ...f, name: e.target.value })}
          />
          <FloatingTextarea
            label="Описание"
            value={f.description}
            rows={3}
            onChange={(e) => setF({ ...f, description: e.target.value })}
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
      emptyIcon="bi-buildings"
      emptyTitle="Нет типов компаний"
      maxHeight="55vh"
    />
  );
}
