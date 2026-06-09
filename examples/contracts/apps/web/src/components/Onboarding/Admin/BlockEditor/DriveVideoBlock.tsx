"use client";

import { useState } from "react";
import type { DriveVideoBlock } from "@/lib/types";
import { parseDriveUrl } from "@/lib/video-parsers";

interface Props {
  block: DriveVideoBlock;
  onChange: (block: DriveVideoBlock) => void;
}

export function DriveVideoBlockEditor({ block, onChange }: Props) {
  const [rawUrl, setRawUrl] = useState(block.drive_url ?? "");
  const [urlError, setUrlError] = useState<string | null>(null);
  const [parsed, setParsed] = useState<boolean>(!!block.drive_url);

  function handleBlur() {
    if (!rawUrl) {
      setUrlError(null);
      setParsed(false);
      onChange({ ...block, drive_url: "" });
      return;
    }

    const result = parseDriveUrl(rawUrl);
    if (result) {
      setUrlError(null);
      setParsed(true);
      onChange({ ...block, drive_url: result.embedUrl });
    } else {
      setUrlError("Неверная ссылка Google Drive. Ожидается: drive.google.com/file/d/{ID}/view");
      setParsed(false);
    }
  }

  return (
    <div className="space-y-3">
      <div>
        <label className="label">Название (опц.)</label>
        <input
          className="input"
          value={block.title ?? ""}
          onChange={(e) => onChange({ ...block, title: e.target.value })}
          placeholder="Название видео"
        />
      </div>

      <div>
        <label className="label">Ссылка Google Drive</label>
        <input
          className="input"
          value={rawUrl}
          onChange={(e) => setRawUrl(e.target.value)}
          onBlur={handleBlur}
          placeholder="https://drive.google.com/file/d/ABC123/view"
        />
        {urlError && <p className="text-danger text-xs mt-1">{urlError}</p>}
        {parsed && !urlError && (
          <p className="text-success text-xs mt-1 flex items-center gap-1">
            <i className="bi bi-check-lg" />
            Ссылка распознана
          </p>
        )}
      </div>

      <div>
        <label className="label">Длительность (мин, опц.)</label>
        <input
          className="input w-32"
          type="number"
          min={0}
          value={block.duration_min ?? ""}
          onChange={(e) => onChange({ ...block, duration_min: e.target.value ? Number(e.target.value) : undefined })}
          placeholder="10"
        />
      </div>

      {block.drive_url && parsed && (
        <div className="aspect-video rounded-lg border border-gray-200 overflow-hidden">
          <iframe
            src={block.drive_url}
            className="w-full h-full"
            allow="autoplay; encrypted-media"
            allowFullScreen
            title="Google Drive preview"
          />
        </div>
      )}
    </div>
  );
}
