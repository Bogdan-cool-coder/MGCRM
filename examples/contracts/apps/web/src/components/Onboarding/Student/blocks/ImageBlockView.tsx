"use client";

import { useState } from "react";
import type { ImageBlock } from "@/lib/types";

interface Props {
  block: ImageBlock;
}

export function ImageBlockView({ block }: Props) {
  const [broken, setBroken] = useState(false);

  if (broken || !block.url) {
    return (
      <div className="flex flex-col items-center justify-center w-full h-32 bg-gray-50 rounded-lg border border-gray-200 mb-6">
        <i className="bi bi-image text-gray-300 text-4xl" />
        {block.caption && <p className="text-xs text-gray-400 mt-1">{block.caption}</p>}
      </div>
    );
  }

  return (
    <figure className="mb-6">
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img
        src={block.url}
        alt={block.caption ?? ""}
        className="w-full rounded-lg max-h-96 object-contain border border-gray-100"
        onError={() => setBroken(true)}
      />
      {block.caption && (
        <figcaption className="text-xs text-gray-500 text-center mt-2 italic">
          {block.caption}
        </figcaption>
      )}
    </figure>
  );
}
