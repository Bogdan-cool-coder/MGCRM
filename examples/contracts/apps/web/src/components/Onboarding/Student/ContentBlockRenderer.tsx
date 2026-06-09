"use client";

import type { ContentBlock } from "@/lib/types";
import { MarkdownBlockView } from "./blocks/MarkdownBlockView";
import { ImageBlockView } from "./blocks/ImageBlockView";
import { DriveVideoBlockView } from "./blocks/DriveVideoBlockView";
import { LoomVideoBlockView } from "./blocks/LoomVideoBlockView";
import { YouTubeVideoBlockView } from "./blocks/YouTubeVideoBlockView";
import { CalloutBlockView } from "./blocks/CalloutBlockView";

interface Props {
  blocks: ContentBlock[];
}

export function ContentBlockRenderer({ blocks }: Props) {
  if (blocks.length === 0) {
    return (
      <div className="text-sm text-gray-400 italic py-4">
        Содержимое урока пустое.
      </div>
    );
  }

  return (
    <div>
      {blocks.map((block, idx) => {
        switch (block.kind) {
          case "markdown":
            return <MarkdownBlockView key={idx} block={block} />;
          case "image":
            return <ImageBlockView key={idx} block={block} />;
          case "drive_video":
            return <DriveVideoBlockView key={idx} block={block} />;
          case "loom_video":
            return <LoomVideoBlockView key={idx} block={block} />;
          case "youtube_video":
            return <YouTubeVideoBlockView key={idx} block={block} />;
          case "callout":
            return <CalloutBlockView key={idx} block={block} />;
          default: {
            // Exhaustive check
            const _exhaustive: never = block;
            return null;
            void _exhaustive;
          }
        }
      })}
    </div>
  );
}
