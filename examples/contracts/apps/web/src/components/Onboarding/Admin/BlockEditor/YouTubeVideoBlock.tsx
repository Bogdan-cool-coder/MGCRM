"use client";

import { useState } from "react";
import type { YouTubeVideoBlock } from "@/lib/types";
import { parseYoutubeId } from "@/lib/video-parsers";

interface Props {
  block: YouTubeVideoBlock;
  onChange: (block: YouTubeVideoBlock) => void;
}

export function YouTubeVideoBlockEditor({ block, onChange }: Props) {
  const [rawUrl, setRawUrl] = useState(
    block.youtube_id ? `https://www.youtube.com/watch?v=${block.youtube_id}` : ""
  );
  const [urlError, setUrlError] = useState<string | null>(null);
  const [parsed, setParsed] = useState<boolean>(!!block.youtube_id);

  function handleBlur() {
    if (!rawUrl) {
      setUrlError(null);
      setParsed(false);
      onChange({ ...block, youtube_id: "" });
      return;
    }

    const result = parseYoutubeId(rawUrl);
    if (result) {
      setUrlError(null);
      setParsed(true);
      onChange({ ...block, youtube_id: result.youtubeId });
    } else {
      setUrlError("Неверная ссылка YouTube. Ожидается: youtube.com/watch?v=ID или youtu.be/ID");
      setParsed(false);
    }
  }

  return (
    <div className="space-y-3">
      <div>
        <label className="label">Ссылка YouTube</label>
        <input
          className="input"
          value={rawUrl}
          onChange={(e) => setRawUrl(e.target.value)}
          onBlur={handleBlur}
          placeholder="https://www.youtube.com/watch?v=dQw4w9WgXcQ"
        />
        {urlError && <p className="text-danger text-xs mt-1">{urlError}</p>}
        {parsed && !urlError && (
          <p className="text-success text-xs mt-1 flex items-center gap-1">
            <i className="bi bi-check-lg" />
            Ссылка распознана
          </p>
        )}
      </div>

      {block.youtube_id && parsed && (
        <div className="rounded-lg border border-gray-200 overflow-hidden">
          {/* В редакторе показываем thumbnail, не iframe — оптимизация */}
          <div className="relative">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img
              src={`https://img.youtube.com/vi/${block.youtube_id}/hqdefault.jpg`}
              alt="YouTube превью"
              className="w-full object-cover"
            />
            <div className="absolute inset-0 flex items-center justify-center bg-black/20">
              <i className="bi bi-play-circle-fill text-white text-5xl opacity-90" />
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
