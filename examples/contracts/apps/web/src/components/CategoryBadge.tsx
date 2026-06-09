"use client";

import useSWR from "swr";
import { fetcher } from "@/lib/api";
import type { ClientCategory } from "@/lib/types";

/**
 * CategoryBadge — бейдж категории клиента.
 * Использует badge базовый класс v2 + кастомный цвет категории из справочника.
 * Цвет задаётся через inline style (категория берёт цвет с бэкенда).
 */
export function CategoryBadge({ code }: { code?: string | null }) {
  const { data: cats } = useSWR<ClientCategory[]>("/client-categories", fetcher);
  if (!code) return null;
  const cat = cats?.find((c) => c.code === code);
  const color = cat?.color || "#6B7A99";
  return (
    <span
      className="badge text-white"
      style={{ backgroundColor: color }}
      title={cat?.name || `Категория ${code}`}
    >
      {code}
    </span>
  );
}
