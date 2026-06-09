"use client";

import { useState } from "react";
import type { ImageBlock } from "@/lib/types";

interface Props {
  block: ImageBlock;
  onChange: (block: ImageBlock) => void;
}

export function ImageBlockEditor({ block, onChange }: Props) {
  const [broken, setBroken] = useState(false);

  function handleUrlChange(url: string) {
    setBroken(false);
    onChange({ ...block, url });
  }

  return (
    <div className="space-y-3">
      <div>
        <label className="label">URL изображения</label>
        <input
          className="input"
          value={block.url}
          onChange={(e) => handleUrlChange(e.target.value)}
          placeholder="https://example.com/image.png"
        />
      </div>

      <div>
        <label className="label">Подпись (опц.)</label>
        <input
          className="input"
          value={block.caption ?? ""}
          onChange={(e) => onChange({ ...block, caption: e.target.value })}
          placeholder="Описание изображения"
        />
      </div>

      {block.url && !broken ? (
        /* eslint-disable-next-line @next/next/no-img-element */
        <img
          src={block.url}
          alt={block.caption ?? ""}
          className="w-full rounded-lg max-h-64 object-contain border border-gray-200"
          onError={() => setBroken(true)}
        />
      ) : block.url && broken ? (
        <div className="flex flex-col items-center justify-center w-full h-24 bg-gray-50 rounded-lg border border-gray-200">
          <i className="bi bi-image text-gray-300 text-4xl" />
          <p className="text-xs text-danger mt-1">Не удалось загрузить изображение</p>
        </div>
      ) : null}
    </div>
  );
}
