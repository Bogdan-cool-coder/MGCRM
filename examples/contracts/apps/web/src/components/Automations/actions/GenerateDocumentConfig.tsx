"use client";

import useSWR from "swr";
import { fetcher } from "@/lib/api";
import type { TemplateInfo } from "@/lib/types";

interface Props {
  config: Record<string, unknown>;
  onChange: (next: Record<string, unknown>) => void;
}

/** Конфиг generate_document. template_code — обязательный. */
export function GenerateDocumentConfig({ config, onChange }: Props) {
  const templateCode = typeof config.template_code === "string" ? config.template_code : "";
  const { data: templates } = useSWR<TemplateInfo[]>("/templates", fetcher);

  // Docx-шаблоны (без product_/country_ YAML-конфигов)
  const docxTemplates = (templates ?? []).filter(
    (t) => !t.code.startsWith("product_") && !t.code.startsWith("country_"),
  );

  return (
    <div className="space-y-3">
      <div className="text-xs bg-warning/30 text-gray-800 border border-warning/50 rounded-md p-3">
        <i className="bi bi-exclamation-triangle mr-1" />
        <strong>MVP-заглушка.</strong> Действие записывает note в timeline цели с фактом
        запроса. Реальная генерация (запуск рендера через `render.generate_contract_files`) —
        TBD, в следующей итерации после ТЗ от contract-specialist'а.
      </div>

      <div>
        <label className="label">Шаблон документа</label>
        <select
          className="input"
          value={templateCode}
          onChange={(e) => onChange({ ...config, template_code: e.target.value })}
        >
          <option value="">— выберите шаблон —</option>
          {docxTemplates.map((t) => (
            <option key={t.id} value={t.code}>{t.title} ({t.code})</option>
          ))}
        </select>
        <div className="text-xs text-gray-500 mt-1">
          Код шаблона из раздела «Шаблоны договоров». В MVP сохраняется в note
          типа «Автоматизация запросила документ: ...».
        </div>
      </div>
    </div>
  );
}
