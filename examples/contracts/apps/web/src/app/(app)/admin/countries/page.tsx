"use client";

import { PageHeader } from "@/components/PageHeader";
import { SimpleEntityCrud, ActiveBadge } from "@/components/SimpleEntityCrud";
import { FloatingInput } from "@/components/ui/FloatingInput";
import { flagEmoji } from "@/lib/flag";
import type { DataTableColumn } from "@/components/ui/DataTable";
import type { Country } from "@/lib/types";

type CountryForm = {
  id?: number;
  code: string;
  name: string;
  name_en: string;
  phone_prefix: string;
  sort_order: string;
  is_active: boolean;
};

const COLUMNS: DataTableColumn<Country>[] = [
  {
    key: "flag",
    header: "",
    width: "2.5rem",
    align: "center",
    skeletonWidth: "1.5rem",
    render: (c) => <span className="text-lg leading-none">{flagEmoji(c.code)}</span>,
  },
  {
    key: "name",
    header: "Название",
    skeletonWidth: "60%",
    render: (c) => (
      <div className={c.is_active ? "" : "opacity-50"}>
        <span className="font-medium text-gray-900 dark:text-gray-100">{c.name}</span>
        <span className="ml-2 text-xs font-mono uppercase text-gray-400">{c.code}</span>
        {c.name_en && (
          <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{c.name_en}</p>
        )}
      </div>
    ),
  },
  {
    key: "phone_prefix",
    header: "Тел. префикс",
    width: "9rem",
    align: "center",
    skeletonWidth: "50%",
    render: (c) => (
      <span className="font-mono text-sm text-gray-600 dark:text-gray-400">{c.phone_prefix ?? "—"}</span>
    ),
  },
  {
    key: "sort_order",
    header: "#",
    width: "3rem",
    align: "right",
    skeletonWidth: "40%",
    render: (c) => (
      <span className="tabular-nums text-gray-400 dark:text-gray-500">{c.sort_order}</span>
    ),
  },
  {
    key: "is_active",
    header: "Статус",
    width: "7rem",
    align: "center",
    skeletonWidth: "60%",
    render: (c) => <ActiveBadge active={c.is_active} labelOn="Активна" />,
  },
];

export default function CountriesPage() {
  return (
    <div>
      <PageHeader
        title="Страны"
        description="Справочник стран для адресов компаний и сделок."
        actions={null}
      />
      <div className="p-8 max-w-3xl">
        <SimpleEntityCrud<Country, CountryForm>
          endpoint="/admin/countries"
          swrKey="/countries"
          title="Страна"
          entityLabel="Страна"
          columns={COLUMNS}
          defaultForm={() => ({ code: "", name: "", name_en: "", phone_prefix: "", sort_order: "0", is_active: true })}
          formToEdit={(c) => ({
            id: c.id,
            code: c.code,
            name: c.name,
            name_en: c.name_en ?? "",
            phone_prefix: c.phone_prefix ?? "",
            sort_order: String(c.sort_order),
            is_active: c.is_active,
          })}
          buildBody={(f) => ({
            code: f.code.trim().toLowerCase(),
            name: f.name.trim(),
            name_en: f.name_en.trim() || null,
            phone_prefix: f.phone_prefix.trim() || null,
            sort_order: Number(f.sort_order) || 0,
            is_active: f.is_active,
          })}
          formFields={(f, setF) => (
            <>
              <div className="grid grid-cols-3 gap-3">
                <FloatingInput
                  label="ISO-код"
                  required
                  value={f.code}
                  onChange={(e) => setF({ ...f, code: e.target.value })}
                />
                <div className="col-span-2">
                  <FloatingInput
                    label="Название (RU)"
                    required
                    value={f.name}
                    onChange={(e) => setF({ ...f, name: e.target.value })}
                  />
                </div>
              </div>
              <FloatingInput
                label="Название (EN)"
                value={f.name_en}
                onChange={(e) => setF({ ...f, name_en: e.target.value })}
              />
              <div className="grid grid-cols-2 gap-3">
                <FloatingInput
                  label="Тел. префикс"
                  value={f.phone_prefix}
                  onChange={(e) => setF({ ...f, phone_prefix: e.target.value })}
                />
                <FloatingInput
                  label="Сортировка"
                  type="number"
                  value={f.sort_order}
                  onChange={(e) => setF({ ...f, sort_order: e.target.value })}
                />
              </div>
            </>
          )}
          validateForm={(f) => {
            if (!f.code.trim()) return "Введите ISO-код (2 буквы)";
            if (!f.name.trim()) return "Введите название";
            return null;
          }}
          searchable
          searchFilter={(c, q) =>
            c.name.toLowerCase().includes(q) ||
            c.code.toLowerCase().includes(q) ||
            ((c.name_en?.toLowerCase().includes(q)) ?? false)
          }
          emptyIcon="bi-globe2"
          emptyTitle="Нет стран"
        />
      </div>
    </div>
  );
}
