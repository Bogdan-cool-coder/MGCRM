"use client";

import useSWR from "swr";
import { fetcher } from "@/lib/api";
import type { User } from "@/lib/types";

/** Единый выбор пользователя. Контролируется строкой ("" = не выбран), как нативный select.
 * users можно передать (избежать лишнего запроса) либо компонент сам подтянет /users. */
export function UserSelect({
  value,
  onChange,
  placeholder = "—",
  className = "input",
  users,
}: {
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
  className?: string;
  users?: User[];
}) {
  const { data } = useSWR<User[]>(users ? null : "/users", fetcher);
  const list = users ?? data ?? [];
  return (
    <select className={className} value={value} onChange={(e) => onChange(e.target.value)}>
      <option value="">{placeholder}</option>
      {list.map((u) => (
        <option key={u.id} value={String(u.id)}>{u.full_name}</option>
      ))}
    </select>
  );
}
