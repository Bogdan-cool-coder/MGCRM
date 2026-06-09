"use client";

import { PageHeader } from "@/components/PageHeader";
import { SimpleEntityCrud, ActiveBadge } from "@/components/SimpleEntityCrud";
import { FloatingInput } from "@/components/ui/FloatingInput";
import type { DataTableColumn } from "@/components/ui/DataTable";
import type { Source } from "@/lib/types";

type SourceForm = {
  id?: number;
  code: string;
  name: string;
  sort_order: string;
  is_active: boolean;
};

const COLUMNS: DataTableColumn<Source>[] = [
  {
    key: "sort_order",
    header: "#",
    width: "3rem",
    align: "right",
    skeletonWidth: "40%",
    render: (s) => (
      <span className="tabular-nums text-gray-400 dark:text-gray-500">{s.sort_order}</span>
    ),
  },
  {
    key: "name",
    header: "Название",
    skeletonWidth: "60%",
    render: (s) => (
      <span className={`font-medium ${s.is_active ? "text-gray-900 dark:text-gray-100" : "text-gray-400 dark:text-gray-500"}`}>
        {s.name}
      </span>
    ),
  },
  {
    key: "code",
    header: "Код",
    width: "11rem",
    skeletonWidth: "50%",
    render: (s) => (
      <span className="font-mono text-xs text-gray-500 dark:text-gray-400">{s.code}</span>
    ),
  },
  {
    key: "is_active",
    header: "Статус",
    width: "7rem",
    align: "center",
    skeletonWidth: "60%",
    render: (s) => <ActiveBadge active={s.is_active} />,
  },
];

export default function SourcesPage() {
  return (
    <div>
      <PageHeader
        title="Источники"
        description="Источники привлечения клиентов: холодный звонок, партнёр, интернет и т.д."
        actions={null}
      />
      <div className="p-8 max-w-3xl">
        <SimpleEntityCrud<Source, SourceForm>
          endpoint="/admin/sources"
          swrKey="/sources"
          title="Источник"
          entityLabel="Источник"
          columns={COLUMNS}
          defaultForm={() => ({ code: "", name: "", sort_order: "0", is_active: true })}
          formToEdit={(s) => ({ id: s.id, code: s.code, name: s.name, sort_order: String(s.sort_order), is_active: s.is_active })}
          buildBody={(f) => ({ code: f.code.trim(), name: f.name.trim(), sort_order: Number(f.sort_order) || 0, is_active: f.is_active })}
          formFields={(f, setF) => (
            <>
              <FloatingInput
                label="Код"
                required
                value={f.code}
                onChange={(e) => setF({ ...f, code: e.target.value })}
              />
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
            if (!f.code.trim()) return "Введите код";
            if (!f.name.trim()) return "Введите название";
            return null;
          }}
          searchable
          searchFilter={(s, q) => s.name.toLowerCase().includes(q) || s.code.toLowerCase().includes(q)}
          emptyIcon="bi-funnel"
          emptyTitle="Нет источников"
        />
      </div>
    </div>
  );
}
