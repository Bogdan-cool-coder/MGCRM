"use client";

import { PageHeader } from "@/components/PageHeader";
import { SimpleEntityCrud, ActiveBadge } from "@/components/SimpleEntityCrud";
import { FloatingInput, FloatingTextarea } from "@/components/ui/FloatingInput";
import type { DataTableColumn } from "@/components/ui/DataTable";
import type { ProductGroup } from "@/lib/types";

type ProductGroupForm = {
  id?: number;
  name: string;
  description: string;
  sort_order: string;
  is_active: boolean;
};

const COLUMNS: DataTableColumn<ProductGroup>[] = [
  {
    key: "sort_order",
    header: "#",
    width: "3rem",
    align: "right",
    skeletonWidth: "40%",
    render: (g) => (
      <span className="tabular-nums text-gray-400 dark:text-gray-500">{g.sort_order}</span>
    ),
  },
  {
    key: "name",
    header: "Название",
    skeletonWidth: "65%",
    render: (g) => (
      <div>
        <span className={`font-medium ${g.is_active ? "text-gray-900 dark:text-gray-100" : "text-gray-400 dark:text-gray-500"}`}>
          {g.name}
        </span>
        {g.description && (
          <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5 truncate max-w-sm">{g.description}</p>
        )}
      </div>
    ),
  },
  {
    key: "is_active",
    header: "Статус",
    width: "7rem",
    align: "center",
    skeletonWidth: "60%",
    render: (g) => <ActiveBadge active={g.is_active} labelOn="Активна" />,
  },
];

export default function ProductGroupsPage() {
  return (
    <div>
      <PageHeader
        title="Группы продуктов"
        description="Категории продуктов для прайса и группировки в карточке продукта."
        actions={null}
      />
      <div className="p-8 max-w-3xl">
        <SimpleEntityCrud<ProductGroup, ProductGroupForm>
          endpoint="/admin/product-groups"
          swrKey="/product-groups"
          title="Группа"
          entityLabel="Группа"
          columns={COLUMNS}
          defaultForm={() => ({ name: "", description: "", sort_order: "0", is_active: true })}
          formToEdit={(g) => ({
            id: g.id,
            name: g.name,
            description: g.description ?? "",
            sort_order: String(g.sort_order),
            is_active: g.is_active,
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
          searchable
          searchFilter={(g, q) => g.name.toLowerCase().includes(q)}
          emptyIcon="bi-collection"
          emptyTitle="Нет групп"
        />
      </div>
    </div>
  );
}
