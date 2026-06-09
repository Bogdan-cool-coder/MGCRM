"use client";

import { useState, useRef } from "react";
import { DndContext, closestCenter, type DragEndEvent } from "@dnd-kit/core";
import { SortableContext, verticalListSortingStrategy, arrayMove } from "@dnd-kit/sortable";
import { SortableItem } from "@/components/SortableItem";
import type { ContentBlock, ContentBlockKind } from "@/lib/types";
import { MarkdownBlockEditor } from "./BlockEditor/MarkdownBlock";
import { ImageBlockEditor } from "./BlockEditor/ImageBlock";
import { DriveVideoBlockEditor } from "./BlockEditor/DriveVideoBlock";
import { LoomVideoBlockEditor } from "./BlockEditor/LoomVideoBlock";
import { YouTubeVideoBlockEditor } from "./BlockEditor/YouTubeVideoBlock";
import { CalloutBlockEditor } from "./BlockEditor/CalloutBlock";
import { EmptyState } from "@/components/EmptyState";

interface Props {
  blocks: ContentBlock[];
  onChange: (blocks: ContentBlock[]) => void;
}

const KIND_LABELS: Record<ContentBlockKind, { label: string; icon: string }> = {
  markdown:      { label: "Текст (Markdown)", icon: "bi-text-left" },
  image:         { label: "Изображение",      icon: "bi-image" },
  drive_video:   { label: "Google Drive",     icon: "bi-google" },
  loom_video:    { label: "Loom",             icon: "bi-camera-video" },
  youtube_video: { label: "YouTube",          icon: "bi-youtube" },
  callout:       { label: "Выделенный блок",  icon: "bi-exclamation-triangle" },
};

const KIND_BADGE: Record<ContentBlockKind, string> = {
  markdown:      "bg-info/10 text-info",
  image:         "bg-gray-100 text-gray-600",
  drive_video:   "bg-primary/10 text-primary",
  loom_video:    "bg-primary/10 text-primary",
  youtube_video: "bg-danger/10 text-danger",
  callout:       "bg-warning/10 text-warning",
};

function makeDefault(kind: ContentBlockKind): ContentBlock {
  switch (kind) {
    case "markdown":      return { kind: "markdown", content: "" };
    case "image":         return { kind: "image", url: "", caption: "" };
    case "drive_video":   return { kind: "drive_video", drive_url: "", title: "" };
    case "loom_video":    return { kind: "loom_video", loom_id: "" };
    case "youtube_video": return { kind: "youtube_video", youtube_id: "" };
    case "callout":       return { kind: "callout", variant: "info", text: "" };
  }
}

export function ContentBlocksBuilder({ blocks, onChange }: Props) {
  const [dropdownOpen, setDropdownOpen] = useState(false);
  const dropdownRef = useRef<HTMLDivElement>(null);

  function addBlock(kind: ContentBlockKind) {
    onChange([...blocks, makeDefault(kind)]);
    setDropdownOpen(false);
  }

  function updateBlock(idx: number, block: ContentBlock) {
    const next = [...blocks];
    next[idx] = block;
    onChange(next);
  }

  function removeBlock(idx: number) {
    onChange(blocks.filter((_, i) => i !== idx));
  }

  function handleDragEnd(event: DragEndEvent) {
    const { active, over } = event;
    if (!over || active.id === over.id) return;
    const oldIdx = blocks.findIndex((_, i) => i === active.id);
    const newIdx = blocks.findIndex((_, i) => i === over.id);
    if (oldIdx < 0 || newIdx < 0) return;
    onChange(arrayMove(blocks, oldIdx, newIdx));
  }

  function renderBlockEditor(block: ContentBlock, idx: number) {
    switch (block.kind) {
      case "markdown":
        return <MarkdownBlockEditor block={block} onChange={(b) => updateBlock(idx, b)} />;
      case "image":
        return <ImageBlockEditor block={block} onChange={(b) => updateBlock(idx, b)} />;
      case "drive_video":
        return <DriveVideoBlockEditor block={block} onChange={(b) => updateBlock(idx, b)} />;
      case "loom_video":
        return <LoomVideoBlockEditor block={block} onChange={(b) => updateBlock(idx, b)} />;
      case "youtube_video":
        return <YouTubeVideoBlockEditor block={block} onChange={(b) => updateBlock(idx, b)} />;
      case "callout":
        return <CalloutBlockEditor block={block} onChange={(b) => updateBlock(idx, b)} />;
      default: {
        const _exhaustive: never = block;
        return null;
        void _exhaustive;
      }
    }
  }

  return (
    <div>
      {blocks.length === 0 && (
        <EmptyState
          icon="bi-layout-text-window"
          title="Урок пустой"
          description="Добавь первый блок: текст, видео или изображение"
        />
      )}

      <DndContext collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
        <SortableContext items={blocks.map((_, i) => i)} strategy={verticalListSortingStrategy}>
          <div className="space-y-3">
            {blocks.map((block, idx) => (
              <SortableItem key={idx} id={idx}>
                <div className="card p-0 overflow-hidden flex-1">
                  {/* Block header */}
                  <div className="flex items-center justify-between px-3 py-2 bg-gray-50 border-b border-gray-100">
                    <span className={`badge text-xs font-medium px-2 py-0.5 rounded-full ${KIND_BADGE[block.kind]}`}>
                      <i className={`bi ${KIND_LABELS[block.kind].icon} mr-1`} />
                      {KIND_LABELS[block.kind].label}
                    </span>
                    <div className="flex items-center gap-1">
                      <button
                        type="button"
                        className="btn-ghost text-xs px-1.5 py-0.5 text-danger hover:bg-danger/10"
                        onClick={() => removeBlock(idx)}
                        title="Удалить блок"
                      >
                        <i className="bi bi-trash" />
                      </button>
                    </div>
                  </div>

                  {/* Block body */}
                  <div className="p-3">
                    {renderBlockEditor(block, idx)}
                  </div>
                </div>
              </SortableItem>
            ))}
          </div>
        </SortableContext>
      </DndContext>

      {/* Add block dropdown */}
      <div className="relative mt-3" ref={dropdownRef}>
        <button
          type="button"
          className="btn-secondary text-sm flex items-center gap-1"
          onClick={() => setDropdownOpen(!dropdownOpen)}
        >
          <i className="bi bi-plus-lg" />
          Добавить блок
          <i className="bi bi-caret-down-fill text-xs ml-1" />
        </button>

        {dropdownOpen && (
          <div className="absolute left-0 top-full mt-1 bg-white rounded-lg shadow-lg border border-gray-200 py-1 z-20 min-w-[200px]">
            {(Object.entries(KIND_LABELS) as [ContentBlockKind, { label: string; icon: string }][]).map(
              ([kind, meta]) => (
                <button
                  key={kind}
                  type="button"
                  className="w-full flex items-center gap-2 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 transition-colors"
                  onClick={() => addBlock(kind)}
                >
                  <i className={`bi ${meta.icon} text-gray-400`} />
                  {meta.label}
                </button>
              )
            )}
          </div>
        )}
      </div>
    </div>
  );
}
