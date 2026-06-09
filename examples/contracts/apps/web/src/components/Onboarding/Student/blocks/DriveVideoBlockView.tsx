"use client";

import type { DriveVideoBlock } from "@/lib/types";

interface Props {
  block: DriveVideoBlock;
}

export function DriveVideoBlockView({ block }: Props) {
  if (!block.drive_url) return null;

  return (
    <div className="mb-6">
      {block.title && (
        <p className="text-sm font-medium text-gray-700 mb-2">{block.title}</p>
      )}
      <div className="aspect-video rounded-lg shadow-md border border-gray-200 overflow-hidden">
        <iframe
          src={block.drive_url}
          className="w-full h-full"
          allow="autoplay; encrypted-media"
          allowFullScreen
          sandbox="allow-same-origin allow-scripts allow-popups"
          title={block.title ?? "Видео"}
        />
      </div>
      {block.duration_min != null && (
        <p className="text-xs text-gray-400 mt-1 flex items-center gap-1">
          <i className="bi bi-clock" />
          {block.duration_min} мин
        </p>
      )}
    </div>
  );
}
