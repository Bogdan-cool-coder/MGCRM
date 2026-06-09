"use client";

import { useState } from "react";
import ReactMarkdown from "react-markdown";
import type { MarkdownBlock } from "@/lib/types";

interface Props {
  block: MarkdownBlock;
  onChange: (block: MarkdownBlock) => void;
}

export function MarkdownBlockEditor({ block, onChange }: Props) {
  const [preview, setPreview] = useState(false);

  return (
    <div>
      <div className="flex items-center justify-between mb-2">
        <label className="label mb-0">Markdown-текст</label>
        <button
          type="button"
          className="text-xs text-primary hover:underline flex items-center gap-1"
          onClick={() => setPreview(!preview)}
        >
          <i className={`bi ${preview ? "bi-pencil" : "bi-eye"}`} />
          {preview ? "Редактировать" : "Предпросмотр"}
        </button>
      </div>

      {!preview ? (
        <textarea
          className="input font-mono text-sm leading-relaxed min-h-[160px] resize-y"
          value={block.content}
          onChange={(e) => onChange({ ...block, content: e.target.value })}
          placeholder="## Заголовок&#10;&#10;Текст параграфа...&#10;&#10;- Пункт 1&#10;- Пункт 2"
        />
      ) : (
        <div className="border border-gray-200 rounded-lg p-4 min-h-[160px] bg-gray-50">
          {block.content ? (
            <div className="prose prose-sm max-w-none">
              <ReactMarkdown>{block.content}</ReactMarkdown>
            </div>
          ) : (
            <p className="text-sm text-gray-400 italic">Нет содержимого для предпросмотра</p>
          )}
        </div>
      )}
    </div>
  );
}
