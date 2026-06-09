"use client";

import { useState } from "react";
import type { LoomVideoBlock } from "@/lib/types";
import { parseLoomId } from "@/lib/video-parsers";

interface Props {
  block: LoomVideoBlock;
  onChange: (block: LoomVideoBlock) => void;
}

export function LoomVideoBlockEditor({ block, onChange }: Props) {
  const [rawUrl, setRawUrl] = useState(
    block.loom_id ? `https://www.loom.com/share/${block.loom_id}` : ""
  );
  const [urlError, setUrlError] = useState<string | null>(null);
  const [parsed, setParsed] = useState<boolean>(!!block.loom_id);

  function handleBlur() {
    if (!rawUrl) {
      setUrlError(null);
      setParsed(false);
      onChange({ ...block, loom_id: "" });
      return;
    }

    const result = parseLoomId(rawUrl);
    if (result) {
      setUrlError(null);
      setParsed(true);
      onChange({ ...block, loom_id: result.loomId });
    } else {
      setUrlError("Неверная ссылка Loom. Ожидается: loom.com/share/{ID}");
      setParsed(false);
    }
  }

  return (
    <div className="space-y-3">
      <div>
        <label className="label">Ссылка Loom</label>
        <input
          className="input"
          value={rawUrl}
          onChange={(e) => setRawUrl(e.target.value)}
          onBlur={handleBlur}
          placeholder="https://www.loom.com/share/abc123"
        />
        {urlError && <p className="text-danger text-xs mt-1">{urlError}</p>}
        {parsed && !urlError && (
          <p className="text-success text-xs mt-1 flex items-center gap-1">
            <i className="bi bi-check-lg" />
            Ссылка распознана (ID: {block.loom_id})
          </p>
        )}
      </div>

      {block.loom_id && parsed && (
        <div className="aspect-video rounded-lg border border-gray-200 overflow-hidden">
          <iframe
            src={`https://www.loom.com/embed/${block.loom_id}?hide_owner=true&hide_share=true`}
            className="w-full h-full"
            allowFullScreen
            title="Loom preview"
          />
        </div>
      )}
    </div>
  );
}
