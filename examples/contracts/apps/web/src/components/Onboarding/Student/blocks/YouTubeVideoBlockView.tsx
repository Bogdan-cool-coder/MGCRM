"use client";

import type { YouTubeVideoBlock } from "@/lib/types";

interface Props {
  block: YouTubeVideoBlock;
}

export function YouTubeVideoBlockView({ block }: Props) {
  if (!block.youtube_id) return null;

  // youtube-nocookie для privacy-friendly embed
  const embedUrl = `https://www.youtube-nocookie.com/embed/${block.youtube_id}?rel=0&modestbranding=1`;

  return (
    <div className="aspect-video rounded-lg shadow-md border border-gray-200 overflow-hidden mb-6">
      <iframe
        src={embedUrl}
        className="w-full h-full"
        allow="presentation"
        allowFullScreen
        sandbox="allow-same-origin allow-scripts allow-popups"
        title="YouTube видео"
      />
    </div>
  );
}
