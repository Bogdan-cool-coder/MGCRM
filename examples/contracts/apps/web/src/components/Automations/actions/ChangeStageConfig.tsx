"use client";

import useSWR from "swr";
import { fetcher } from "@/lib/api";
import type { Pipeline, PipelineStage } from "@/lib/types";

interface Props {
  config: Record<string, unknown>;
  onChange: (next: Record<string, unknown>) => void;
}

/**
 * Конфиг для action_kind=change_stage.
 * TODO(backend): добавить action_kind change_stage в automation_executor.py.
 */
export function ChangeStageConfig({ config, onChange }: Props) {
  const targetPipelineId = typeof config.pipeline_id === "number" ? config.pipeline_id : null;
  const targetStageId = typeof config.stage_id === "number" ? config.stage_id : null;

  const { data: pipelines } = useSWR<Pipeline[]>("/pipelines", fetcher);
  const { data: stages } = useSWR<PipelineStage[]>(
    targetPipelineId ? `/pipelines/${targetPipelineId}/stages` : null,
    fetcher,
  );

  return (
    <div className="space-y-3">
      <div>
        <label className="label">Воронка</label>
        <select
          className="input"
          value={targetPipelineId ?? ""}
          onChange={(e) => {
            const pid = e.target.value ? Number(e.target.value) : null;
            onChange({ ...config, pipeline_id: pid, stage_id: null });
          }}
        >
          <option value="">— выберите воронку —</option>
          {(pipelines ?? []).map((p) => (
            <option key={p.id} value={p.id}>{p.name}</option>
          ))}
        </select>
      </div>

      <div>
        <label className="label">Этап</label>
        <select
          className="input"
          value={targetStageId ?? ""}
          disabled={!targetPipelineId}
          onChange={(e) => {
            const sid = e.target.value ? Number(e.target.value) : null;
            onChange({ ...config, stage_id: sid });
          }}
        >
          <option value="">— выберите этап —</option>
          {(stages ?? []).map((s) => (
            <option key={s.id} value={s.id}>{s.name}</option>
          ))}
        </select>
        {!targetPipelineId && (
          <div className="text-xs text-gray-400 mt-1">Сначала выберите воронку</div>
        )}
      </div>
    </div>
  );
}
