"use client";

import type { MarkdownBlock } from "@/lib/types";

// react-markdown is ESM — Next 14 handles it with transpilePackages if needed
// Using dynamic import pattern to avoid SSR issues
import ReactMarkdown from "react-markdown";

interface Props {
  block: MarkdownBlock;
}

export function MarkdownBlockView({ block }: Props) {
  return (
    <div className="prose prose-sm max-w-prose mb-6">
      <ReactMarkdown>{block.content}</ReactMarkdown>
    </div>
  );
}
