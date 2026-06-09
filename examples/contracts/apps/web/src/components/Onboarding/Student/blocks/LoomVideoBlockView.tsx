"use client";

import type { LoomVideoBlock } from "@/lib/types";

interface Props {
  block: LoomVideoBlock;
}

export function LoomVideoBlockView({ block }: Props) {
  if (!block.loom_id) return null;

  const embedUrl = `https://www.loom.com/embed/${block.loom_id}?hide_owner=true&hide_share=true`;

  return (
    <div className="aspect-video rounded-lg shadow-md border border-gray-200 overflow-hidden mb-6">
      <iframe
        src={embedUrl}
        className="w-full h-full"
        allowFullScreen
        sandbox="allow-same-origin allow-scripts allow-popups"
        title="Loom видео"
      />
    </div>
  );
}
