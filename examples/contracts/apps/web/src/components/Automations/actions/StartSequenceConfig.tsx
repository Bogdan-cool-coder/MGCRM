"use client";

import useSWR from "swr";
import Link from "next/link";
import { fetcher } from "@/lib/api";
import type { Sequence } from "@/lib/types";

interface Props {
  config: Record<string, unknown>;
  onChange: (next: Record<string, unknown>) => void;
}

/**
 * Конфиг start_sequence.
 * Выбор активной последовательности из /api/sequences?is_active=true.
 */
export function StartSequenceConfig({ config, onChange }: Props) {
  const sequenceId =
    typeof config.sequence_id === "number"
      ? String(config.sequence_id)
      : typeof config.sequence_id === "string"
      ? config.sequence_id
      : "";

  const { data: sequences, isLoading } = useSWR<Sequence[]>(
    "/sequences?is_active=true",
    fetcher,
  );

  return (
    <div className="space-y-3">
      <div>
        <label className="label">
          Последовательность <span className="text-danger">*</span>
        </label>
        <select
          className="input"
          value={sequenceId}
          onChange={(e) =>
            onChange({ ...config, sequence_id: e.target.value ? Number(e.target.value) : null })
          }
          disabled={isLoading}
        >
          <option value="">
            {isLoading ? "Загружаем…" : sequences && sequences.length === 0
              ? "Нет активных последовательностей"
              : "— выберите последовательность —"}
          </option>
          {(sequences ?? []).map((s) => (
            <option key={s.id} value={String(s.id)}>
              {s.name}
              {s.steps_count !== undefined ? ` (${s.steps_count} шагов)` : ""}
            </option>
          ))}
        </select>
        <div className="text-xs text-gray-500 mt-1">
          Только активные последовательности.{" "}
          <Link href="/admin/sequences" className="text-primary hover:underline" target="_blank">
            Создать на /admin/sequences
          </Link>
        </div>
      </div>
    </div>
  );
}
